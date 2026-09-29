<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stop storing bearer credentials in plaintext.
 *
 * - Client bearer tokens (`auth_tokens`) and deploy tokens (`deploy_tokens`) are replaced by their
 *   SHA-256 digest (`token_hash`). Every existing row is hashed in place, so tokens that clients and
 *   rollout scripts already hold keep working; nobody is signed out.
 * - Pending OIDC device logins (`oauth_sessions`) are keyed by the digest of the polling code, and a
 *   not-yet-delivered AuthBody is encrypted with the application key.
 *
 * Irreversible for credentials: rolling back restores the columns but not the plaintext values, so
 * a downgrade signs every client out and invalidates every deploy token.
 */
return new class extends Migration
{
    private const TOKEN_TABLES = ['auth_tokens', 'deploy_tokens'];

    public function up(): void
    {
        foreach (self::TOKEN_TABLES as $table) {
            $this->hashTokens($table);
        }

        $this->hashPendingOidcSessions();
    }

    public function down(): void
    {
        foreach (self::TOKEN_TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('token')->nullable()->after('user_id');
            });
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->unique('token', $table.'_token_unique');
                $blueprint->dropUnique($table.'_token_hash_unique');
                $blueprint->dropColumn('token_hash');
            });
        }

        // Pending device logins live for five minutes; discard them instead of reversing digests.
        DB::table('oauth_sessions')->delete();
        Schema::table('oauth_sessions', function (Blueprint $table): void {
            $table->json('auth_body')->nullable()->change();
        });
    }

    private function hashTokens(string $table): void
    {
        if (! Schema::hasColumn($table, 'token_hash')) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->char('token_hash', 64)->nullable()->after('token');
            });
        }

        DB::table($table)
            ->select(['id', 'token'])
            ->whereNull('token_hash')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table): void {
                foreach ($rows as $row) {
                    DB::table($table)->where('id', $row->id)->update([
                        'token_hash' => hash('sha256', (string) $row->token),
                    ]);
                }
            });

        Schema::table($table, function (Blueprint $blueprint) use ($table): void {
            $blueprint->unique('token_hash', $table.'_token_hash_unique');
            $blueprint->dropUnique($table.'_token_unique');
            $blueprint->dropColumn('token');
        });
    }

    private function hashPendingOidcSessions(): void
    {
        // A JSON column only accepts JSON; ciphertext needs a plain text column.
        Schema::table('oauth_sessions', function (Blueprint $table): void {
            $table->longText('auth_body')->nullable()->change();
        });

        DB::table('oauth_sessions')
            ->select(['code', 'auth_body'])
            ->orderBy('code')
            ->get()
            ->each(function ($row): void {
                $code = (string) $row->code;
                $updates = [];

                // Polling codes are 32 characters; digests are 64 hex characters.
                if (preg_match('/\A[0-9a-f]{64}\z/', $code) !== 1) {
                    $updates['code'] = hash('sha256', $code);
                }

                if (is_string($row->auth_body) && $row->auth_body !== '') {
                    try {
                        Crypt::decryptString($row->auth_body);
                    } catch (DecryptException) {
                        $updates['auth_body'] = Crypt::encryptString($row->auth_body);
                    }
                }

                if ($updates !== []) {
                    DB::table('oauth_sessions')->where('code', $code)->update($updates);
                }
            });
    }
};
