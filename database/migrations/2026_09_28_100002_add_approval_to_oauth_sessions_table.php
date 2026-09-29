<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OIDC device sign-in approval step. After the provider callback resolves the account, the
 * session waits for the account holder to approve the device on a confirmation page; only then is
 * a bearer token issued. Only SHA-256 digests of the one-time page nonce and the browser-binding
 * cookie are stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_sessions', function (Blueprint $table): void {
            $table->string('request_ip', 45)->nullable()->after('device_name');
            $table->unsignedBigInteger('user_id')->nullable()->after('request_ip');
            $table->char('confirm_hash', 64)->nullable()->after('user_id');
            $table->char('browser_hash', 64)->nullable()->after('confirm_hash');
            $table->timestamp('resolved_at')->nullable()->after('browser_hash');
        });
    }

    public function down(): void
    {
        Schema::table('oauth_sessions', function (Blueprint $table): void {
            $table->dropColumn(['request_ip', 'user_id', 'confirm_hash', 'browser_hash', 'resolved_at']);
        });
    }
};
