<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CONSTRAINT = 'users_email_verification_requires_address';

    private const REPORTED_ID_LIMIT = 20;

    /**
     * Reject unusable historical policies before enforcing the invariant in MariaDB.
     */
    public function up(): void
    {
        $this->assertEveryEmailVerificationPolicyHasAddress();

        if (! $this->constraintExists()) {
            DB::statement(<<<'SQL'
                ALTER TABLE `users`
                ADD CONSTRAINT `users_email_verification_requires_address`
                CHECK (
                    CASE
                        WHEN BINARY `login_verify` = BINARY 'email'
                            AND `email` IS NOT NULL
                            AND `email` REGEXP '[^[:space:]]'
                        THEN 1
                        WHEN BINARY `login_verify` = BINARY 'email'
                        THEN 0
                        ELSE 1
                    END = 1
                )
                SQL);
        }
    }

    /**
     * Rollback removes only enforcement and never changes account policy or address data.
     */
    public function down(): void
    {
        if ($this->constraintExists()) {
            DB::statement(
                'ALTER TABLE `users` DROP CONSTRAINT `'.self::CONSTRAINT.'`'
            );
        }
    }

    private function assertEveryEmailVerificationPolicyHasAddress(): void
    {
        $invalidPolicies = DB::table('users')
            ->whereRaw("BINARY `login_verify` = BINARY 'email'")
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('email')
                    ->orWhereRaw("NOT (`email` REGEXP '[^[:space:]]')");
            });

        $count = (clone $invalidPolicies)->count();
        if ($count === 0) {
            return;
        }

        $ids = (clone $invalidPolicies)
            ->orderBy('id')
            ->limit(self::REPORTED_ID_LIMIT)
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->implode(', ');

        throw new RuntimeException(
            "Cannot enforce email-verification address invariant: {$count} user account(s) "
            .'use email verification without an address containing a non-whitespace character. '
            .'Affected user IDs (up to '.self::REPORTED_ID_LIMIT."): {$ids}. "
            .'Set a valid email address or intentionally change login_verify before retrying.'
        );
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
