<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHECK_CONSTRAINT = 'address_books_personal_marker_valid';

    private const UNIQUE_INDEX = 'address_books_one_personal_per_user';

    private const PERSONAL_NAME = 'My address book';

    private const REPORTED_ID_LIMIT = 20;

    /**
     * Give each owner at most one durable personal-book identity.
     */
    public function up(): void
    {
        $this->assertExistingSchemaDefinitionsAreValid();
        $this->assertExistingMarkersAreValid();

        if (! Schema::hasColumn('address_books', 'is_personal')) {
            // Install the marker and both enforcement objects in one ALTER. New application
            // code can therefore never observe the column without its concurrency boundary.
            DB::statement(<<<'SQL'
                ALTER TABLE `address_books`
                ADD COLUMN `is_personal` TINYINT(1) NULL DEFAULT NULL AFTER `user_id`,
                ADD CONSTRAINT `address_books_personal_marker_valid`
                CHECK (
                    `is_personal` IS NULL
                    OR (`is_personal` = 1 AND `user_id` IS NOT NULL)
                ),
                ADD UNIQUE INDEX `address_books_one_personal_per_user` (`user_id`, `is_personal`)
                SQL);
        } else {
            $this->addMissingEnforcement();
        }

        $this->backfillLegacyPersonalBooks();
    }

    /**
     * Rollback removes only the marker and its enforcement; every address book remains intact.
     */
    public function down(): void
    {
        $this->assertExistingSchemaDefinitionsAreValid();
        $this->assertExistingMarkersAreValid();
        $this->assertRollbackPreservesLegacyIdentity();

        if ($this->indexExists()) {
            DB::statement(
                'ALTER TABLE `address_books` DROP INDEX `'.self::UNIQUE_INDEX.'`'
            );
        }

        if ($this->checkConstraintExists()) {
            DB::statement(
                'ALTER TABLE `address_books` DROP CONSTRAINT `'.self::CHECK_CONSTRAINT.'`'
            );
        }

        if (Schema::hasColumn('address_books', 'is_personal')) {
            DB::statement('ALTER TABLE `address_books` DROP COLUMN `is_personal`');
        }
    }

    /**
     * Complete only the missing objects from a valid interrupted migration, before backfill.
     */
    private function addMissingEnforcement(): void
    {
        $clauses = [];
        if (! $this->checkConstraintExists()) {
            $clauses[] = <<<'SQL'
                ADD CONSTRAINT `address_books_personal_marker_valid`
                CHECK (
                    `is_personal` IS NULL
                    OR (`is_personal` = 1 AND `user_id` IS NOT NULL)
                )
                SQL;
        }
        if (! $this->indexExists()) {
            $clauses[] = <<<'SQL'
                ADD UNIQUE INDEX `address_books_one_personal_per_user` (`user_id`, `is_personal`)
                SQL;
        }

        if ($clauses !== []) {
            DB::statement('ALTER TABLE `address_books` '.implode(', ', $clauses));
        }
    }

    /**
     * Never mistake a colliding manual object for enforcement installed by this migration.
     */
    private function assertExistingSchemaDefinitionsAreValid(): void
    {
        $column = DB::selectOne(<<<'SQL'
            SELECT
                `DATA_TYPE`,
                `COLUMN_TYPE`,
                `IS_NULLABLE`,
                `COLUMN_DEFAULT`,
                `EXTRA`
            FROM `information_schema`.`COLUMNS`
            WHERE `TABLE_SCHEMA` = DATABASE()
                AND `TABLE_NAME` = 'address_books'
                AND `COLUMN_NAME` = 'is_personal'
            SQL);

        if (is_object($column)) {
            $columnDefault = $column->COLUMN_DEFAULT ?? null;
            $columnIsValid = strtolower((string) ($column->DATA_TYPE ?? '')) === 'tinyint'
                && strtolower((string) ($column->COLUMN_TYPE ?? '')) === 'tinyint(1)'
                && strtoupper((string) ($column->IS_NULLABLE ?? '')) === 'YES'
                && ($columnDefault === null || strtoupper((string) $columnDefault) === 'NULL')
                && (string) ($column->EXTRA ?? '') === '';

            if (! $columnIsValid) {
                throw new RuntimeException(
                    'Cannot enforce the personal address-book invariant: the existing '
                    .'address_books.is_personal column does not match the expected nullable '
                    .'TINYINT(1) definition. Resolve the schema collision before retrying.'
                );
            }
        }

        $indexRows = $this->indexRows();
        if ($indexRows !== []) {
            $indexIsValid = count($indexRows) === 2
                && (int) ($indexRows[0]->NON_UNIQUE ?? 1) === 0
                && (int) ($indexRows[0]->SEQ_IN_INDEX ?? 0) === 1
                && (string) ($indexRows[0]->COLUMN_NAME ?? '') === 'user_id'
                && ($indexRows[0]->SUB_PART ?? null) === null
                && (int) ($indexRows[1]->NON_UNIQUE ?? 1) === 0
                && (int) ($indexRows[1]->SEQ_IN_INDEX ?? 0) === 2
                && (string) ($indexRows[1]->COLUMN_NAME ?? '') === 'is_personal'
                && ($indexRows[1]->SUB_PART ?? null) === null;

            if (! $indexIsValid) {
                throw new RuntimeException(
                    'Cannot enforce the personal address-book invariant: the existing '
                    .self::UNIQUE_INDEX.' index does not exactly enforce UNIQUE '
                    .'(user_id, is_personal). Resolve the schema collision before retrying.'
                );
            }
        }

        $check = $this->checkConstraintDefinition();
        if (is_object($check)) {
            $expectedClause = 'is_personalisnulloris_personal=1anduser_idisnotnull';
            $actualClause = $this->normalizeCheckClause((string) ($check->CHECK_CLAUSE ?? ''));
            $checkIsValid = strtoupper((string) ($check->CONSTRAINT_TYPE ?? '')) === 'CHECK'
                && $actualClause === $expectedClause;

            if (! $checkIsValid) {
                throw new RuntimeException(
                    'Cannot enforce the personal address-book invariant: the existing '
                    .self::CHECK_CONSTRAINT.' constraint does not match the expected marker '
                    .'validation. Resolve the schema collision before retrying.'
                );
            }
        }
    }

    /**
     * A partially applied/manual marker must be safe before this migration changes schema/data.
     */
    private function assertExistingMarkersAreValid(): void
    {
        if (! Schema::hasColumn('address_books', 'is_personal')) {
            return;
        }

        $invalidMarkers = DB::table('address_books')
            ->whereNotNull('is_personal')
            ->where(function (Builder $query): void {
                $query->where('is_personal', '<>', 1)->orWhereNull('user_id');
            });
        $invalidCount = (clone $invalidMarkers)->count();

        if ($invalidCount > 0) {
            $ids = (clone $invalidMarkers)
                ->orderBy('id')
                ->limit(self::REPORTED_ID_LIMIT)
                ->pluck('id')
                ->map(static fn ($id): string => (string) $id)
                ->implode(', ');

            throw new RuntimeException(
                "Cannot enforce the personal address-book invariant: {$invalidCount} invalid "
                .'marker row(s) exist. A marker must be NULL, or 1 with an owner. '
                .'Affected address-book IDs (up to '.self::REPORTED_ID_LIMIT."): {$ids}."
            );
        }

        $duplicateOwners = DB::query()->fromSub(
            DB::table('address_books')
                ->select('user_id')
                ->where('is_personal', 1)
                ->groupBy('user_id')
                ->havingRaw('COUNT(*) > 1'),
            'duplicate_personal_owners',
        );
        $duplicateCount = (clone $duplicateOwners)->count();

        if ($duplicateCount > 0) {
            $ownerIds = (clone $duplicateOwners)
                ->orderBy('user_id')
                ->limit(self::REPORTED_ID_LIMIT)
                ->pluck('user_id')
                ->map(static fn ($id): string => (string) $id)
                ->implode(', ');

            throw new RuntimeException(
                "Cannot enforce the personal address-book invariant: {$duplicateCount} owner(s) "
                .'already have multiple personal markers. Affected owner IDs (up to '
                .self::REPORTED_ID_LIMIT."): {$ownerIds}."
            );
        }
    }

    /**
     * Old code identifies the personal book by name. Refuse rollback when that resolver would
     * select a different row or fail to find the currently marked collection.
     */
    private function assertRollbackPreservesLegacyIdentity(): void
    {
        if (! Schema::hasColumn('address_books', 'is_personal')) {
            return;
        }

        $unsafeMarkers = DB::table('address_books as personal')
            ->where('personal.is_personal', 1)
            ->where(function (Builder $query): void {
                $query
                    ->where('personal.name', '<>', self::PERSONAL_NAME)
                    ->orWhereExists(function (Builder $collision): void {
                        $collision
                            ->selectRaw('1')
                            ->from('address_books as same_name')
                            ->whereColumn('same_name.user_id', 'personal.user_id')
                            ->whereColumn('same_name.id', '<>', 'personal.id')
                            ->where('same_name.name', self::PERSONAL_NAME);
                    });
            });
        $unsafeCount = (clone $unsafeMarkers)->count();

        if ($unsafeCount === 0) {
            return;
        }

        $ids = (clone $unsafeMarkers)
            ->orderBy('personal.id')
            ->limit(self::REPORTED_ID_LIMIT)
            ->pluck('personal.id')
            ->map(static fn ($id): string => (string) $id)
            ->implode(', ');

        throw new RuntimeException(
            "Cannot roll back the personal address-book invariant: {$unsafeCount} marked "
            .'book(s) cannot be represented unambiguously by the previous name-based resolver. '
            .'Affected personal address-book IDs (up to '.self::REPORTED_ID_LIMIT."): {$ids}. "
            .'Rename or remove the colliding ordinary book, or restore the marked book name, '
            .'before retrying the quiesced rollback.'
        );
    }

    /**
     * Preserve all legacy books and mark only the lowest matching ID for an unmarked owner.
     */
    private function backfillLegacyPersonalBooks(): void
    {
        DB::update(<<<'SQL'
            UPDATE `address_books` AS `candidate`
            INNER JOIN (
                SELECT `eligible`.`user_id`, MIN(`eligible`.`id`) AS `id`
                FROM (
                    SELECT `named`.`id`, `named`.`user_id`
                    FROM `address_books` AS `named`
                    WHERE `named`.`user_id` IS NOT NULL
                        AND `named`.`name` = ?
                        AND NOT EXISTS (
                            SELECT 1
                            FROM `address_books` AS `marked`
                            WHERE `marked`.`user_id` = `named`.`user_id`
                                AND `marked`.`is_personal` = 1
                        )
                ) AS `eligible`
                GROUP BY `eligible`.`user_id`
            ) AS `canonical` ON `canonical`.`id` = `candidate`.`id`
            SET `candidate`.`is_personal` = 1
            SQL, [self::PERSONAL_NAME]);
    }

    private function checkConstraintExists(): bool
    {
        return is_object($this->checkConstraintDefinition());
    }

    private function indexExists(): bool
    {
        return $this->indexRows() !== [];
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
                AND `TABLE_NAME` = 'address_books'
                AND `INDEX_NAME` = ?
            ORDER BY `SEQ_IN_INDEX`
            SQL, [self::UNIQUE_INDEX]);
    }

    private function checkConstraintDefinition(): ?object
    {
        $result = DB::selectOne(<<<'SQL'
            SELECT `tc`.`CONSTRAINT_TYPE`, `cc`.`CHECK_CLAUSE`
            FROM `information_schema`.`TABLE_CONSTRAINTS` AS `tc`
            LEFT JOIN `information_schema`.`CHECK_CONSTRAINTS` AS `cc`
                ON `cc`.`CONSTRAINT_SCHEMA` = `tc`.`CONSTRAINT_SCHEMA`
                AND `cc`.`CONSTRAINT_NAME` = `tc`.`CONSTRAINT_NAME`
            WHERE `tc`.`CONSTRAINT_SCHEMA` = DATABASE()
                AND `tc`.`TABLE_NAME` = 'address_books'
                AND `tc`.`CONSTRAINT_NAME` = ?
            SQL, [self::CHECK_CONSTRAINT]);

        return is_object($result) ? $result : null;
    }

    private function normalizeCheckClause(string $clause): string
    {
        // MariaDB removes the redundant grouping from this migration's CHECK, but preserves
        // parentheses that change AND/OR precedence. Keep those meaningful groups distinct.
        $normalized = preg_replace('/[`\s]+/', '', strtolower($clause));

        return is_string($normalized) ? $normalized : '';
    }
};
