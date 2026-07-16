<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecoveryCodeCallerStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_later_caller_save_cannot_resurrect_codes_consumed_by_another_request(): void
    {
        $user = User::create([
            'username' => 'recovery-caller-state',
            'password' => 'legacy-password',
            'is_admin' => true,
            'status' => User::STATUS_NORMAL,
            'login_verify' => User::LOGIN_VERIFY_TOTP,
            'two_factor_enabled' => true,
            'two_factor_secret' => app(TwoFactorService::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => [
                'ABCDEF-123456',
                'FEDCBA-654321',
            ],
        ]);
        $firstRequest = User::findOrFail($user->id);
        $secondRequest = User::findOrFail($user->id);
        $service = app(TwoFactorService::class);

        $this->assertTrue($service->verifyRecoveryCode($firstRequest, 'ABCDEF-123456'));
        $this->assertTrue($service->verifyRecoveryCode($secondRequest, 'FEDCBA-654321'));

        $firstRequest->forceFill(['last_login_at' => now()])->save();

        $persisted = User::findOrFail($user->id);
        $this->assertSame([], $persisted->getAttribute('two_factor_recovery_codes'));
        $this->assertNotNull($persisted->last_login_at);
    }
}
