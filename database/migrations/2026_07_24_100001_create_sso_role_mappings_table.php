<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Declares that membership of an identity-provider group grants a console admin role.
     *
     * Mappings are scoped to one provider. Duplicate group names across providers are certain
     * (every directory has an "admins"), so a bare group string would be a cross-provider
     * confusion bug. `provider_key` is `oauth_providers.op` for OIDC and the LDAP identity
     * namespace for LDAP, which already fails closed into a new namespace when the directory
     * configuration changes.
     */
    public function up(): void
    {
        Schema::create('sso_role_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('provider_kind', 16);
            $table->string('provider_key', 191);
            // The operator's input, kept verbatim so the console shows exactly what they typed.
            $table->string('group_value', 512);
            // Comparison key: a digest of the normalized group value. Group DNs are longer than
            // an indexable column, and matching must be exact, so the digest is both the index
            // and the equality test. Nothing ever matches on group_value.
            $table->char('group_key', 64);
            $table->unsignedBigInteger('admin_role_id')->index();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['provider_kind', 'provider_key']);
            $table->unique(
                ['provider_kind', 'provider_key', 'group_key', 'admin_role_id'],
                'sso_role_mappings_scope_unique'
            );

            // A deleted role must not leave a mapping pointing at nothing.
            $table->foreign('admin_role_id')->references('id')->on('admin_roles')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_role_mappings');
    }
};
