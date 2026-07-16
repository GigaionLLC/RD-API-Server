<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TotpSecretMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_migration_encrypts_legacy_secrets_idempotently_and_can_round_trip(): void
    {
        $migration = require database_path(
            'migrations/2026_07_14_100007_encrypt_two_factor_secrets.php'
        );
        $secret = app(TwoFactorService::class)->generateSecret();

        $migration->down();

        try {
            $userId = DB::table('users')->insertGetId([
                'username' => 'legacy-totp-migration-user',
                'password' => 'legacy-password',
                'status' => User::STATUS_NORMAL,
                'login_verify' => User::LOGIN_VERIFY_TOTP,
                'two_factor_enabled' => true,
                'two_factor_secret' => $secret,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $migration->up();
            $firstPass = DB::table('users')->where('id', $userId)->value('two_factor_secret');
            $this->assertIsString($firstPass);
            $this->assertNotSame($secret, $firstPass);
            $this->assertSame($secret, Crypt::decryptString($firstPass));

            $migration->up();
            $this->assertSame(
                $firstPass,
                DB::table('users')->where('id', $userId)->value('two_factor_secret'),
            );

            $migration->down();
            $this->assertSame(
                $secret,
                DB::table('users')->where('id', $userId)->value('two_factor_secret'),
            );
        } finally {
            DB::table('users')->where('username', 'legacy-totp-migration-user')->delete();
            $migration->up();
        }
    }

    public function test_migration_rejects_unknown_or_corrupt_stored_values(): void
    {
        $migration = require database_path(
            'migrations/2026_07_14_100007_encrypt_two_factor_secrets.php'
        );

        $migration->down();

        try {
            $unknownKey = new Encrypter(str_repeat('u', 32), (string) config('app.cipher'));
            DB::table('users')->insert([
                'username' => 'corrupt-totp-migration-user',
                'password' => 'legacy-password',
                'status' => User::STATUS_NORMAL,
                'login_verify' => User::LOGIN_VERIFY_TOTP,
                'two_factor_enabled' => true,
                'two_factor_secret' => $unknownKey->encryptString(
                    str_repeat('A', 16),
                ),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            try {
                $migration->up();
                $this->fail('The migration accepted an invalid stored TOTP value.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('invalid or undecryptable', $exception->getMessage());
            }
        } finally {
            DB::table('users')->where('username', 'corrupt-totp-migration-user')->delete();
            $migration->up();
        }
    }

    public function test_failed_rollback_does_not_partially_decrypt_earlier_rows(): void
    {
        $migration = require database_path(
            'migrations/2026_07_14_100007_encrypt_two_factor_secrets.php'
        );
        $secret = app(TwoFactorService::class)->generateSecret();
        $ciphertext = Crypt::encryptString($secret);

        DB::table('users')->insert([
            [
                'username' => 'valid-rollback-totp-user',
                'password' => 'legacy-password',
                'status' => User::STATUS_NORMAL,
                'login_verify' => User::LOGIN_VERIFY_TOTP,
                'two_factor_enabled' => true,
                'two_factor_secret' => $ciphertext,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'username' => 'corrupt-rollback-totp-user',
                'password' => 'legacy-password',
                'status' => User::STATUS_NORMAL,
                'login_verify' => User::LOGIN_VERIFY_TOTP,
                'two_factor_enabled' => true,
                'two_factor_secret' => 'not-valid-ciphertext!',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        try {
            try {
                $migration->down();
                $this->fail('The rollback accepted an invalid stored TOTP value.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('invalid or undecryptable', $exception->getMessage());
            }

            $this->assertSame(
                $ciphertext,
                DB::table('users')
                    ->where('username', 'valid-rollback-totp-user')
                    ->value('two_factor_secret'),
            );
        } finally {
            DB::table('users')->whereIn('username', [
                'valid-rollback-totp-user',
                'corrupt-rollback-totp-user',
            ])->delete();
        }
    }
}
