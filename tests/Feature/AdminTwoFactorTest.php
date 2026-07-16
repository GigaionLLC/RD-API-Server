<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\RecentAdminAuthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
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

    private function setupSecret(): string
    {
        $stored = session('2fa.setup_secret');
        $this->assertIsString($stored);

        $payload = json_decode(Crypt::decryptString($stored), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertSame(1, $payload['version'] ?? null);
        $this->assertIsString($payload['secret'] ?? null);
        $secret = $payload['secret'];
        $this->assertStringNotContainsString($secret, $stored);

        return $secret;
    }

    private function recentlyAuthenticatedAs(User $user, bool $factorVerified = false): void
    {
        $this->actingAs($user);
        session()->put('auth.credential_version', max(1, (int) $user->credential_version));
        $recent = app(RecentAdminAuthentication::class);
        if ($factorVerified) {
            $recent->noteFactorVerification(session()->driver(), $user);
        }
        $recent->mark(session()->driver(), $user);
        $this->retainCurrentSessionCookie();
    }

    private function retainCurrentSessionCookie(): void
    {
        $this->withCookie((string) config('session.cookie'), session()->getId());
    }

    private function assertPendingChallengeCleared(): void
    {
        $this->assertFalse(session()->has('2fa.user'));
        $this->assertFalse(session()->has('2fa.remember'));
        $this->assertFalse(session()->has('2fa.password_fingerprint'));
        $this->assertFalse(session()->has('2fa.expires_at'));
    }

    public function test_every_two_factor_route_serializes_requests_from_the_same_session(): void
    {
        $routeNames = [
            'admin.2fa.challenge',
            'admin.2fa.challenge.verify',
            'admin.2fa.show',
            'admin.2fa.enable',
            'admin.2fa.confirm',
            'admin.2fa.cancel',
            'admin.2fa.reauthenticate',
            'admin.2fa.disable',
        ];

        foreach ($routeNames as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route, "Missing route [{$routeName}].");
            $this->assertSame(30, $route->locksFor(), "Route [{$routeName}] must hold a session lock.");
            $this->assertSame(10, $route->waitsFor(), "Route [{$routeName}] must wait for its session lock.");
        }
    }

    public function test_admin_can_enroll_in_two_factor(): void
    {
        $admin = $this->admin();
        $this->recentlyAuthenticatedAs($admin);

        // Start enrollment — a candidate secret is stashed in the session.
        $this->post(route('admin.2fa.enable'))->assertRedirect(route('admin.2fa.show'));
        $secret = $this->setupSecret();

        // Confirm with a live code → 2FA becomes enabled and recovery codes are issued once.
        $response = $this->post(route('admin.2fa.confirm'), ['code' => $this->code($secret)])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSessionMissing('2fa.recovery_codes')
            ->assertSessionMissing(RecentAdminAuthentication::SESSION_KEY);

        preg_match_all('/\b[A-F0-9]{6}-[A-F0-9]{6}\b/', $response->getContent(), $matches);
        $displayedCodes = array_values(array_unique($matches[0]));
        $this->assertCount(8, $displayedCodes);

        $admin->refresh();
        $this->assertTrue((bool) $admin->two_factor_enabled);
        $this->assertSame($secret, $admin->two_factor_secret);
        $this->assertSame(User::LOGIN_VERIFY_TOTP, $admin->login_verify);
        $this->assertCount(8, (array) $admin->two_factor_recovery_codes);
        $this->assertArrayNotHasKey('two_factor_secret', $admin->toArray());
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $admin->toArray());

        $rawSecret = (string) DB::table('users')
            ->where('id', $admin->id)
            ->value('two_factor_secret');
        $this->assertNotSame($secret, $rawSecret);
        $this->assertStringNotContainsString($secret, $rawSecret);
        $this->assertSame($secret, Crypt::decryptString($rawSecret));

        $rawCodes = (string) DB::table('users')
            ->where('id', $admin->id)
            ->value('two_factor_recovery_codes');
        foreach ($displayedCodes as $code) {
            $this->assertStringNotContainsString($code, $rawCodes);
        }
    }

    public function test_confirm_rejects_a_bad_code(): void
    {
        $admin = $this->admin();
        $this->recentlyAuthenticatedAs($admin);
        $this->post(route('admin.2fa.enable'));

        $this->post(route('admin.2fa.confirm'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertFalse((bool) $admin->fresh()->two_factor_enabled);
    }

    public function test_confirm_throttles_bad_codes_and_blocks_a_later_valid_code_without_mutation(): void
    {
        $admin = $this->admin();
        $this->recentlyAuthenticatedAs($admin);
        $this->post(route('admin.2fa.enable'));
        $secret = $this->setupSecret();
        $throttleKey = '2fa-management:confirm:'.$admin->id.'|127.0.0.1';
        RateLimiter::clear($throttleKey);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('admin.2fa.confirm'), ['code' => 'definitely-invalid'])
                ->assertSessionHasErrors('code');
        }

        $this->assertTrue(RateLimiter::tooManyAttempts($throttleKey, 5));
        $this->post(route('admin.2fa.confirm'), ['code' => $this->code($secret)])
            ->assertSessionHasErrors('code');
        $this->assertFalse((bool) $admin->fresh()->two_factor_enabled);
        $this->assertTrue(session()->has('2fa.setup_secret'));

        RateLimiter::clear($throttleKey);
    }

    public function test_setup_page_rejects_an_unbound_legacy_plaintext_session_secret(): void
    {
        $admin = $this->admin();
        $secret = app(TwoFactorService::class)->generateSecret();
        $this->recentlyAuthenticatedAs($admin);

        $this->withSession(['2fa.setup_secret' => $secret])
            ->get(route('admin.2fa.show'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertDontSee($secret)
            ->assertSessionMissing('2fa.setup_secret');

        $this->post(route('admin.2fa.confirm'), ['code' => $this->code($secret)])
            ->assertRedirect(route('admin.2fa.show'));

        $this->assertFalse((bool) $admin->fresh()->two_factor_enabled);
    }

    public function test_setup_page_rejects_and_clears_a_corrupt_session_secret(): void
    {
        $admin = $this->admin();
        $this->recentlyAuthenticatedAs($admin);

        $this->withSession(['2fa.setup_secret' => 'not-valid-ciphertext!'])
            ->get(route('admin.2fa.show'))
            ->assertOk()
            ->assertDontSee('Setup key')
            ->assertSessionMissing('2fa.setup_secret');

        $this->post(route('admin.2fa.confirm'), ['code' => '123456'])
            ->assertRedirect(route('admin.2fa.show'));
        $this->assertFalse((bool) $admin->fresh()->two_factor_enabled);
    }

    public function test_management_controls_require_a_recent_completed_sign_in(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('admin.2fa.show'))
            ->assertOk()
            ->assertSee('Sign in again to make changes')
            ->assertDontSee('Set up authenticator');

        $this->post(route('admin.2fa.enable'))
            ->assertRedirect(route('admin.2fa.show'))
            ->assertSessionMissing('2fa.setup_secret');

        $this->withSession(['2fa.setup_secret' => 'stale-encrypted-state'])
            ->post(route('admin.2fa.confirm'))
            ->assertRedirect(route('admin.2fa.show'))
            ->assertSessionMissing('2fa.setup_secret');
        $this->assertFalse((bool) $admin->fresh()->two_factor_enabled);
    }

    public function test_recent_sign_in_is_valid_at_the_exact_boundary_and_expires_one_second_later(): void
    {
        $this->admin();
        $this->post('/admin/login', ['username' => 'admin', 'password' => 'secret12345'])
            ->assertRedirect(route('admin.dashboard'));
        $this->retainCurrentSessionCookie();

        $this->travel(5)->minutes();
        $this->get(route('admin.2fa.show'))
            ->assertOk()
            ->assertSee('Set up authenticator');

        $this->travel(1)->second();
        $this->get(route('admin.2fa.show'))
            ->assertOk()
            ->assertSee('Sign in again to make changes')
            ->assertSessionMissing(RecentAdminAuthentication::SESSION_KEY);
    }

    public function test_management_freshness_configuration_is_clamped_to_a_secure_range(): void
    {
        $recent = app(RecentAdminAuthentication::class);

        config(['auth.two_factor_management_timeout' => 0]);
        $this->assertSame(60, $recent->timeoutSeconds());

        config(['auth.two_factor_management_timeout' => 3600]);
        $this->assertSame(900, $recent->timeoutSeconds());
    }

    public function test_malformed_and_future_dated_recent_sign_in_markers_fail_closed(): void
    {
        $admin = $this->admin();
        $this->recentlyAuthenticatedAs($admin);
        $recent = app(RecentAdminAuthentication::class);

        $stored = session(RecentAdminAuthentication::SESSION_KEY);
        $this->assertIsString($stored);
        $payload = json_decode(Crypt::decryptString($stored), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $payload['issued_at'] = now()->addMinute()->getTimestamp();
        $payload['expires_at'] = $payload['issued_at'] + $recent->timeoutSeconds();
        session()->put(
            RecentAdminAuthentication::SESSION_KEY,
            Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
        );

        $this->assertFalse($recent->isValid(session()->driver(), $admin));
        $this->assertFalse(session()->has(RecentAdminAuthentication::SESSION_KEY));

        session()->put(RecentAdminAuthentication::SESSION_KEY, 'not-valid-ciphertext');
        $this->assertFalse($recent->isValid(session()->driver(), $admin));
        $this->assertFalse(session()->has(RecentAdminAuthentication::SESSION_KEY));
    }

    public function test_recent_sign_in_marker_is_bound_to_password_and_credential_version(): void
    {
        $admin = $this->admin();
        $this->recentlyAuthenticatedAs($admin);
        $recent = app(RecentAdminAuthentication::class);
        $this->assertTrue($recent->isValid(session()->driver(), $admin));

        $admin->forceFill(['password' => 'replacement-password'])->save();
        $this->assertFalse($recent->isValid(session()->driver(), $admin->fresh()));

        $this->recentlyAuthenticatedAs($admin->fresh());
        $admin->forceFill(['credential_version' => 2])->save();
        $this->assertFalse($recent->isValid(session()->driver(), $admin->fresh()));
    }

    public function test_recent_sign_in_is_local_to_one_browser_session(): void
    {
        $admin = $this->admin();
        $this->recentlyAuthenticatedAs($admin);
        $this->assertTrue(session()->has(RecentAdminAuthentication::SESSION_KEY));

        $this->flushSession();
        $this->actingAs($admin->fresh())
            ->get(route('admin.2fa.show'))
            ->assertOk()
            ->assertSee('Sign in again to make changes')
            ->assertDontSee('Set up authenticator');
    }

    public function test_copied_recent_sign_in_marker_is_rejected_after_the_session_id_changes(): void
    {
        $admin = $this->admin();
        $this->recentlyAuthenticatedAs($admin);
        $recent = app(RecentAdminAuthentication::class);
        $session = session()->driver();
        $copiedMarker = $session->get(RecentAdminAuthentication::SESSION_KEY);
        $originalSessionId = $session->getId();
        $this->assertIsString($copiedMarker);

        $session->migrate(true);
        $this->assertNotSame($originalSessionId, $session->getId());
        $this->retainCurrentSessionCookie();
        $session->put(RecentAdminAuthentication::SESSION_KEY, $copiedMarker);

        $this->assertFalse($recent->isValid($session, $admin));
        $this->assertFalse($session->has(RecentAdminAuthentication::SESSION_KEY));
    }

    public function test_setup_state_cannot_cross_accounts(): void
    {
        $first = $this->admin(['username' => 'first-admin']);
        $this->recentlyAuthenticatedAs($first);
        $this->post(route('admin.2fa.enable'));
        $storedSetup = session('2fa.setup_secret');
        $this->assertIsString($storedSetup);

        $this->post(route('admin.logout'))->assertRedirect(route('admin.login'));
        $this->retainCurrentSessionCookie();
        $second = $this->admin(['username' => 'second-admin']);
        $this->recentlyAuthenticatedAs($second);

        session()->put('2fa.setup_secret', $storedSetup);
        $this->get(route('admin.2fa.show'))
            ->assertOk()
            ->assertDontSee('Setup key')
            ->assertSessionMissing('2fa.setup_secret');
    }

    public function test_expired_recent_sign_in_cannot_finish_enrollment(): void
    {
        $admin = $this->admin();
        $this->recentlyAuthenticatedAs($admin);
        $this->post(route('admin.2fa.enable'));
        $secret = $this->setupSecret();

        $this->travel(5)->minutes();
        $this->travel(1)->second();

        $this->post(route('admin.2fa.confirm'), ['code' => $this->code($secret)])
            ->assertRedirect(route('admin.2fa.show'))
            ->assertSessionMissing('2fa.setup_secret')
            ->assertSessionMissing(RecentAdminAuthentication::SESSION_KEY);
        $this->assertFalse((bool) $admin->fresh()->two_factor_enabled);
    }

    public function test_stale_setup_cannot_overwrite_a_factor_enabled_concurrently(): void
    {
        $admin = $this->admin();
        $this->recentlyAuthenticatedAs($admin);
        $this->post(route('admin.2fa.enable'));
        $candidate = $this->setupSecret();
        $current = app(TwoFactorService::class)->generateSecret();

        $admin->forceFill([
            'two_factor_enabled' => true,
            'two_factor_secret' => $current,
            'login_verify' => User::LOGIN_VERIFY_TOTP,
        ])->save();

        $this->post(route('admin.2fa.confirm'), ['code' => $this->code($candidate)])
            ->assertRedirect(route('admin.2fa.show'))
            ->assertSessionMissing('2fa.setup_secret');
        $this->assertSame($current, $admin->fresh()->two_factor_secret);
    }

    public function test_setup_can_be_cancelled_without_a_freshness_bypass(): void
    {
        $admin = $this->admin();
        $this->recentlyAuthenticatedAs($admin);
        $this->post(route('admin.2fa.enable'))
            ->assertSessionHas('2fa.setup_secret');

        $this->delete(route('admin.2fa.cancel'))
            ->assertRedirect(route('admin.2fa.show'))
            ->assertSessionMissing('2fa.setup_secret');
        $this->assertTrue(session()->has(RecentAdminAuthentication::SESSION_KEY));
    }

    public function test_reauthentication_action_clears_setup_and_returns_after_the_next_login(): void
    {
        $admin = $this->admin();
        $this->recentlyAuthenticatedAs($admin);
        $this->post(route('admin.2fa.enable'));

        $this->post(route('admin.2fa.reauthenticate'))
            ->assertRedirect(route('admin.login'))
            ->assertSessionMissing('2fa.setup_secret')
            ->assertSessionMissing(RecentAdminAuthentication::SESSION_KEY)
            ->assertSessionHas('url.intended', route('admin.2fa.show'));
        $this->assertGuest();
        $this->retainCurrentSessionCookie();

        $this->post('/admin/login', ['username' => 'admin', 'password' => 'secret12345'])
            ->assertRedirect(route('admin.2fa.show'));
        $this->assertAuthenticatedAs($admin);
        $this->assertTrue(session()->has(RecentAdminAuthentication::SESSION_KEY));
    }

    public function test_totp_login_assurance_allows_code_free_disable(): void
    {
        $secret = app(TwoFactorService::class)->generateSecret();
        $admin = $this->admin([
            'two_factor_enabled' => true,
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'login_verify' => User::LOGIN_VERIFY_TOTP,
        ]);

        $this->post('/admin/login', ['username' => 'admin', 'password' => 'secret12345'])
            ->assertRedirect(route('admin.2fa.challenge'));
        $this->retainCurrentSessionCookie();
        $this->post(route('admin.2fa.challenge.verify'), ['code' => $this->code($secret)])
            ->assertRedirect(route('admin.dashboard'));
        $this->retainCurrentSessionCookie();

        $this->get(route('admin.2fa.show'))
            ->assertOk()
            ->assertSee('You already verified this authenticator')
            ->assertDontSee('id="disable-code"', false);
        $this->delete(route('admin.2fa.disable'))
            ->assertRedirect(route('admin.2fa.show'));

        $this->assertFalse((bool) $admin->fresh()->two_factor_enabled);
    }

    public function test_last_recovery_code_login_assurance_allows_code_free_disable(): void
    {
        $twoFactor = app(TwoFactorService::class);
        $secret = $twoFactor->generateSecret();
        $recoveryCode = $twoFactor->generateRecoveryCodes(1)[0];
        $admin = $this->admin([
            'two_factor_enabled' => true,
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $twoFactor->protectRecoveryCodes([$recoveryCode]),
            'login_verify' => User::LOGIN_VERIFY_TOTP,
        ]);

        $this->post('/admin/login', ['username' => 'admin', 'password' => 'secret12345'])
            ->assertRedirect(route('admin.2fa.challenge'));
        $this->retainCurrentSessionCookie();
        $this->post(route('admin.2fa.challenge.verify'), ['code' => $recoveryCode])
            ->assertRedirect(route('admin.dashboard'));
        $this->retainCurrentSessionCookie();
        $this->assertSame([], (array) $admin->fresh()->two_factor_recovery_codes);

        $this->get(route('admin.2fa.show'))
            ->assertOk()
            ->assertSee('You already verified this authenticator')
            ->assertDontSee('id="disable-code"', false);
        $this->delete(route('admin.2fa.disable'))
            ->assertRedirect(route('admin.2fa.show'));

        $this->assertFalse((bool) $admin->fresh()->two_factor_enabled);
    }

    public function test_valid_totp_disables_two_factor_and_consumes_recent_authentication(): void
    {
        $secret = app(TwoFactorService::class)->generateSecret();
        $admin = $this->admin([
            'two_factor_enabled' => true,
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => app(TwoFactorService::class)
                ->protectRecoveryCodes(app(TwoFactorService::class)->generateRecoveryCodes()),
            'login_verify' => User::LOGIN_VERIFY_TOTP,
        ]);
        $this->recentlyAuthenticatedAs($admin);
        session()->put('2fa.setup_secret', Crypt::encryptString('sensitive-pending-state'));

        $this->delete(route('admin.2fa.disable'), ['code' => $this->code($secret)])
            ->assertRedirect(route('admin.2fa.show'))
            ->assertSessionMissing('2fa.setup_secret')
            ->assertSessionMissing(RecentAdminAuthentication::SESSION_KEY);

        $admin->refresh();
        $this->assertFalse((bool) $admin->two_factor_enabled);
        $this->assertNull($admin->two_factor_secret);
        $this->assertNull($admin->two_factor_confirmed_at);
        $this->assertNull($admin->two_factor_recovery_codes);
        $this->assertSame(User::LOGIN_VERIFY_OFF, $admin->login_verify);
    }

    public function test_unused_recovery_code_can_disable_two_factor(): void
    {
        $twoFactor = app(TwoFactorService::class);
        $secret = $twoFactor->generateSecret();
        $recovery = $twoFactor->generateRecoveryCodes();
        $admin = $this->admin([
            'two_factor_enabled' => true,
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $twoFactor->protectRecoveryCodes($recovery),
            'login_verify' => User::LOGIN_VERIFY_TOTP,
        ]);
        $this->recentlyAuthenticatedAs($admin);

        $this->delete(route('admin.2fa.disable'), ['code' => $recovery[0]])
            ->assertRedirect(route('admin.2fa.show'));
        $this->assertFalse((bool) $admin->fresh()->two_factor_enabled);
    }

    public function test_disable_rejects_and_throttles_bad_codes_without_mutation(): void
    {
        $twoFactor = app(TwoFactorService::class);
        $secret = $twoFactor->generateSecret();
        $recovery = $twoFactor->generateRecoveryCodes();
        $admin = $this->admin([
            'two_factor_enabled' => true,
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $twoFactor->protectRecoveryCodes($recovery),
            'login_verify' => User::LOGIN_VERIFY_TOTP,
        ]);
        $this->recentlyAuthenticatedAs($admin);
        $throttleKey = '2fa-management:disable:'.$admin->id.'|127.0.0.1';
        RateLimiter::clear($throttleKey);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->delete(route('admin.2fa.disable'), ['code' => 'definitely-invalid'])
                ->assertSessionHasErrors('code');
        }

        $this->assertTrue(RateLimiter::tooManyAttempts($throttleKey, 5));
        $this->delete(route('admin.2fa.disable'), ['code' => $this->code($secret)])
            ->assertSessionHasErrors('code');

        $admin->refresh();
        $this->assertTrue((bool) $admin->two_factor_enabled);
        $this->assertSame($secret, $admin->two_factor_secret);
        $this->assertSame(User::LOGIN_VERIFY_TOTP, $admin->login_verify);
        $this->assertCount(count($recovery), (array) $admin->two_factor_recovery_codes);
    }

    public function test_stale_session_cannot_disable_two_factor_even_with_a_valid_code(): void
    {
        $secret = app(TwoFactorService::class)->generateSecret();
        $admin = $this->admin([
            'two_factor_enabled' => true,
            'two_factor_secret' => $secret,
            'login_verify' => User::LOGIN_VERIFY_TOTP,
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.2fa.disable'), ['code' => $this->code($secret)])
            ->assertRedirect(route('admin.2fa.show'));

        $this->assertTrue((bool) $admin->fresh()->two_factor_enabled);
        $this->assertSame($secret, $admin->fresh()->two_factor_secret);
    }

    public function test_expired_factor_assurance_cannot_disable_two_factor_without_a_code(): void
    {
        $secret = app(TwoFactorService::class)->generateSecret();
        $admin = $this->admin([
            'two_factor_enabled' => true,
            'two_factor_secret' => $secret,
            'login_verify' => User::LOGIN_VERIFY_TOTP,
        ]);
        $this->recentlyAuthenticatedAs($admin, factorVerified: true);
        $this->assertTrue(app(RecentAdminAuthentication::class)
            ->factorWasVerified(session()->driver(), $admin));

        $this->travel(5)->minutes();
        $this->travel(1)->second();
        $this->delete(route('admin.2fa.disable'))
            ->assertRedirect(route('admin.2fa.show'));

        $this->assertTrue((bool) $admin->fresh()->two_factor_enabled);
        $this->assertSame($secret, $admin->fresh()->two_factor_secret);
    }

    public function test_assurance_for_a_replaced_factor_cannot_disable_the_new_factor_without_a_code(): void
    {
        $originalSecret = app(TwoFactorService::class)->generateSecret();
        $replacementSecret = app(TwoFactorService::class)->generateSecret();
        $admin = $this->admin([
            'two_factor_enabled' => true,
            'two_factor_secret' => $originalSecret,
            'two_factor_confirmed_at' => now(),
            'login_verify' => User::LOGIN_VERIFY_TOTP,
        ]);
        $this->recentlyAuthenticatedAs($admin, factorVerified: true);
        $this->assertTrue(app(RecentAdminAuthentication::class)
            ->factorWasVerified(session()->driver(), $admin));

        $admin->forceFill([
            'two_factor_secret' => $replacementSecret,
            'two_factor_confirmed_at' => now()->addSecond(),
        ])->save();

        $this->delete(route('admin.2fa.disable'))
            ->assertSessionHasErrors('code');

        $admin->refresh();
        $this->assertTrue((bool) $admin->two_factor_enabled);
        $this->assertSame($replacementSecret, $admin->two_factor_secret);
        $this->assertSame(User::LOGIN_VERIFY_TOTP, $admin->login_verify);
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
        $this->assertFalse(session()->has(RecentAdminAuthentication::SESSION_KEY));

        // Supplying the code completes the login.
        $this->post(route('admin.2fa.challenge.verify'), ['code' => $this->code($secret)])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticated();
        $this->assertPendingChallengeCleared();
        $this->assertTrue(session()->has(RecentAdminAuthentication::SESSION_KEY));
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

    public function test_credential_version_change_invalidates_a_pending_challenge(): void
    {
        $secret = app(TwoFactorService::class)->generateSecret();
        $admin = $this->admin([
            'two_factor_enabled' => true,
            'two_factor_secret' => $secret,
            'login_verify' => User::LOGIN_VERIFY_TOTP,
        ]);

        $this->post('/admin/login', ['username' => 'admin', 'password' => 'secret12345'])
            ->assertRedirect(route('admin.2fa.challenge'));

        $admin->forceFill(['credential_version' => 2])->save();

        $this->post(route('admin.2fa.challenge.verify'), ['code' => $this->code($secret)])
            ->assertRedirect(route('admin.login'));
        $this->assertGuest();
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
        $this->assertFalse(session()->has(RecentAdminAuthentication::SESSION_KEY));
    }

    public function test_admin_without_two_factor_signs_in_directly(): void
    {
        $this->admin();

        $this->post('/admin/login', ['username' => 'admin', 'password' => 'secret12345'])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticated();
        $this->assertTrue(session()->has(RecentAdminAuthentication::SESSION_KEY));
    }

    public function test_settings_and_challenge_pages_render_in_every_state(): void
    {
        $admin = $this->admin();
        $this->recentlyAuthenticatedAs($admin);

        // Disabled state.
        $this->get(route('admin.2fa.show'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSee('Set up authenticator');

        // Setup state shows the manual key.
        $this->post(route('admin.2fa.enable'));
        $this->get(route('admin.2fa.show'))->assertOk()->assertSee('Setup key');

        // Enabled state offers the disable form.
        $secret = $this->setupSecret();
        $this->post(route('admin.2fa.confirm'), ['code' => $this->code($secret)]);
        $this->post(route('admin.logout'))->assertRedirect(route('admin.login'));
        $this->retainCurrentSessionCookie();
        $this->post('/admin/login', ['username' => 'admin', 'password' => 'secret12345'])
            ->assertRedirect(route('admin.2fa.challenge'));
        $this->retainCurrentSessionCookie();
        $this->post(route('admin.2fa.challenge.verify'), ['code' => $this->code($secret)])
            ->assertRedirect(route('admin.dashboard'));
        $this->retainCurrentSessionCookie();
        $this->get(route('admin.2fa.show'))->assertOk()->assertSee('Disable two-factor');

        // The standalone challenge page renders when a login is pending.
        $this->post(route('admin.logout'));
        $other = $this->admin([
            'username' => 'pending', 'two_factor_enabled' => true,
            'two_factor_secret' => $secret, 'login_verify' => User::LOGIN_VERIFY_TOTP,
        ]);
        $this->post('/admin/login', ['username' => 'pending', 'password' => 'secret12345']);
        $this->get(route('admin.2fa.challenge'))->assertOk()->assertSee('Two-factor');
    }
}
