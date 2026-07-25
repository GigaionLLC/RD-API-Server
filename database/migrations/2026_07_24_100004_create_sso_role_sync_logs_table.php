<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Structured record of every federated authority change, and every reason one was skipped.
     *
     * The console audit log cannot express this: it records `method + path` for console writes
     * only, explicitly skips the login route, and never sees the client API at all. Yet this is
     * the one place where who-can-do-what changes silently, at login time, driven by a string an
     * external system asserts. A skip is recorded as deliberately as a change, because "nothing
     * happened" and "the provider returned nothing" are the failure this feature is most likely
     * to produce.
     */
    public function up(): void
    {
        Schema::create('sso_role_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            // Kept verbatim so attribution survives a later rename or account deletion.
            $table->string('username')->default('');
            $table->string('provider_kind', 16);
            $table->string('provider_key', 191);
            $table->string('channel', 32);
            $table->string('outcome', 32)->index();
            // Role names as well as ids, because a role can be deleted afterwards.
            $table->json('granted')->nullable();
            $table->json('revoked')->nullable();
            $table->json('matched_groups')->nullable();
            $table->unsignedInteger('groups_seen')->default(0);
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['provider_kind', 'provider_key']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_role_sync_logs');
    }
};
