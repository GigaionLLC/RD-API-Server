<?php

namespace Tests\Feature;

use App\Models\AddressBook;
use App\Models\AddressBookPeer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AddressBookPeerIdentityMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_migration_preflight_reports_duplicate_pairs_before_ddl_or_data_changes(): void
    {
        $migration = $this->migration();
        $migration->down();
        $owner = $this->user('peer-preflight-owner');
        $book = $this->book($owner, 'Preflight book');
        $first = $this->peer($book, $owner, 'duplicate-id', 'First');
        $second = $this->peer($book, $owner, 'duplicate-id', 'Second');

        try {
            try {
                $migration->up();
                $this->fail('The migration accepted duplicate peer identities.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('1 duplicate (book, RustDesk ID) pair(s)', $exception->getMessage());
                $this->assertStringContainsString($book.':"duplicate-id"', $exception->getMessage());
            }

            $this->assertFalse($this->indexExists());
            $this->assertDatabaseHas('address_book_peers', ['id' => $first, 'alias' => 'First']);
            $this->assertDatabaseHas('address_book_peers', ['id' => $second, 'alias' => 'Second']);
        } finally {
            DB::table('address_book_peers')->whereIn('id', [$first, $second])->delete();
            DB::table('address_books')->where('id', $book)->delete();
            DB::table('users')->where('id', $owner)->delete();
            $migration->up();
        }
    }

    public function test_database_rejects_a_duplicate_only_within_the_same_book(): void
    {
        $owner = User::findOrFail($this->user('peer-constraint-owner'));
        $firstBook = AddressBook::create(['user_id' => $owner->id, 'name' => 'First book']);
        $secondBook = AddressBook::create(['user_id' => $owner->id, 'name' => 'Second book']);

        AddressBookPeer::create([
            'address_book_id' => $firstBook->id,
            'user_id' => $owner->id,
            'rustdesk_id' => 'shared-id',
        ]);
        AddressBookPeer::create([
            'address_book_id' => $secondBook->id,
            'user_id' => $owner->id,
            'rustdesk_id' => 'shared-id',
        ]);

        try {
            AddressBookPeer::create([
                'address_book_id' => $firstBook->id,
                'user_id' => $owner->id,
                'rustdesk_id' => 'shared-id',
            ]);
            $this->fail('The database accepted a duplicate peer identity within one book.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString(
                'address_book_peers_unique_book_rustdesk_id',
                $exception->getMessage(),
            );
        }

        $this->assertSame(2, AddressBookPeer::where('rustdesk_id', 'shared-id')->count());
    }

    public function test_migration_rejects_a_colliding_named_index(): void
    {
        $migration = $this->migration();
        $migration->down();
        DB::statement(
            'ALTER TABLE `address_book_peers` '
            .'ADD INDEX `address_book_peers_unique_book_rustdesk_id` (`rustdesk_id`)'
        );

        try {
            try {
                $migration->up();
                $this->fail('The migration accepted a colliding peer-identity index.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString(
                    'does not exactly enforce UNIQUE (address_book_id, rustdesk_id)',
                    $exception->getMessage(),
                );
            }

            $this->assertTrue($this->indexExists());
        } finally {
            DB::statement(
                'ALTER TABLE `address_book_peers` '
                .'DROP INDEX `address_book_peers_unique_book_rustdesk_id`'
            );
            $migration->up();
        }
    }

    public function test_rollback_removes_only_the_index_and_preserves_peer_rows(): void
    {
        $migration = $this->migration();
        $owner = $this->user('peer-rollback-owner');
        $book = $this->book($owner, 'Rollback book');
        $first = $this->peer($book, $owner, 'rollback-id', 'First');
        $second = null;

        try {
            $migration->down();

            $this->assertFalse($this->indexExists());
            $this->assertDatabaseHas('address_book_peers', ['id' => $first, 'alias' => 'First']);
            $second = $this->peer($book, $owner, 'rollback-id', 'Second');
            $this->assertSame(
                2,
                DB::table('address_book_peers')
                    ->where('address_book_id', $book)
                    ->where('rustdesk_id', 'rollback-id')
                    ->count(),
            );
        } finally {
            DB::table('address_book_peers')->whereIn('id', array_filter([$first, $second]))->delete();
            DB::table('address_books')->where('id', $book)->delete();
            DB::table('users')->where('id', $owner)->delete();
            $migration->up();
        }
    }

    private function migration(): object
    {
        return require database_path(
            'migrations/2026_07_15_100004_enforce_address_book_peer_identity.php'
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

    private function book(int $owner, string $name): int
    {
        return (int) DB::table('address_books')->insertGetId([
            'user_id' => $owner,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function peer(int $book, int $owner, string $rustdeskId, string $alias): int
    {
        return (int) DB::table('address_book_peers')->insertGetId([
            'address_book_id' => $book,
            'user_id' => $owner,
            'rustdesk_id' => $rustdeskId,
            'alias' => $alias,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function indexExists(): bool
    {
        $result = DB::selectOne(<<<'SQL'
            SELECT COUNT(*) AS `aggregate`
            FROM `information_schema`.`STATISTICS`
            WHERE `TABLE_SCHEMA` = DATABASE()
                AND `TABLE_NAME` = 'address_book_peers'
                AND `INDEX_NAME` = 'address_book_peers_unique_book_rustdesk_id'
            SQL);

        return is_object($result) && (int) ($result->aggregate ?? 0) > 0;
    }
}
