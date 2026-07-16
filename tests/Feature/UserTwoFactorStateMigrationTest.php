<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Database\QueryException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserTwoFactorStateMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_migration_normalizes_historical_states_by_strongest_intent(): void
    {
        $migration = $this->migration();
        $migration->down();
        $secret = app(TwoFactorService::class)->generateSecret();
        $ciphertext = Crypt::encryptString($secret);
        $now = now();

        try {
            $this->insertRaw('policy-activates', [
                'login_verify' => User::LOGIN_VERIFY_TOTP,
                'two_factor_enabled' => false,
                'two_factor_secret' => $ciphertext,
                'two_factor_confirmed_at' => $now,
                'two_factor_recovery_codes' => json_encode(['keep-policy-metadata']),
            ]);
            $this->insertRaw('flag-activates', [
                'login_verify' => User::LOGIN_VERIFY_EMAIL,
                'two_factor_enabled' => true,
                'two_factor_secret' => $ciphertext,
                'two_factor_confirmed_at' => $now,
                'two_factor_recovery_codes' => json_encode(['keep-flag-metadata']),
            ]);
            $this->insertRaw('email-without-secret', [
                'login_verify' => User::LOGIN_VERIFY_EMAIL,
                'two_factor_enabled' => true,
                'two_factor_secret' => null,
                'two_factor_confirmed_at' => $now,
                'two_factor_recovery_codes' => json_encode(['clear-email-metadata']),
            ]);
            $this->insertRaw('totp-without-secret', [
                'login_verify' => User::LOGIN_VERIFY_TOTP,
                'two_factor_enabled' => true,
                'two_factor_secret' => null,
                'two_factor_confirmed_at' => $now,
                'two_factor_recovery_codes' => json_encode(['clear-totp-metadata']),
            ]);
            $this->insertRaw('orphaned-secret', [
                'login_verify' => User::LOGIN_VERIFY_OFF,
                'two_factor_enabled' => false,
                'two_factor_secret' => $ciphertext,
                'two_factor_confirmed_at' => $now,
                'two_factor_recovery_codes' => json_encode(['clear-orphan-metadata']),
            ]);
            $this->insertRaw('unknown-policy', [
                'login_verify' => 'legacy-unknown',
                'two_factor_enabled' => false,
                'two_factor_secret' => null,
                'two_factor_confirmed_at' => $now,
                'two_factor_recovery_codes' => json_encode(['clear-unknown-metadata']),
            ]);
            $this->insertRaw('case-variant-policy', [
                'login_verify' => 'TOTP',
                'two_factor_enabled' => false,
                'two_factor_secret' => $ciphertext,
                'two_factor_confirmed_at' => $now,
                'two_factor_recovery_codes' => json_encode(['clear-case-variant-metadata']),
            ]);

            $migration->up();

            foreach (['policy-activates', 'flag-activates'] as $username) {
                $row = $this->rawUser($username);
                $this->assertSame(User::LOGIN_VERIFY_TOTP, $row->login_verify);
                $this->assertSame(1, (int) $row->two_factor_enabled);
                $this->assertSame($ciphertext, $row->two_factor_secret);
                $this->assertNotNull($row->two_factor_confirmed_at);
                $this->assertNotNull($row->two_factor_recovery_codes);
            }

            $email = $this->rawUser('email-without-secret');
            $this->assertSame(User::LOGIN_VERIFY_EMAIL, $email->login_verify);
            $this->assertInactiveState($email);

            foreach (
                ['totp-without-secret', 'orphaned-secret', 'unknown-policy', 'case-variant-policy'] as $username
            ) {
                $row = $this->rawUser($username);
                $this->assertSame(User::LOGIN_VERIFY_OFF, $row->login_verify);
                $this->assertInactiveState($row);
            }

            $this->assertTrue($this->constraintExists());
        } finally {
            DB::table('users')->whereIn('username', [
                'policy-activates',
                'flag-activates',
                'email-without-secret',
                'totp-without-secret',
                'orphaned-secret',
                'unknown-policy',
                'case-variant-policy',
            ])->delete();

            $migration->up();
        }
    }

    public function test_migration_preflight_rejects_corrupt_ciphertext_before_repairing_any_row(): void
    {
        $migration = $this->migration();
        $migration->down();
        $confirmedAt = now()->subDay()->startOfSecond();

        try {
            $this->insertRaw('preflight-unchanged', [
                'login_verify' => 'legacy-unknown',
                'two_factor_enabled' => true,
                'two_factor_secret' => null,
                'two_factor_confirmed_at' => $confirmedAt,
                'two_factor_recovery_codes' => json_encode(['must-remain']),
            ]);
            $this->insertRaw('preflight-corrupt', [
                'login_verify' => User::LOGIN_VERIFY_TOTP,
                'two_factor_enabled' => true,
                'two_factor_secret' => 'not-valid-ciphertext!',
            ]);

            try {
                $migration->up();
                $this->fail('The migration accepted corrupt encrypted factor state.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('invalid or undecryptable', $exception->getMessage());
            }

            $untouched = $this->rawUser('preflight-unchanged');
            $this->assertSame('legacy-unknown', $untouched->login_verify);
            $this->assertSame(1, (int) $untouched->two_factor_enabled);
            $this->assertSame($confirmedAt->format('Y-m-d H:i:s'), $untouched->two_factor_confirmed_at);
            $this->assertSame('["must-remain"]', $untouched->two_factor_recovery_codes);
            $this->assertFalse($this->constraintExists());
        } finally {
            DB::table('users')->where('username', 'preflight-corrupt')->delete();
            $migration->up();
            DB::table('users')->where('username', 'preflight-unchanged')->delete();
        }
    }

    public function test_migration_accepts_a_secret_encrypted_with_a_configured_previous_key(): void
    {
        $migration = $this->migration();
        $migration->down();
        $secret = app(TwoFactorService::class)->generateSecret();
        $previousBytes = str_repeat('p', 32);
        $previousKey = 'base64:'.base64_encode($previousBytes);
        $ciphertext = (new Encrypter($previousBytes, (string) config('app.cipher')))
            ->encryptString($secret);
        $originalKey = config('app.key');
        $originalPreviousKeys = config('app.previous_keys', []);

        try {
            config(['app.previous_keys' => [$previousKey]]);
            $this->rebuildEncrypter();
            $this->insertRaw('previous-key-normalization', [
                'login_verify' => User::LOGIN_VERIFY_TOTP,
                'two_factor_enabled' => false,
                'two_factor_secret' => $ciphertext,
            ]);

            $migration->up();

            $user = User::where('username', 'previous-key-normalization')->firstOrFail();
            $this->assertTrue($user->hasActiveTotp());
            $this->assertSame($secret, $user->two_factor_secret);
        } finally {
            DB::table('users')->where('username', 'previous-key-normalization')->delete();
            config([
                'app.key' => $originalKey,
                'app.previous_keys' => $originalPreviousKeys,
            ]);
            $this->rebuildEncrypter();
            $migration->up();
        }
    }

    public function test_migration_rejects_decryptable_data_that_is_not_a_totp_secret(): void
    {
        $migration = $this->migration();
        $migration->down();
        $ciphertext = Crypt::encryptString('not-a-base32-secret!');

        try {
            $this->insertRaw('invalid-secret-format', [
                'login_verify' => User::LOGIN_VERIFY_TOTP,
                'two_factor_enabled' => true,
                'two_factor_secret' => $ciphertext,
            ]);

            try {
                $migration->up();
                $this->fail('The migration accepted decrypted data with an invalid TOTP format.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString(
                    'not a valid TOTP secret',
                    $exception->getMessage(),
                );
            }

            $this->assertSame(
                $ciphertext,
                $this->rawUser('invalid-secret-format')->two_factor_secret,
            );
            $this->assertFalse($this->constraintExists());
        } finally {
            DB::table('users')->where('username', 'invalid-secret-format')->delete();
            $migration->up();
        }
    }

    public function test_database_check_rejects_partial_factor_states_and_down_keeps_repairs(): void
    {
        $migration = $this->migration();
        $migration->down();
        $user = User::create([
            'username' => 'constraint-target',
            'password' => 'secret123456',
            'status' => User::STATUS_NORMAL,
            'login_verify' => User::LOGIN_VERIFY_TOTP,
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => ['orphaned-metadata'],
        ]);

        try {
            $migration->up();
            $user->refresh();
            $this->assertSame(User::LOGIN_VERIFY_OFF, $user->login_verify);
            $this->assertFalse($user->two_factor_enabled);
            $this->assertNull($user->two_factor_confirmed_at);
            $this->assertNull($user->two_factor_recovery_codes);

            try {
                DB::table('users')->where('id', $user->id)->update([
                    'login_verify' => User::LOGIN_VERIFY_TOTP,
                    'two_factor_enabled' => true,
                    'two_factor_secret' => null,
                ]);
                $this->fail('The database accepted a partial TOTP state.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString(
                    'users_two_factor_state_consistent',
                    $exception->getMessage(),
                );
            }

            foreach (['OFF', 'EMAIL', 'TOTP', 'off '] as $caseVariant) {
                try {
                    DB::table('users')->where('id', $user->id)->update([
                        'login_verify' => $caseVariant,
                    ]);
                    $this->fail("The database accepted the non-canonical policy {$caseVariant}.");
                } catch (QueryException $exception) {
                    $this->assertStringContainsString(
                        'users_two_factor_state_consistent',
                        $exception->getMessage(),
                    );
                }
            }

            $migration->down();
            $this->assertFalse($this->constraintExists());
            $user->refresh();
            $this->assertSame(User::LOGIN_VERIFY_OFF, $user->login_verify);
            $this->assertFalse($user->two_factor_enabled);
        } finally {
            DB::table('users')->where('id', $user->id)->delete();
            $migration->up();
        }
    }

    private function migration(): object
    {
        return require database_path(
            'migrations/2026_07_15_100001_normalize_user_two_factor_state.php'
        );
    }

    /** @param array<string, mixed> $state */
    private function insertRaw(string $username, array $state): void
    {
        DB::table('users')->insert([
            'username' => $username,
            'password' => 'legacy-password',
            'status' => User::STATUS_NORMAL,
            ...$state,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function rawUser(string $username): object
    {
        return DB::table('users')->where('username', $username)->firstOrFail();
    }

    private function assertInactiveState(object $row): void
    {
        $this->assertSame(0, (int) $row->two_factor_enabled);
        $this->assertNull($row->two_factor_secret);
        $this->assertNull($row->two_factor_confirmed_at);
        $this->assertNull($row->two_factor_recovery_codes);
    }

    private function constraintExists(): bool
    {
        $result = DB::selectOne(<<<'SQL'
            SELECT COUNT(*) AS `aggregate`
            FROM `information_schema`.`TABLE_CONSTRAINTS`
            WHERE `CONSTRAINT_SCHEMA` = DATABASE()
                AND `TABLE_NAME` = 'users'
                AND `CONSTRAINT_NAME` = 'users_two_factor_state_consistent'
                AND `CONSTRAINT_TYPE` = 'CHECK'
            SQL);

        return is_object($result) && (int) ($result->aggregate ?? 0) > 0;
    }

    private function rebuildEncrypter(): void
    {
        User::encryptUsing(null);
        Crypt::clearResolvedInstance('encrypter');
        $this->app->forgetInstance('encrypter');
    }
}
