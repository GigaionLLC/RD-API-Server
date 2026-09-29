<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypt address-book peer connection secrets (`password`, `hash`) with the application key.
 *
 * The client API keeps returning exactly the same plaintext values (the model decrypts them); a
 * database copy without APP_KEY no longer yields peer passwords or password-equivalent hashes.
 * The rewrite is idempotent, and it can be rolled back while the key is still available.
 */
return new class extends Migration
{
    private const COLUMNS = ['password', 'hash'];

    public function up(): void
    {
        Schema::table('address_book_peers', function (Blueprint $table): void {
            $table->text('password')->nullable()->change();
            $table->text('hash')->nullable()->change();
        });

        $this->eachRow(function (object $row): array {
            $updates = [];
            foreach (self::COLUMNS as $column) {
                $value = $row->{$column};
                if (is_string($value) && ! $this->isCiphertext($value)) {
                    $updates[$column] = Crypt::encryptString($value);
                }
            }

            return $updates;
        });
    }

    public function down(): void
    {
        $this->eachRow(function (object $row): array {
            $updates = [];
            foreach (self::COLUMNS as $column) {
                $value = $row->{$column};
                if (is_string($value) && $this->isCiphertext($value)) {
                    $updates[$column] = Crypt::decryptString($value);
                }
            }

            return $updates;
        });

        Schema::table('address_book_peers', function (Blueprint $table): void {
            $table->string('password')->nullable()->change();
            $table->string('hash')->nullable()->change();
        });
    }

    /**
     * @param  callable(object): array<string, string>  $transform
     */
    private function eachRow(callable $transform): void
    {
        DB::table('address_book_peers')
            ->select(['id', 'password', 'hash'])
            ->where(fn ($query) => $query->whereNotNull('password')->orWhereNotNull('hash'))
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($transform): void {
                foreach ($rows as $row) {
                    $updates = $transform($row);
                    if ($updates !== []) {
                        DB::table('address_book_peers')->where('id', $row->id)->update($updates);
                    }
                }
            });
    }

    private function isCiphertext(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
