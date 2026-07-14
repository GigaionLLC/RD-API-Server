<?php

use App\Support\RecoveryCodeProtector;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Replace every valid legacy plaintext recovery code with a versioned keyed digest.
     * The rewrite is idempotent so an interrupted deployment can safely retry it.
     */
    public function up(): void
    {
        $protector = app(RecoveryCodeProtector::class);

        $this->eachRecoveryCodeRow(function (int $userId, mixed $raw) use ($protector): void {
            $codes = $this->decodeCodes($raw);
            if ($codes === null) {
                $this->writeCodes($userId, null);

                return;
            }

            $protected = [];
            foreach ($codes as $code) {
                $value = $protector->protectStored($code);
                if ($value !== null) {
                    $protected[] = $value;
                }
            }

            $this->writeCodes($userId, $protected);
        });
    }

    /**
     * Keyed digests are intentionally irreversible. On rollback, invalidate affected recovery
     * lists rather than letting the old application mistake a digest for a usable code.
     */
    public function down(): void
    {
        $protector = app(RecoveryCodeProtector::class);

        $this->eachRecoveryCodeRow(function (int $userId, mixed $raw) use ($protector): void {
            $codes = $this->decodeCodes($raw);
            if ($codes === null || array_any($codes, $protector->isProtected(...))) {
                $this->writeCodes($userId, null);
            }
        });
    }

    /**
     * @param  callable(int, mixed): void  $callback
     */
    private function eachRecoveryCodeRow(callable $callback): void
    {
        DB::table('users')
            ->select('id')
            ->whereNotNull('two_factor_recovery_codes')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($callback): void {
                foreach ($rows as $row) {
                    $userId = (int) $row->id;

                    DB::transaction(function () use ($callback, $userId): void {
                        $raw = DB::table('users')
                            ->where('id', $userId)
                            ->lockForUpdate()
                            ->value('two_factor_recovery_codes');

                        if ($raw !== null) {
                            $callback($userId, $raw);
                        }
                    });
                }
            });
    }

    /**
     * @return list<string>|null
     */
    private function decodeCodes(mixed $raw): ?array
    {
        if (is_string($raw)) {
            try {
                $raw = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return null;
            }
        }

        if (! is_array($raw) || ! array_is_list($raw)) {
            return null;
        }

        return array_values(array_filter(
            $raw,
            static fn (mixed $code): bool => is_string($code),
        ));
    }

    /**
     * @param  list<string>|null  $codes
     */
    private function writeCodes(int $userId, ?array $codes): void
    {
        DB::table('users')->where('id', $userId)->update([
            'two_factor_recovery_codes' => $codes === null
                ? null
                : json_encode($codes, JSON_THROW_ON_ERROR),
        ]);
    }
};
