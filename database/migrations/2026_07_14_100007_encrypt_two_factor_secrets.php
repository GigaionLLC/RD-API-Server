<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Encrypt every legacy TOTP secret before the model begins decrypting the column.
     * The rewrite is idempotent so an interrupted deployment can safely retry it.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('two_factor_secret')->nullable()->change();
        });

        $this->assertEverySecretIsReadable();

        $this->eachSecretRow(function (int $userId, string $stored): void {
            try {
                $plaintext = Crypt::decryptString($stored);
            } catch (DecryptException) {
                if (! $this->isValidPlaintext($stored)) {
                    throw new RuntimeException(
                        "User {$userId} has an invalid or undecryptable TOTP secret."
                    );
                }

                $this->writeSecret($userId, Crypt::encryptString($stored));

                return;
            }

            if (! $this->isValidPlaintext($plaintext)) {
                throw new RuntimeException(
                    "User {$userId} has an encrypted value that is not a valid TOTP secret."
                );
            }
        });
    }

    /**
     * Restore plaintext only while the current or a configured previous application key can
     * decrypt every value. Unknown/corrupt ciphertext aborts the rollback instead of losing it.
     */
    public function down(): void
    {
        // Validate the complete set before decrypting the first row. A corrupt or unknown-key
        // value must not leave an interrupted rollback with a plaintext/ciphertext mixture.
        $this->assertEverySecretIsReadable();

        $this->eachSecretRow(function (int $userId, string $stored): void {
            try {
                $plaintext = Crypt::decryptString($stored);
            } catch (DecryptException) {
                if ($this->isValidPlaintext($stored)) {
                    return;
                }

                throw new RuntimeException(
                    "User {$userId} has an invalid or undecryptable TOTP secret."
                );
            }

            if (! $this->isValidPlaintext($plaintext)) {
                throw new RuntimeException(
                    "User {$userId} has an encrypted value that is not a valid TOTP secret."
                );
            }

            $this->writeSecret($userId, $plaintext);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('two_factor_secret', 255)->nullable()->change();
        });
    }

    /**
     * @param  callable(int, string): void  $callback
     */
    private function eachSecretRow(callable $callback): void
    {
        DB::table('users')
            ->select('id')
            ->whereNotNull('two_factor_secret')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($callback): void {
                foreach ($rows as $row) {
                    $userId = (int) $row->id;

                    DB::transaction(function () use ($callback, $userId): void {
                        $stored = DB::table('users')
                            ->where('id', $userId)
                            ->lockForUpdate()
                            ->value('two_factor_secret');

                        if (is_string($stored)) {
                            $callback($userId, $stored);
                        }
                    });
                }
            });
    }

    private function writeSecret(int $userId, string $secret): void
    {
        DB::table('users')->where('id', $userId)->update([
            'two_factor_secret' => $secret,
        ]);
    }

    /**
     * Preflight every row before either direction mutates data. The deployment is required to
     * quiesce old replicas, so a complete validation pass also prevents partial rewrites.
     */
    private function assertEverySecretIsReadable(): void
    {
        $this->eachSecretRow(function (int $userId, string $stored): void {
            try {
                $plaintext = Crypt::decryptString($stored);
            } catch (DecryptException) {
                if ($this->isValidPlaintext($stored)) {
                    return;
                }

                throw new RuntimeException(
                    "User {$userId} has an invalid or undecryptable TOTP secret."
                );
            }

            if (! $this->isValidPlaintext($plaintext)) {
                throw new RuntimeException(
                    "User {$userId} has an encrypted value that is not a valid TOTP secret."
                );
            }
        });
    }

    /**
     * Frozen legacy format validation: migrations must not depend on mutable application code.
     */
    private function isValidPlaintext(string $secret): bool
    {
        if (strlen($secret) > 255) {
            return false;
        }

        $normalized = preg_replace('/\s+/', '', $secret);

        return is_string($normalized)
            && strlen($normalized) >= 16
            && preg_match('/\A[A-Z2-7]+={0,6}\z/i', $normalized) === 1;
    }
};
