<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserEmailVerificationStateMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_migration_preflight_rejects_unusable_policies_before_changing_any_row(): void
    {
        $migration = $this->migration();
        $migration->down();

        try {
            $nullId = $this->insertRaw('email-policy-null', User::LOGIN_VERIFY_EMAIL, null);
            $whitespaceId = $this->insertRaw(
                'email-policy-whitespace',
                User::LOGIN_VERIFY_EMAIL,
                " \t  ",
            );
            $validId = $this->insertRaw(
                'email-policy-valid',
                User::LOGIN_VERIFY_EMAIL,
                'valid@example.test',
            );
            $this->insertRaw('off-policy-null', User::LOGIN_VERIFY_OFF, null);
            $this->insertRaw('off-policy-whitespace', User::LOGIN_VERIFY_OFF, "\t ");

            try {
                $migration->up();
                $this->fail('The migration accepted unusable email-verification policies.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('2 user account(s)', $exception->getMessage());
                $this->assertStringContainsString((string) $nullId, $exception->getMessage());
                $this->assertStringContainsString((string) $whitespaceId, $exception->getMessage());
                $this->assertStringNotContainsString((string) $validId, $exception->getMessage());
            }

            $this->assertSame(
                User::LOGIN_VERIFY_EMAIL,
                $this->rawUser('email-policy-null')->login_verify,
            );

            $whitespace = $this->rawUser('email-policy-whitespace');
            $this->assertSame(User::LOGIN_VERIFY_EMAIL, $whitespace->login_verify);
            $this->assertSame(" \t  ", $whitespace->email);

            $valid = $this->rawUser('email-policy-valid');
            $this->assertSame(User::LOGIN_VERIFY_EMAIL, $valid->login_verify);
            $this->assertSame('valid@example.test', $valid->email);

            $this->assertSame(
                User::LOGIN_VERIFY_OFF,
                $this->rawUser('off-policy-null')->login_verify,
            );

            $unrelated = $this->rawUser('off-policy-whitespace');
            $this->assertSame(User::LOGIN_VERIFY_OFF, $unrelated->login_verify);
            $this->assertSame("\t ", $unrelated->email);
            $this->assertFalse($this->constraintExists());
        } finally {
            $this->deleteFixtures();
            $migration->up();
        }
    }

    public function test_migration_accepts_valid_email_policies_and_preserves_unrelated_rows(): void
    {
        $migration = $this->migration();
        $migration->down();

        try {
            $this->insertRaw(
                'valid-policy-address',
                User::LOGIN_VERIFY_EMAIL,
                'valid@example.test',
            );
            $this->insertRaw('unrelated-off-null', User::LOGIN_VERIFY_OFF, null);
            $this->insertRaw('unrelated-off-whitespace', User::LOGIN_VERIFY_OFF, " \t ");

            $migration->up();

            $valid = $this->rawUser('valid-policy-address');
            $this->assertSame(User::LOGIN_VERIFY_EMAIL, $valid->login_verify);
            $this->assertSame('valid@example.test', $valid->email);

            $this->assertSame(
                User::LOGIN_VERIFY_OFF,
                $this->rawUser('unrelated-off-null')->login_verify,
            );

            $unrelated = $this->rawUser('unrelated-off-whitespace');
            $this->assertSame(User::LOGIN_VERIFY_OFF, $unrelated->login_verify);
            $this->assertSame(" \t ", $unrelated->email);
            $this->assertTrue($this->constraintExists());
        } finally {
            DB::table('users')->whereIn('username', [
                'valid-policy-address',
                'unrelated-off-null',
                'unrelated-off-whitespace',
            ])->delete();
            $migration->up();
        }
    }

    public function test_migration_preflight_bounds_the_reported_ids_and_includes_the_total_count(): void
    {
        $migration = $this->migration();
        $migration->down();
        $ids = [];

        try {
            for ($index = 0; $index < 21; $index++) {
                $ids[] = $this->insertRaw(
                    "email-policy-report-{$index}",
                    User::LOGIN_VERIFY_EMAIL,
                    null,
                );
            }

            try {
                $migration->up();
                $this->fail('The migration accepted unusable email-verification policies.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('21 user account(s)', $exception->getMessage());
                $matched = preg_match(
                    '/Affected user IDs \(up to 20\): ([0-9, ]+)\./',
                    $exception->getMessage(),
                    $matches,
                );
                $this->assertSame(1, $matched);

                $reportedIds = explode(', ', $matches[1]);
                $this->assertCount(20, $reportedIds);
                $this->assertSame(
                    array_map(static fn (int $id): string => (string) $id, array_slice($ids, 0, 20)),
                    $reportedIds,
                );
                $this->assertNotContains((string) $ids[20], $reportedIds);
            }

            $this->assertFalse($this->constraintExists());
        } finally {
            DB::table('users')->whereIn('id', $ids)->delete();
            $migration->up();
        }
    }

    public function test_database_check_rejects_email_policy_without_a_non_whitespace_address(): void
    {
        $userId = $this->insertRaw('email-policy-check', User::LOGIN_VERIFY_OFF, null);

        try {
            foreach ([null, " \t "] as $invalidEmail) {
                try {
                    DB::table('users')->where('id', $userId)->update([
                        'email' => $invalidEmail,
                        'login_verify' => User::LOGIN_VERIFY_EMAIL,
                    ]);
                    $this->fail('The database accepted an unusable email-verification policy.');
                } catch (QueryException $exception) {
                    $this->assertStringContainsString(
                        'users_email_verification_requires_address',
                        $exception->getMessage(),
                    );
                }
            }

            DB::table('users')->where('id', $userId)->update([
                'email' => 'usable@example.test',
                'login_verify' => User::LOGIN_VERIFY_EMAIL,
            ]);

            $this->assertSame(
                User::LOGIN_VERIFY_EMAIL,
                $this->rawUser('email-policy-check')->login_verify,
            );
        } finally {
            DB::table('users')->where('id', $userId)->delete();
        }
    }

    public function test_noncanonical_email_policy_spellings_are_not_accepted_as_canonical_state(): void
    {
        $emailMigration = $this->migration();
        $twoFactorMigration = $this->twoFactorMigration();
        $emailMigration->down();
        $twoFactorMigration->down();
        $ids = [
            $this->insertRaw('email-policy-uppercase', 'EMAIL', null),
            $this->insertRaw('email-policy-trailing', 'email ', null),
        ];

        try {
            // The email-address invariant applies only to the exact canonical policy. With
            // the broader policy constraint temporarily absent, both variants remain visible
            // to prove neither the preflight nor this CHECK uses collation-folded equality.
            $emailMigration->up();
            $this->assertSame('EMAIL', $this->rawUser('email-policy-uppercase')->login_verify);
            $this->assertSame('email ', $this->rawUser('email-policy-trailing')->login_verify);

            // Reapplying the policy-state migration normalizes historical variants. Its exact
            // CHECK then rejects either variant on every future write.
            $twoFactorMigration->up();
            $this->assertSame(
                User::LOGIN_VERIFY_OFF,
                $this->rawUser('email-policy-uppercase')->login_verify,
            );
            $this->assertSame(
                User::LOGIN_VERIFY_OFF,
                $this->rawUser('email-policy-trailing')->login_verify,
            );

            foreach (array_combine($ids, ['EMAIL', 'email ']) as $userId => $policy) {
                try {
                    DB::table('users')->where('id', $userId)->update([
                        'email' => 'variant@example.test',
                        'login_verify' => $policy,
                    ]);
                    $this->fail('The database accepted a noncanonical login policy.');
                } catch (QueryException $exception) {
                    $this->assertStringContainsString(
                        'users_two_factor_state_consistent',
                        $exception->getMessage(),
                    );
                }
            }
        } finally {
            DB::table('users')->whereIn('id', $ids)->delete();
            $twoFactorMigration->up();
            $emailMigration->up();
        }
    }

    public function test_rollback_removes_only_the_constraint_and_preserves_existing_state(): void
    {
        $migration = $this->migration();
        $userId = $this->insertRaw(
            'email-policy-rollback',
            User::LOGIN_VERIFY_EMAIL,
            'rollback@example.test',
        );

        try {
            $migration->down();

            $this->assertFalse($this->constraintExists());
            $rolledBack = $this->rawUser('email-policy-rollback');
            $this->assertSame(User::LOGIN_VERIFY_EMAIL, $rolledBack->login_verify);
            $this->assertSame('rollback@example.test', $rolledBack->email);

            DB::table('users')->where('id', $userId)->update([
                'email' => null,
                'login_verify' => User::LOGIN_VERIFY_EMAIL,
            ]);
            $this->assertSame(
                User::LOGIN_VERIFY_EMAIL,
                $this->rawUser('email-policy-rollback')->login_verify,
            );
        } finally {
            DB::table('users')->where('id', $userId)->delete();
            $migration->up();
        }
    }

    private function migration(): object
    {
        return require database_path(
            'migrations/2026_07_15_100002_require_email_for_email_verification.php'
        );
    }

    private function twoFactorMigration(): object
    {
        return require database_path(
            'migrations/2026_07_15_100001_normalize_user_two_factor_state.php'
        );
    }

    private function insertRaw(string $username, string $policy, ?string $email): int
    {
        return (int) DB::table('users')->insertGetId([
            'username' => $username,
            'email' => $email,
            'password' => 'legacy-password',
            'status' => User::STATUS_NORMAL,
            'login_verify' => $policy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function rawUser(string $username): object
    {
        return DB::table('users')->where('username', $username)->firstOrFail();
    }

    private function deleteFixtures(): void
    {
        DB::table('users')->whereIn('username', [
            'email-policy-null',
            'email-policy-whitespace',
            'email-policy-valid',
            'off-policy-null',
            'off-policy-whitespace',
        ])->delete();
    }

    private function constraintExists(): bool
    {
        $result = DB::selectOne(<<<'SQL'
            SELECT COUNT(*) AS `aggregate`
            FROM `information_schema`.`TABLE_CONSTRAINTS`
            WHERE `CONSTRAINT_SCHEMA` = DATABASE()
                AND `TABLE_NAME` = 'users'
                AND `CONSTRAINT_NAME` = 'users_email_verification_requires_address'
                AND `CONSTRAINT_TYPE` = 'CHECK'
            SQL);

        return is_object($result) && (int) ($result->aggregate ?? 0) > 0;
    }
}
