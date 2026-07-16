<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\RecoveryCodeProtector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            ...$this->activeFactorState(),
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
        $remaining = User::findOrFail($user->id)->getAttribute('two_factor_recovery_codes');
        $this->assertIsArray($remaining);
        $this->assertCount(1, $remaining);
        $this->assertNotSame('FEDCBA-654321', $remaining[0]);
        $this->assertTrue(app(RecoveryCodeProtector::class)->matches(
            $remaining[0],
            'FEDCBA-654321',
        ));
    }

    public function test_new_recovery_codes_are_keyed_digests_and_remain_single_use(): void
    {
        $service = app(TwoFactorService::class);
        $plaintext = 'ABCDEF-123456';
        $protected = $service->protectRecoveryCodes([$plaintext]);
        $user = User::create([
            'username' => 'protected-recovery-user',
            'password' => 'legacy-password',
            'is_admin' => true,
            'status' => User::STATUS_NORMAL,
            ...$this->activeFactorState(),
            'two_factor_recovery_codes' => $protected,
        ]);

        $this->assertCount(1, $protected);
        $this->assertMatchesRegularExpression('/\Av1:[a-f0-9]{64}\z/', $protected[0]);
        $this->assertStringNotContainsString(
            $plaintext,
            (string) DB::table('users')->where('id', $user->id)->value('two_factor_recovery_codes'),
        );
        $this->assertTrue($service->verifyRecoveryCode($user, strtolower($plaintext)));
        $this->assertFalse($service->verifyRecoveryCode($user, $plaintext));
    }

    public function test_recovery_digest_verification_accepts_configured_previous_keys(): void
    {
        $protector = app(RecoveryCodeProtector::class);
        $currentKey = (string) config('app.key');
        $previousKey = 'base64:'.base64_encode(str_repeat('p', 32));

        config(['app.key' => $previousKey, 'app.previous_keys' => []]);
        $protected = $protector->protect('ABCDEF-123456');
        config(['app.key' => $currentKey, 'app.previous_keys' => [$previousKey]]);

        $this->assertTrue($protector->matches($protected, 'ABCDEF-123456'));
        $this->assertFalse($protector->matches($protected, 'FEDCBA-654321'));
    }

    public function test_malformed_recovery_storage_fails_closed(): void
    {
        $user = User::create([
            'username' => 'malformed-recovery-user',
            'password' => 'legacy-password',
            'is_admin' => true,
            'status' => User::STATUS_NORMAL,
            ...$this->activeFactorState(),
            'two_factor_recovery_codes' => ['v1:not-a-digest', 'not-a-code', 123],
        ]);

        $this->assertFalse(app(TwoFactorService::class)->verifyRecoveryCode(
            $user,
            'ABCDEF-123456',
        ));
    }

    public function test_migration_hashes_legacy_codes_idempotently_and_invalidates_them_on_rollback(): void
    {
        $migration = require database_path(
            'migrations/2026_07_14_100006_hash_two_factor_recovery_codes.php'
        );
        $migration->down();

        $legacy = User::create([
            'username' => 'legacy-recovery-user',
            'password' => 'legacy-password',
            'status' => User::STATUS_NORMAL,
            ...$this->activeFactorState(),
            'two_factor_recovery_codes' => ['ABCDEF-123456', 'invalid'],
        ]);
        $alreadyProtected = User::create([
            'username' => 'protected-migration-user',
            'password' => 'legacy-password',
            'status' => User::STATUS_NORMAL,
            ...$this->activeFactorState(),
            'two_factor_recovery_codes' => app(RecoveryCodeProtector::class)
                ->protectMany(['FEDCBA-654321']),
        ]);

        $migration->up();

        $legacyCodes = User::findOrFail($legacy->id)->getAttribute('two_factor_recovery_codes');
        $this->assertIsArray($legacyCodes);
        $this->assertCount(1, $legacyCodes);
        $this->assertTrue(app(RecoveryCodeProtector::class)->matches(
            $legacyCodes[0],
            'ABCDEF-123456',
        ));
        $firstPass = DB::table('users')
            ->where('id', $legacy->id)
            ->value('two_factor_recovery_codes');

        $migration->up();
        $this->assertSame(
            $firstPass,
            DB::table('users')->where('id', $legacy->id)->value('two_factor_recovery_codes'),
        );

        $migration->down();
        $this->assertNull(DB::table('users')
            ->where('id', $legacy->id)
            ->value('two_factor_recovery_codes'));
        $this->assertNull(DB::table('users')
            ->where('id', $alreadyProtected->id)
            ->value('two_factor_recovery_codes'));

        // Restore the expected migration state for the test harness.
        $migration->up();
    }

    /** @return array<string, mixed> */
    private function activeFactorState(): array
    {
        return [
            'login_verify' => User::LOGIN_VERIFY_TOTP,
            'two_factor_enabled' => true,
            'two_factor_secret' => app(TwoFactorService::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ];
    }
}
