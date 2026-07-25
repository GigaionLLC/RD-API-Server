<?php

namespace App\Services;

use App\Models\AdminRole;
use App\Models\AuthToken;
use App\Models\SsoRoleMapping;
use App\Models\SsoRoleSyncLog;
use App\Models\User;
use App\Support\SsoGroupKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Translates identity-provider group membership into console admin roles.
 *
 * This is the only place that writes a federated grant, and it is invoked from all four login
 * paths (console LDAP, client LDAP, console OIDC, client OIDC) so a user's authority cannot
 * depend on which client they last signed in with.
 *
 * Three properties are load-bearing:
 *
 *  - It reconciles only grants its own provider owns. Manual assignments and grants from a
 *    different provider are never read, written, or deleted, so authoritative revocation and
 *    hand-assigned roles can coexist.
 *  - It never revokes on uncertainty. A provider that could not be asked, or that returned no
 *    group claim at all, leaves existing grants exactly as they are. Only an explicitly empty
 *    group list revokes, because only that is evidence of removal rather than absence of
 *    evidence.
 *  - It can never fail a login. Authentication has already succeeded by the time it runs, and a
 *    failure here is logged and audited rather than propagated.
 */
class SsoRoleSyncService
{
    /**
     * Reconcile from an LDAP authentication result.
     *
     * An incomplete directory answer (`groups_known` false) is passed through as unknown so a
     * truncated membership list can never look like a removal.
     *
     * @param  array{groups?: array<int, string>, groups_known?: bool, provider?: string}  $attrs
     */
    public function syncFromLdap(User $user, array $attrs, string $channel, ?string $ip = null): void
    {
        $known = (bool) ($attrs['groups_known'] ?? false);

        $this->sync(
            $user,
            SsoRoleMapping::KIND_LDAP,
            (string) ($attrs['provider'] ?? ''),
            $known ? array_values($attrs['groups'] ?? []) : null,
            $channel,
            $ip,
        );
    }

    /**
     * Reconcile one provider's grants for one user.
     *
     * @param  list<string>|null  $groups  the asserted group values, or null when the provider
     *                                     could not be asked and nothing should change
     */
    public function sync(
        User $user,
        string $providerKind,
        string $providerKey,
        ?array $groups,
        string $channel = 'unknown',
        ?string $ip = null,
    ): void {
        try {
            $this->reconcile($user, $providerKind, $providerKey, $groups, $channel, $ip);
        } catch (\Throwable $e) {
            // Authority resolution must never cost someone their session. Report loudly and
            // leave every existing grant untouched.
            Log::warning('SSO role sync failed', [
                'user_id' => $user->id,
                'provider_kind' => $providerKind,
                'provider_key' => $providerKey,
                'exception' => $e::class,
                'reason' => $e->getMessage(),
            ]);

            $this->record($user, $providerKind, $providerKey, $channel, SsoRoleSyncLog::OUTCOME_FAILED, [], [], [], 0, $ip);
        }
    }

    /**
     * @param  list<string>|null  $groups
     */
    private function reconcile(
        User $user,
        string $providerKind,
        string $providerKey,
        ?array $groups,
        string $channel,
        ?string $ip,
    ): void {
        if ($providerKey === '') {
            return;
        }

        // A full administrator is the break-glass path back into a console whose identity
        // provider is broken. No external system may alter that account.
        if ($user->is_admin) {
            if ($groups !== null) {
                $this->record($user, $providerKind, $providerKey, $channel, SsoRoleSyncLog::OUTCOME_SKIPPED_FULL_ADMIN, [], [], [], count($groups), $ip);
            }

            return;
        }

        $origin = SsoRoleMapping::originFor($providerKind, $providerKey);

        if ($groups === null) {
            // The directory or userinfo request failed, or the provider emits no group claim.
            // Absence of evidence is not evidence of removal, so nothing changes. Record it when
            // the operator has reason to expect something to happen -- the user already holds
            // grants, or a mapping exists for this provider -- because a silent no-op is the
            // failure this feature is most likely to produce and the hardest to diagnose.
            $held = $this->heldGrants($user, $origin);
            $configured = SsoRoleMapping::query()
                ->forProvider($providerKind, $providerKey)
                ->where('enabled', true)
                ->exists();

            if ($held !== [] || $configured) {
                $this->record(
                    $user,
                    $providerKind,
                    $providerKey,
                    $channel,
                    $held !== [] ? SsoRoleSyncLog::OUTCOME_PROVIDER_ERROR : SsoRoleSyncLog::OUTCOME_NO_CLAIM,
                    [], [], [], 0, $ip,
                );
            }

            return;
        }

        $limit = max(1, (int) config('rustdesk.sso_role_mapping.max_groups', 200));
        if (count($groups) > $limit) {
            // A list we had to truncate is not a statement of membership, it is a partial view.
            // Treating it as authoritative would revoke roles for exactly the users who belong to
            // the most groups, which is the same defect the memberOf;range= check exists to avoid.
            Log::warning('SSO role sync skipped an over-long group list', [
                'user_id' => $user->id,
                'provider_kind' => $providerKind,
                'provider_key' => $providerKey,
                'groups' => count($groups),
                'limit' => $limit,
            ]);

            $this->record($user, $providerKind, $providerKey, $channel, SsoRoleSyncLog::OUTCOME_PROVIDER_ERROR, [], [], [], count($groups), $ip);

            return;
        }

        $values = SsoGroupKey::usableValues($groups, $limit);

        $mappings = SsoRoleMapping::query()
            ->forProvider($providerKind, $providerKey)
            ->where('enabled', true)
            ->with('role')
            ->get();

        $allowGlobal = (bool) config('rustdesk.sso_role_mapping.allow_global_roles', false);

        $digests = [];
        foreach ($values as $value) {
            $digests[SsoGroupKey::digest($providerKind, $value)] = $value;
        }

        /** @var array<int, AdminRole> $desiredRoles */
        $desiredRoles = [];
        $matchedGroups = [];
        $refusedGlobal = false;

        foreach ($mappings as $mapping) {
            $key = (string) $mapping->group_key;
            if (! isset($digests[$key])) {
                continue;
            }

            $role = $mapping->role;
            if (! $role instanceof AdminRole) {
                continue;
            }

            // Re-checked here and not only at write time, so turning the opt-in off disables
            // mappings that were created while it was on.
            if ($role->type === AdminRole::TYPE_GLOBAL && ! $allowGlobal) {
                $refusedGlobal = true;

                continue;
            }

            $desiredRoles[(int) $role->id] = $role;
            $matchedGroups[$digests[$key]] = true;
        }

        $held = $this->heldGrants($user, $origin);
        $desiredIds = array_keys($desiredRoles);
        $heldIds = array_keys($held);

        // The pivot is unique on (role, user), so a role the user already holds manually or from
        // another provider cannot receive a second row. Without this the insert would silently do
        // nothing, the grant would never appear as held, and every single sign-in would believe it
        // had just granted the role -- bumping the credential version and deleting the account's
        // client tokens forever.
        $heldElsewhere = $this->roleIdsHeldOutsideOrigin($user, $origin);

        $toGrant = array_values(array_diff($desiredIds, $heldIds, $heldElsewhere));
        $toRevoke = array_values(array_diff($heldIds, $desiredIds));

        if ($toGrant === [] && $toRevoke === []) {
            if ($refusedGlobal) {
                $this->record($user, $providerKind, $providerKey, $channel, SsoRoleSyncLog::OUTCOME_REFUSED_GLOBAL, [], [], array_keys($matchedGroups), count($values), $ip);
            }

            return;
        }

        $granted = [];
        $revoked = [];

        DB::transaction(function () use ($user, $origin, $desiredRoles, $toGrant, $toRevoke, $held, &$granted, &$revoked): void {
            if ($toRevoke !== []) {
                DB::table('admin_role_user')
                    ->where('user_id', $user->id)
                    ->where('origin', $origin)
                    ->whereIn('admin_role_id', $toRevoke)
                    ->delete();

                foreach ($toRevoke as $roleId) {
                    $revoked[] = ['id' => $roleId, 'name' => $held[$roleId] ?? ''];
                }
            }

            foreach ($toGrant as $roleId) {
                $role = $desiredRoles[$roleId];
                // A concurrent login from a second device can race this; the pivot's unique
                // constraint is the arbiter and a duplicate is simply the desired state.
                DB::table('admin_role_user')->insertOrIgnore([
                    'admin_role_id' => $roleId,
                    'user_id' => $user->id,
                    'origin' => $origin,
                    'sso_role_mapping_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $granted[] = ['id' => $roleId, 'name' => (string) $role->name];
            }
        }, 3);

        $user->unsetRelation('adminRoles');

        // Authority just changed, so anything carrying the previous answer must stop being
        // trusted: browser sessions re-evaluate against credential_version, and client bearer
        // tokens carry an is_admin snapshot taken when they were issued.
        $this->invalidatePriorAuthority($user);

        $this->record(
            $user,
            $providerKind,
            $providerKey,
            $channel,
            SsoRoleSyncLog::OUTCOME_CHANGED,
            $granted,
            $revoked,
            array_keys($matchedGroups),
            count($values),
            $ip,
        );
    }

    /**
     * Role ids this provider currently grants the user, mapped to their names.
     *
     * @return array<int, string>
     */
    private function heldGrants(User $user, string $origin): array
    {
        $rows = DB::table('admin_role_user')
            ->join('admin_roles', 'admin_roles.id', '=', 'admin_role_user.admin_role_id')
            ->where('admin_role_user.user_id', $user->id)
            ->where('admin_role_user.origin', $origin)
            ->get(['admin_roles.id', 'admin_roles.name']);

        $held = [];
        foreach ($rows as $row) {
            $held[(int) $row->id] = (string) $row->name;
        }

        return $held;
    }

    /**
     * Role ids the user holds from somewhere other than this provider.
     *
     * @return list<int>
     */
    private function roleIdsHeldOutsideOrigin(User $user, string $origin): array
    {
        return DB::table('admin_role_user')
            ->where('user_id', $user->id)
            ->where('origin', '!=', $origin)
            ->pluck('admin_role_id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Force existing sessions and issued tokens to re-derive this user's authority.
     */
    private function invalidatePriorAuthority(User $user): void
    {
        $user->forceFill(['credential_version' => max(1, (int) $user->credential_version) + 1])->save();

        AuthToken::where('user_id', $user->id)->delete();
    }

    /**
     * @param  list<array{id: int, name: string}>  $granted
     * @param  list<array{id: int, name: string}>  $revoked
     * @param  list<string>  $matchedGroups
     */
    private function record(
        User $user,
        string $providerKind,
        string $providerKey,
        string $channel,
        string $outcome,
        array $granted,
        array $revoked,
        array $matchedGroups,
        int $groupsSeen,
        ?string $ip,
    ): void {
        try {
            SsoRoleSyncLog::create([
                'user_id' => $user->id,
                'username' => (string) $user->username,
                'provider_kind' => $providerKind,
                'provider_key' => mb_substr($providerKey, 0, 191),
                'channel' => $channel,
                'outcome' => $outcome,
                'granted' => $granted,
                'revoked' => $revoked,
                'matched_groups' => array_slice($matchedGroups, 0, 50),
                'groups_seen' => $groupsSeen,
                'ip' => $ip,
            ]);
        } catch (\Throwable $e) {
            // Auditing must never break a login, but losing the record of a grant is worth
            // shouting about.
            Log::warning('SSO role sync audit write failed', [
                'user_id' => $user->id,
                'outcome' => $outcome,
                'exception' => $e::class,
            ]);
        }
    }
}
