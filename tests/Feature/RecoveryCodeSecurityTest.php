<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecoveryCodeSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_requests_cannot_consume_the_same_recovery_code_twice(): void
    {
        $user = User::create([
            'username' => 'recovery-race-user',
            'password' => 'legacy-password',
            'is_admin' => true,
            'status' => User::STATUS_NORMAL,
            'two_factor_recovery_codes' => [
                'ABCDEF-123456',
                'FEDCBA-654321',
            ],
        ]);
        $firstRequest = User::findOrFail($user->id);
        $staleRequest = User::findOrFail($user->id);
        $service = app(TwoFactorService::class);

        $this->assertTrue($service->verifyRecoveryCode($firstRequest, 'abcdef-123456'));
        $this->assertFalse($service->verifyRecoveryCode($staleRequest, 'ABCDEF-123456'));
        $this->assertSame(
            ['FEDCBA-654321'],
            User::findOrFail($user->id)->two_factor_recovery_codes,
        );
    }
}
