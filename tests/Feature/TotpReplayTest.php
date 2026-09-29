<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A TOTP code is accepted once: replaying the same (or an older) code inside its ±1 step window is
 * refused on the client one-request path and in the console challenge.
 */
class TotpReplayTest extends TestCase
{
    use RefreshDatabase;

    private function totpUser(array $overrides = []): array
    {
        $secret = app(TwoFactorService::class)->generateSecret();
        $user = User::create(array_merge([
            'username' => 'totp', 'password' => 'secret12345', 'status' => User::STATUS_NORMAL,
            'two_factor_enabled' => true, 'two_factor_secret' => $secret, 'login_verify' => User::LOGIN_VERIFY_TOTP,
        ], $overrides));

        return [$user, $secret];
    }

    public function test_service_accepts_a_code_once(): void
    {
        [$user, $secret] = $this->totpUser();
        $service = app(TwoFactorService::class);
        $code = $service->currentCode($secret);

        $this->assertTrue($service->verifyTotp($user, $code));
        $this->assertFalse($service->verifyTotp($user->fresh(), $code));
        $this->assertNotNull($user->fresh()->two_factor_last_counter);
    }

    public function test_client_one_request_login_cannot_replay_a_code(): void
    {
        [, $secret] = $this->totpUser();
        $code = app(TwoFactorService::class)->currentCode($secret);
        $payload = ['username' => 'totp', 'password' => 'secret12345', 'tfaCode' => $code, 'id' => 'd', 'uuid' => 'u'];

        $this->postJson('/api/login', $payload)->assertOk()->assertJsonStructure(['access_token']);
        $this->postJson('/api/login', $payload)->assertOk()->assertExactJson(['error' => 'Wrong 2FA code']);
    }

    public function test_console_challenge_cannot_replay_a_code(): void
    {
        [, $secret] = $this->totpUser(['username' => 'admin', 'is_admin' => true]);
        $code = app(TwoFactorService::class)->currentCode($secret);

        $this->post('/admin/login', ['username' => 'admin', 'password' => 'secret12345'])
            ->assertRedirect(route('admin.2fa.challenge'));
        $this->post(route('admin.2fa.challenge.verify'), ['code' => $code])->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticated();

        $this->post('/admin/logout');
        $this->assertGuest();

        $this->post('/admin/login', ['username' => 'admin', 'password' => 'secret12345'])
            ->assertRedirect(route('admin.2fa.challenge'));
        $this->post(route('admin.2fa.challenge.verify'), ['code' => $code])->assertRedirect(route('admin.login'));
        $this->assertGuest();
    }
}
