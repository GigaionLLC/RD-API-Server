<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The userinfo claim that carries group membership for this provider.
     *
     * There is no agreed claim name across identity providers, and several emit nothing unless
     * the operator adds a mapper. Empty means this provider contributes no groups, which is the
     * default so an upgrade grants nobody anything.
     *
     * Dot notation addresses a nested claim, e.g. `realm_access.roles`.
     */
    public function up(): void
    {
        Schema::table('oauth_providers', function (Blueprint $table) {
            $table->string('groups_claim', 191)->default('')->after('scopes');
        });
    }

    public function down(): void
    {
        Schema::table('oauth_providers', function (Blueprint $table) {
            $table->dropColumn('groups_claim');
        });
    }
};
