<?php

use App\Support\TotpSecretFormat;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CONSTRAINT = 'users_two_factor_state_consistent';

    /**
     * Repair historical partial states, then make the structural TOTP invariant durable.
     */
    public function up(): void
    {
        // Validate the complete ciphertext set before changing the first account. Laravel's
        // encrypter tries APP_KEY followed by APP_PREVIOUS_KEYS, so a missing rotation key or
        // malformed seed aborts without partially normalizing unrelated rows.
        $this->assertEverySecretIsReadable();

        DB::transaction(function (): void {
            // MariaDB evaluates single-table assignments from left to right. Secret retention
            // is therefore decided first from the historical state; later assignments can use
            // the retained/cleared value without resurrecting an orphaned factor.
            DB::statement(<<<'SQL'
                UPDATE `users`
                SET
                    `two_factor_secret` = CASE
                        WHEN `two_factor_secret` IS NOT NULL
                            AND (`two_factor_enabled` = 1 OR BINARY `login_verify` = BINARY 'totp')
                        THEN `two_factor_secret`
                        ELSE NULL
                    END,
                    `two_factor_confirmed_at` = CASE
                        WHEN `two_factor_secret` IS NOT NULL
                            AND (`two_factor_enabled` = 1 OR BINARY `login_verify` = BINARY 'totp')
                        THEN `two_factor_confirmed_at`
                        ELSE NULL
                    END,
                    `two_factor_recovery_codes` = CASE
                        WHEN `two_factor_secret` IS NOT NULL
                            AND (`two_factor_enabled` = 1 OR BINARY `login_verify` = BINARY 'totp')
                        THEN `two_factor_recovery_codes`
                        ELSE NULL
                    END,
                    `two_factor_enabled` = CASE
                        WHEN `two_factor_secret` IS NOT NULL
                            AND (`two_factor_enabled` = 1 OR BINARY `login_verify` = BINARY 'totp')
                        THEN 1
                        ELSE 0
                    END,
                    `login_verify` = CASE
                        WHEN `two_factor_secret` IS NOT NULL
                            AND (`two_factor_enabled` = 1 OR BINARY `login_verify` = BINARY 'totp')
                        THEN 'totp'
                        WHEN BINARY `login_verify` = BINARY 'email'
                        THEN 'email'
                        ELSE 'off'
                    END
                SQL);
        });

        if (! $this->constraintExists()) {
            DB::statement(<<<'SQL'
                ALTER TABLE `users`
                ADD CONSTRAINT `users_two_factor_state_consistent`
                CHECK (
                    CASE
                        WHEN BINARY `login_verify` = BINARY 'totp'
                            AND `two_factor_enabled` = 1
                            AND `two_factor_secret` IS NOT NULL
                        THEN 1
                        WHEN (
                                BINARY `login_verify` = BINARY 'off'
                                OR BINARY `login_verify` = BINARY 'email'
                            )
                            AND `two_factor_enabled` = 0
                            AND `two_factor_secret` IS NULL
                            AND `two_factor_confirmed_at` IS NULL
                            AND `two_factor_recovery_codes` IS NULL
                        THEN 1
                        ELSE 0
                    END = 1
                )
                SQL);
        }
    }

    /**
     * Keep repaired account data intact; rollback removes only the enforcement boundary.
     */
    public function down(): void
    {
        if ($this->constraintExists()) {
            DB::statement(
                'ALTER TABLE `users` DROP CONSTRAINT `'.self::CONSTRAINT.'`'
            );
        }
    }

    private function assertEverySecretIsReadable(): void
    {
        DB::table('users')
            ->select(['id', 'two_factor_secret'])
            ->whereNotNull('two_factor_secret')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $stored = $row->two_factor_secret ?? null;
                    if (! is_string($stored)) {
                        throw new RuntimeException(
                            "User {$row->id} has a non-string encrypted TOTP secret."
                        );
                    }

                    try {
                        $secret = Crypt::decryptString($stored);
                    } catch (DecryptException) {
                        throw new RuntimeException(
                            "User {$row->id} has an invalid or undecryptable encrypted TOTP secret."
                        );
                    }

                    if (! TotpSecretFormat::isValid($secret)) {
                        throw new RuntimeException(
                            "User {$row->id} has encrypted data that is not a valid TOTP secret."
                        );
                    }
                }
            });
    }

    private function constraintExists(): bool
    {
        $result = DB::selectOne(
            <<<'SQL'
                SELECT COUNT(*) AS `aggregate`
                FROM `information_schema`.`TABLE_CONSTRAINTS`
                WHERE `CONSTRAINT_SCHEMA` = DATABASE()
                    AND `TABLE_NAME` = 'users'
                    AND `CONSTRAINT_NAME` = ?
                    AND `CONSTRAINT_TYPE` = 'CHECK'
                SQL,
            [self::CONSTRAINT],
        );

        return is_object($result) && (int) ($result->aggregate ?? 0) > 0;
    }
};
