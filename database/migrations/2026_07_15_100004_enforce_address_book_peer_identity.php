<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'address_book_peers_unique_book_rustdesk_id';

    private const REPORTED_PAIR_LIMIT = 20;

    /**
     * Reject ambiguous historical rows, then make peer identity durable in MariaDB.
     */
    public function up(): void
    {
        if ($this->validIndexExists()) {
            return;
        }

        $this->assertNoDuplicatePeerPairs();

        DB::statement(<<<'SQL'
            ALTER TABLE `address_book_peers`
            ADD UNIQUE INDEX `address_book_peers_unique_book_rustdesk_id`
                (`address_book_id`, `rustdesk_id`)
            SQL);
    }

    /**
     * Rollback removes only enforcement and preserves every peer row.
     */
    public function down(): void
    {
        if ($this->validIndexExists()) {
            DB::statement(
                'ALTER TABLE `address_book_peers` DROP INDEX `'.self::UNIQUE_INDEX.'`'
            );
        }
    }

    private function assertNoDuplicatePeerPairs(): void
    {
        $groups = DB::table('address_book_peers')
            ->select('address_book_id', 'rustdesk_id')
            ->groupBy('address_book_id', 'rustdesk_id')
            ->havingRaw('COUNT(*) > 1');
        $duplicates = DB::query()->fromSub($groups, 'duplicate_peer_pairs');
        $count = (clone $duplicates)->count();

        if ($count === 0) {
            return;
        }

        $pairs = (clone $duplicates)
            ->orderBy('address_book_id')
            ->orderBy('rustdesk_id')
            ->limit(self::REPORTED_PAIR_LIMIT)
            ->get()
            ->map(static function (object $row): string {
                $encodedId = json_encode(
                    (string) ($row->rustdesk_id ?? ''),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                );

                return (string) ($row->address_book_id ?? '').':'
                    .(is_string($encodedId) ? $encodedId : '"<unprintable>"');
            })
            ->implode(', ');

        throw new RuntimeException(
            "Cannot enforce address-book peer identity: {$count} duplicate (book, RustDesk ID) "
            .'pair(s) exist. Conflicting pairs (up to '.self::REPORTED_PAIR_LIMIT."): {$pairs}. "
            .'Merge or remove the unintended duplicate rows explicitly, quiesce legacy writers, '
            .'and retry the migration.'
        );
    }

    /**
     * Read and validate once so a colliding named object cannot appear between checks.
     */
    private function validIndexExists(): bool
    {
        $rows = $this->indexRows();
        if ($rows === []) {
            return false;
        }

        $valid = count($rows) === 2
            && (int) ($rows[0]->NON_UNIQUE ?? 1) === 0
            && (int) ($rows[0]->SEQ_IN_INDEX ?? 0) === 1
            && (string) ($rows[0]->COLUMN_NAME ?? '') === 'address_book_id'
            && ($rows[0]->SUB_PART ?? null) === null
            && (int) ($rows[1]->NON_UNIQUE ?? 1) === 0
            && (int) ($rows[1]->SEQ_IN_INDEX ?? 0) === 2
            && (string) ($rows[1]->COLUMN_NAME ?? '') === 'rustdesk_id'
            && ($rows[1]->SUB_PART ?? null) === null;

        if (! $valid) {
            throw new RuntimeException(
                'Cannot enforce address-book peer identity: the existing '.self::UNIQUE_INDEX
                .' index does not exactly enforce UNIQUE (address_book_id, rustdesk_id). '
                .'Resolve the schema collision before retrying.'
            );
        }

        return true;
    }

    /**
     * @return list<object>
     */
    private function indexRows(): array
    {
        return DB::select(<<<'SQL'
            SELECT `NON_UNIQUE`, `SEQ_IN_INDEX`, `COLUMN_NAME`, `SUB_PART`
            FROM `information_schema`.`STATISTICS`
            WHERE `TABLE_SCHEMA` = DATABASE()
                AND `TABLE_NAME` = 'address_book_peers'
                AND `INDEX_NAME` = ?
            ORDER BY `SEQ_IN_INDEX`
            SQL, [self::UNIQUE_INDEX]);
    }
};
