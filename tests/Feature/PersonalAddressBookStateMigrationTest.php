<?php

namespace Tests\Feature;

use App\Models\AddressBook;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PersonalAddressBookStateMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_migration_marks_only_the_lowest_legacy_name_match_for_each_owner(): void
    {
        $migration = $this->migration();
        $migration->down();
        $owner = $this->user('legacy-personal-owner');
        $other = $this->user('legacy-personal-other');
        $first = $this->book($owner, 'My Address Book');
        $duplicate = $this->book($owner, 'MY ADDRESS BOOK');
        $ordinary = $this->book($owner, 'Operations');
        $otherPersonal = $this->book($other, 'My address book');
        $ownerless = $this->book(null, 'My address book');

        try {
            $migration->up();

            $this->assertSame(1, (int) DB::table('address_books')->where('id', $first)->value('is_personal'));
            $this->assertNull(DB::table('address_books')->where('id', $duplicate)->value('is_personal'));
            $this->assertNull(DB::table('address_books')->where('id', $ordinary)->value('is_personal'));
            $this->assertSame(1, (int) DB::table('address_books')->where('id', $otherPersonal)->value('is_personal'));
            $this->assertNull(DB::table('address_books')->where('id', $ownerless)->value('is_personal'));
            $this->assertTrue($this->checkConstraintExists());
            $this->assertTrue($this->indexExists());
        } finally {
            DB::table('address_books')->whereIn('id', [$first, $duplicate, $ordinary, $otherPersonal, $ownerless])->delete();
            DB::table('users')->whereIn('id', [$owner, $other])->delete();
            $migration->up();
        }
    }

    public function test_migration_preserves_an_existing_valid_marker_during_partial_recovery(): void
    {
        $migration = $this->migration();
        $migration->down();
        Schema::table('address_books', function (Blueprint $table): void {
            $table->boolean('is_personal')->nullable()->after('user_id');
        });
        $owner = $this->user('partial-personal-owner');
        $lowerNamed = $this->book($owner, AddressBook::PERSONAL_NAME);
        $marked = $this->book($owner, 'Recovered personal', true);

        try {
            $migration->up();

            $this->assertNull(DB::table('address_books')->where('id', $lowerNamed)->value('is_personal'));
            $this->assertSame(1, (int) DB::table('address_books')->where('id', $marked)->value('is_personal'));
            $this->assertSame(
                1,
                DB::table('address_books')->where('user_id', $owner)->where('is_personal', 1)->count(),
            );
        } finally {
            DB::table('address_books')->whereIn('id', [$lowerNamed, $marked])->delete();
            DB::table('users')->where('id', $owner)->delete();
            $migration->up();
        }
    }

    public function test_rollback_refuses_to_make_the_legacy_name_resolver_ambiguous(): void
    {
        $migration = $this->migration();
        $collisionOwner = User::findOrFail($this->user('personal-rollback-collision'));
        $customOwner = User::findOrFail($this->user('personal-rollback-custom'));
        $ordinary = AddressBook::create([
            'user_id' => $collisionOwner->id,
            'name' => AddressBook::PERSONAL_NAME,
        ]);
        $personal = AddressBook::personalFor($collisionOwner);
        $custom = AddressBook::create([
            'user_id' => $customOwner->id,
            'is_personal' => true,
            'name' => 'Custom personal name',
        ]);

        try {
            try {
                $migration->down();
                $this->fail('Rollback discarded personal-book identity in an ambiguous state.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('2 marked book(s)', $exception->getMessage());
                $this->assertStringContainsString((string) $personal->id, $exception->getMessage());
                $this->assertStringContainsString((string) $custom->id, $exception->getMessage());
            }

            $this->assertTrue(Schema::hasColumn('address_books', 'is_personal'));
            $this->assertTrue($this->checkConstraintExists());
            $this->assertTrue($this->indexExists());
            $this->assertDatabaseHas('address_books', ['id' => $ordinary->id]);
            $this->assertDatabaseHas('address_books', ['id' => $personal->id, 'is_personal' => 1]);
            $this->assertDatabaseHas('address_books', ['id' => $custom->id, 'is_personal' => 1]);
        } finally {
            DB::table('address_books')->whereIn('id', [$ordinary->id, $personal->id, $custom->id])->delete();
            DB::table('users')->whereIn('id', [$collisionOwner->id, $customOwner->id])->delete();
            $migration->up();
        }
    }

    public function test_migration_rejects_a_colliding_column_definition_before_ddl(): void
    {
        $migration = $this->migration();
        $migration->down();
        Schema::table('address_books', function (Blueprint $table): void {
            $table->string('is_personal', 8)->nullable()->after('user_id');
        });

        try {
            try {
                $migration->up();
                $this->fail('The migration accepted a colliding marker-column definition.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString(
                    'does not match the expected nullable TINYINT(1) definition',
                    $exception->getMessage(),
                );
            }

            $this->assertFalse($this->checkConstraintExists());
            $this->assertFalse($this->indexExists());
        } finally {
            Schema::table('address_books', function (Blueprint $table): void {
                $table->dropColumn('is_personal');
            });
            $migration->up();
        }
    }

    public function test_migration_rejects_a_colliding_index_definition_before_data_changes(): void
    {
        $migration = $this->migration();
        $migration->down();
        DB::statement(
            'ALTER TABLE `address_books` ADD COLUMN `is_personal` TINYINT(1) NULL DEFAULT NULL '
            .'AFTER `user_id`, ADD INDEX `address_books_one_personal_per_user` (`name`)'
        );

        try {
            try {
                $migration->up();
                $this->fail('The migration accepted a colliding personal-book index.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString(
                    'does not exactly enforce UNIQUE (user_id, is_personal)',
                    $exception->getMessage(),
                );
            }

            $this->assertFalse($this->checkConstraintExists());
            $this->assertTrue($this->indexExists());
        } finally {
            DB::statement(
                'ALTER TABLE `address_books` '
                .'DROP INDEX `address_books_one_personal_per_user`, DROP COLUMN `is_personal`'
            );
            $migration->up();
        }
    }

    public function test_migration_rejects_a_colliding_check_definition_before_data_changes(): void
    {
        $migration = $this->migration();
        $migration->down();
        DB::statement(<<<'SQL'
            ALTER TABLE `address_books`
            ADD COLUMN `is_personal` TINYINT(1) NULL DEFAULT NULL AFTER `user_id`,
            ADD CONSTRAINT `address_books_personal_marker_valid`
                CHECK ((`is_personal` IS NULL OR `is_personal` = 1) AND `user_id` IS NOT NULL)
            SQL);

        try {
            try {
                $migration->up();
                $this->fail('The migration accepted a colliding personal-book CHECK.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString(
                    'does not match the expected marker validation',
                    $exception->getMessage(),
                );
            }

            $this->assertTrue($this->checkConstraintExists());
            $this->assertFalse($this->indexExists());
        } finally {
            DB::statement(
                'ALTER TABLE `address_books` '
                .'DROP CONSTRAINT `address_books_personal_marker_valid`, '
                .'DROP COLUMN `is_personal`'
            );
            $migration->up();
        }
    }

    public function test_rollback_removes_only_enforcement_and_preserves_books(): void
    {
        $migration = $this->migration();
        $owner = $this->user('personal-rollback-owner');
        $personal = AddressBook::personalFor(User::findOrFail($owner));
        $ordinary = AddressBook::create(['user_id' => $owner, 'name' => 'Rollback ordinary']);

        try {
            $migration->down();

            $this->assertFalse(Schema::hasColumn('address_books', 'is_personal'));
            $this->assertDatabaseHas('address_books', ['id' => $personal->id, 'name' => AddressBook::PERSONAL_NAME]);
            $this->assertDatabaseHas('address_books', ['id' => $ordinary->id, 'name' => 'Rollback ordinary']);
        } finally {
            DB::table('address_books')->whereIn('id', [$personal->id, $ordinary->id])->delete();
            DB::table('users')->where('id', $owner)->delete();
            $migration->up();
        }
    }

    private function migration(): object
    {
        return require database_path(
            'migrations/2026_07_15_100003_enforce_personal_address_book_singleton.php'
        );
    }

    private function user(string $username): int
    {
        return (int) DB::table('users')->insertGetId([
            'username' => $username,
            'password' => 'legacy-password',
            'status' => User::STATUS_NORMAL,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function book(?int $owner, string $name, ?bool $isPersonal = null): int
    {
        $attributes = [
            'user_id' => $owner,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('address_books', 'is_personal')) {
            $attributes['is_personal'] = $isPersonal;
        }

        return (int) DB::table('address_books')->insertGetId($attributes);
    }

    private function checkConstraintExists(): bool
    {
        $result = DB::selectOne(<<<'SQL'
            SELECT COUNT(*) AS `aggregate`
            FROM `information_schema`.`TABLE_CONSTRAINTS`
            WHERE `CONSTRAINT_SCHEMA` = DATABASE()
                AND `TABLE_NAME` = 'address_books'
                AND `CONSTRAINT_NAME` = 'address_books_personal_marker_valid'
                AND `CONSTRAINT_TYPE` = 'CHECK'
            SQL);

        return is_object($result) && (int) ($result->aggregate ?? 0) > 0;
    }

    private function indexExists(): bool
    {
        $result = DB::selectOne(<<<'SQL'
            SELECT COUNT(*) AS `aggregate`
            FROM `information_schema`.`STATISTICS`
            WHERE `TABLE_SCHEMA` = DATABASE()
                AND `TABLE_NAME` = 'address_books'
                AND `INDEX_NAME` = 'address_books_one_personal_per_user'
            SQL);

        return is_object($result) && (int) ($result->aggregate ?? 0) > 0;
    }
}
