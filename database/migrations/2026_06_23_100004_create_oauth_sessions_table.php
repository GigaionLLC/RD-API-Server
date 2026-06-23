<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pending OIDC/OAuth device-login sessions for the RustDesk client poll flow. Persisted in the
 * database (not the cache) so the provider callback and the client's auth-query poll resolve to
 * the same session even across multiple API instances / workers behind a load balancer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_sessions', function (Blueprint $table): void {
            // `code` is the polling code the client echoes back (also the provider `state`).
            $table->string('code', 64)->primary();
            $table->string('op');
            $table->string('rustdesk_id')->nullable();
            $table->string('uuid')->nullable();
            $table->string('nonce')->nullable();
            $table->string('code_verifier')->nullable();
            $table->string('device_os')->nullable();
            $table->string('device_type')->nullable();
            $table->string('device_name')->nullable();
            // The issued AuthBody once the callback resolves the user (null while pending).
            $table->json('auth_body')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_sessions');
    }
};
