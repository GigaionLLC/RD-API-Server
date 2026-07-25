<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records where a role assignment came from.
     *
     * Without provenance the two possible sync models are both wrong: purely additive grants are
     * never revoked when a user leaves a group, and a fully authoritative sync destroys every
     * hand-assigned role at the next login. Marking each grant lets a login reconcile only the
     * rows its own provider owns and leave everything else untouched.
     *
     * Existing rows predate federation and are therefore manual by definition.
     */
    public function up(): void
    {
        Schema::table('admin_role_user', function (Blueprint $table) {
            $table->string('origin', 191)->default('manual')->after('user_id');
            $table->unsignedBigInteger('sso_role_mapping_id')->nullable()->after('origin');

            $table->index(['user_id', 'origin']);
            $table->index('sso_role_mapping_id');
        });
    }

    public function down(): void
    {
        Schema::table('admin_role_user', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'origin']);
            $table->dropIndex(['sso_role_mapping_id']);
            $table->dropColumn(['origin', 'sso_role_mapping_id']);
        });
    }
};
