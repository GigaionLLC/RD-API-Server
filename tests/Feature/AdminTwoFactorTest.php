<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TOTP two-factor for the admin console: enrollment (enable → confirm), the post-password
 * login challenge, and that accounts without 2FA still sign straight in.
 */
class AdminTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $overrides = []): User
    {
        return User::create(array_merge([
            'username' => 'admin', 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ], $overrides));
    }

    private function code(string $secret): string
    {
        return app(TwoFactorService::class)->currentCode($secret);
    }

    private function assertPendingChallengeCleared(): void
    {
        $this->assertFalse(session()->has('2fa.user'));
        $this->assertFalse(session()->has('2fa.remember'));
        $this->assertFalse(session()->has('2fa.password_fingerprint'));
        $this->assertFalse(session()->has('2fa.expires_at'));
    }

    public function test_admin_can_enroll_in_two_factor(): void
    {
        $admin = $this->admin();

        // Start enrollment — a candidate secret is stashed in the session.
        $this->actingAs($admin)->post(route('admin.2fa.enable'))->assertRedirect(route('admin.2fa.show'));
        $secret = session('2fa.setup_secret');
        $this->assertIsString($secret);

        // Confirm with a live code → 2FA becomes enabled and recovery codes are issued once.
        $this->actingAs($admin)
            ->post(route('admin.2fa.confirm'), ['code' => $this->code($secret)])
            ->assertRedirect(route('admin.2fa.show'))
            ->assertSessionHas('2fa.recovery_codes');

        $admin->refresh();
        $this->assertTrue((bool) $admin->two_factor_enabled);
        $this->assertSame($secret, $admin->two_factor_secret);
        $this->assertSame(User::LOGIN_VERIFY_TOTP, $admin->login_verify);
        $this->assertCount(8, (array) $admin->two_factor_recovery_codes);
    }

    public function test_confirm_rejects_a_bad_code(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.2fa.enable'));

        $this->actingAs($admin)
            ->post(route('admin.2fa.confirm'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertFalse((bool) $admin->fresh()->two_factor_enabled);
    }

    public function test_login_with_two_factor_defers_until_the_code_is_supplied(): void
    {
        $secret = app(TwoFactorService::class)->generateSecret();
        $this->admin([
            'two_factor_enabled' => true,
            'two_factor_secret' => $secret,
            'login_verify' => User::LOGIN_VERIFY_TOTP,
        ]);

        // Correct password → redirected to the challenge, but NOT yet authenticated.
        $this->post('/admin/login', ['username' => 'admin', 'password' => 'secret12345'])
            ->assertRedirect(route('admin.2fa.challenge'));
        $this->assertGuest();
        $this->assertTrue(session()->has('2fa.password_fingerprint'));
        $this->assertTrue(session()->has('2fa.expires_at'));

        // Supplying the code completes the login.
        $this->post(route('admin.2fa.challenge.verify'), ['code' => $this->code($secret)])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticated();
        $this->assertPendingChallengeCleared();
    }

    public function test_password_change_invalidates_a_pending_challenge(): void
    {
        $secret = app(TwoFactorService::class)->generateSecret();
        $admin = $this->admin([
            'two_factor_enabled' => true,
            'two_factor_secret' => $secret,
            'login_verify' => User::LOGIN_VERIFY_TOTP,
        ]);

        $this->post('/admin/login', ['username' => 'admin', 'password' => 'secret12345'])
            ->assertRedirect(route('admin.2fa.challenge'));

        $admin->forceFill(['password' => 'replacement-password'])->save();

        $this->post(route('admin.2fa.challenge.verify'), ['code' => $this->code($secret)])
            ->assertRedirect(route('admin.login'));
        $this->assertGuest();
        $this->assertPendingChallengeCleared();

        $this->post('/admin/login', ['username' => 'admin', 'password' => 'replacement-password'])
            ->assertRedirect(route('admin.2fa.challenge'));
        $this->post(route('admin.2fa.challenge.verify'), ['code' => $this->code($secret)])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin->fresh());
        $this->assertPendingChallengeCleared();
    }

    public function test_expired_pending_challenge_is_rejected(): void
    {
        $secret = app(TwoFactorService::class)->generateSecret();
        $this->admin([
            'two_factor_enabled' => true,
            'two_factor_secret' => $secret,
            'login_verify' => User::LOGIN_VERIFY_TOTP,
        ]);

        $this->post('/admin/login', ['username' => 'admin', 'password' => 'secret12345'])
            ->assertRedirect(route('admin.2fa.challenge'));
        $this->travel(6)->minutes();

        $this->post(route('admin.2fa.challenge.verify'), ['code' => $this->code($secret)])
            ->assertRedirect(route('admin.login'));
        $this->assertGuest();
        $this->assertPendingChallengeCleared();
    }

    public function test_disabled_account_cannot_finish_a_pending_challenge(): void
    {
        $secret = app(TwoFactorService::class)->generateSecret();
        $admin = $this->admin([
            'two_factor_enabled' => true,
            'two_factor_secret' => $secret,
            'login_verify' => User::LOGIN_VERIFY_TOTP,
        ]);

        $this->post('/admin/login', ['username' => 'admin', 'password' => 'secret12345'])
            ->assertRedirect(route('admin.2fa.challenge'));
        $admin->forceFill(['status' => User::STATUS_DISABLED])->save();

        $this->post(route('admin.2fa.challenge.verify'), ['code' => $this->code($secret)])
            ->assertRedirect(route('admin.login'));
        $this->assertGuest();
        $this->assertPendingChallengeCleared();
    }

    public function test_account_without_console_authority_cannot_finish_a_pending_challenge(): void
    {
        $secret = app(TwoFactorService::class)->generateSecret();
        $admin = $this->admin([
            'two_factor_enabled' => true,
            'two_factor_secret' => $secret,
            'login_verify' => User::LOGIN_VERIFY_TOTP,
        ]);

        $this->post('/admin/login', ['username' => 'admin', 'password' => 'secret12345'])
            ->assertRedirect(route('admin.2fa.challenge'));
        $admin->forceFill(['is_admin' => false])->save();

        $this->post(route('admin.2fa.challenge.verify'), ['code' => $this->code($secret)])
            ->assertRedirect(route('admin.login'));
        $this->assertGuest();
        $this->assertPendingChallengeCleared();
    }

    public function test_challenge_rejects_a_bad_code_and_stays_guest(): void
    {
        $secret = app(TwoFactorService::class)->generateSecret();
        $this->admin([
            'two_factor_enabled' => true,
            'two_factor_secret' => $secret,
            'login_verify' => User::LOGIN_VERIFY_TOTP,
        ]);

        $this->post('/admin/login', ['username' => 'admin', 'password' => 'secret12345']);

        $this->post(route('admin.2fa.challenge.verify'), ['code' => '111111'])
            ->assertRedirect(route('admin.login'))
            ->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->assertPendingChallengeCleared();
    }

    public function test_admin_without_two_factor_signs_in_directly(): void
    {
        $this->admin();

        $this->post('/admin/login', ['username' => 'admin', 'password' => 'secret12345'])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticated();
    }

    public function test_settings_and_challenge_pages_render_in_every_state(): void
    {
        $admin = $this->admin();

        // Disabled state.
        $this->actingAs($admin)->get(route('admin.2fa.show'))->assertOk()->assertSee('Enable two-factor');

        // Setup state shows the manual key.
        $this->actingAs($admin)->post(route('admin.2fa.enable'));
        $this->actingAs($admin)->get(route('admin.2fa.show'))->assertOk()->assertSee('Setup key');

        // Enabled state offers the disable form.
        $secret = session('2fa.setup_secret');
        $this->actingAs($admin)->post(route('admin.2fa.confirm'), ['code' => $this->code($secret)]);
        $this->actingAs($admin->fresh())->get(route('admin.2fa.show'))->assertOk()->assertSee('Disable');

        // The standalone challenge page renders when a login is pending.
        $other = $this->admin([
            'username' => 'pending', 'two_factor_enabled' => true,
            'two_factor_secret' => $secret, 'login_verify' => User::LOGIN_VERIFY_TOTP,
        ]);
        $this->post('/admin/login', ['username' => 'pending', 'password' => 'secret12345']);
        $this->get(route('admin.2fa.challenge'))->assertOk()->assertSee('Two-factor');
    }
}
