<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Designates one account as the break-glass administrator.
     *
     * The flag grants no permission of its own. It is a mutation shield: the marked account cannot
     * be demoted, deleted, disabled, forced to SSO, demoted by a directory, or linked to an
     * external identity, so a wrong or unreachable identity provider can never remove the last way
     * back into the console.
     *
     * The uniqueness is a database fact rather than an application convention. A stored generated
     * column collapses every unprotected row to NULL, which a unique index ignores, so at most one
     * protected account can exist even under concurrent writes.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_protected_admin')->default(false)->after('is_admin');
            $table->tinyInteger('protected_admin_slot')
                ->nullable()
                ->storedAs('CASE WHEN is_protected_admin THEN 1 ELSE NULL END')
                ->after('is_protected_admin');

            $table->unique('protected_admin_slot');
        });

        $this->protectExistingAdministrator();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['protected_admin_slot']);
            $table->dropColumn(['protected_admin_slot', 'is_protected_admin']);
        });
    }

    /**
     * Adopt the most plausible existing administrator, or none at all.
     *
     * Only an account that could actually be used for recovery qualifies: a full administrator with
     * no federated identity, since a linked account cannot receive a local password. Guessing wrong
     * would silently protect an account the operator never chose, so when nothing qualifies this
     * says so and leaves the decision to them.
     */
    private function protectExistingAdministrator(): void
    {
        $candidate = DB::table('users')
            ->where('is_admin', true)
            ->whereNotIn('id', fn ($query) => $query->select('user_id')->from('ldap_identities'))
            ->whereNotIn('id', fn ($query) => $query->select('user_id')->from('user_thirds'))
            ->orderBy('id')
            ->value('id');

        if ($candidate === null) {
            Log::warning(
                'No break-glass administrator was designated: this installation has no full '
                .'administrator without a linked LDAP or SSO identity. Designate one with '
                .'`php artisan rustdesk:admin:protect <username>`.'
            );

            return;
        }

        DB::table('users')->where('id', $candidate)->update(['is_protected_admin' => true]);
    }
};
