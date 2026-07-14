<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bind every email-login verification code to an opaque, single-use challenge. The secret
 * returned to the client is never stored directly; its SHA-256 digest is the indexed lookup key.
 * A row-local failure budget protects the six-digit code even when an attacker rotates source IPs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('verify_codes', function (Blueprint $table): void {
            $table->string('challenge_hash', 64)->nullable()->unique()->after('uuid');
            $table->unsignedTinyInteger('failed_attempts')->default(0)->after('code');
            $table->unsignedTinyInteger('max_attempts')->default(5)->after('failed_attempts');
            $table->timestamp('consumed_at')->nullable()->after('expires_at');
        });

        // Pre-migration rows have no challenge secret and can never pass the new verifier.
        // Explicitly retire them and erase their formerly plaintext six-digit codes.
        DB::table('verify_codes')->whereNull('challenge_hash')->update([
            'code' => null,
            'status' => 0,
        ]);
    }

    public function down(): void
    {
        // A rollback also invalidates challenges issued under the hashed protocol. The legacy
        // verifier cannot consume their password hashes, so users must start a fresh attempt.
        DB::table('verify_codes')->whereNotNull('challenge_hash')->update([
            'code' => null,
            'status' => 0,
        ]);

        Schema::table('verify_codes', function (Blueprint $table): void {
            $table->dropUnique(['challenge_hash']);
            $table->dropColumn([
                'challenge_hash',
                'failed_attempts',
                'max_attempts',
                'consumed_at',
            ]);
        });
    }
};
