<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\AuthToken;
use App\Models\SsoRoleMapping;
use App\Models\SsoRoleSyncLog;
use App\Models\User;
use App\Services\SsoRoleSyncService;
use App\Support\SsoGroupKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Federated role grants. An identity provider deciding who may administer this console is a
 * privilege-escalation path by design, so these tests pin the containment rules as hard as the
 * happy path.
 */
class SsoRoleMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_mapped_group_grants_its_role_at_sign_in(): void
    {
        $user = $this->member();
        $role = $this->role('Helpdesk', ['devices.view']);
        $this->mapping('ldap', 'dir-a', 'cn=helpdesk,dc=example,dc=com', $role);

        $this->sync($user, 'ldap', 'dir-a', ['cn=helpdesk,dc=example,dc=com']);

        $this->assertTrue($user->fresh()?->adminRoles()->whereKey($role->id)->exists());
        $this->assertSame('idp:ldap:dir-a', DB::table('admin_role_user')->where('user_id', $user->id)->value('origin'));
    }

    public function test_leaving_the_group_revokes_the_role(): void
    {
        $user = $this->member();
        $role = $this->role('Helpdesk', ['devices.view']);
        $this->mapping('ldap', 'dir-a', 'cn=helpdesk,dc=example,dc=com', $role);

        $this->sync($user, 'ldap', 'dir-a', ['cn=helpdesk,dc=example,dc=com']);
        $this->sync($user, 'ldap', 'dir-a', []);

        $this->assertFalse($user->fresh()?->adminRoles()->whereKey($role->id)->exists());
    }

    public function test_an_unreadable_group_list_never_revokes(): void
    {
        $user = $this->member();
        $role = $this->role('Helpdesk', ['devices.view']);
        $this->mapping('ldap', 'dir-a', 'cn=helpdesk,dc=example,dc=com', $role);
        $this->sync($user, 'ldap', 'dir-a', ['cn=helpdesk,dc=example,dc=com']);

        // null means the directory could not be asked. Absence of evidence is not evidence of
        // removal, so the grant must survive.
        $this->sync($user, 'ldap', 'dir-a', null);

        $this->assertTrue($user->fresh()?->adminRoles()->whereKey($role->id)->exists());
        $this->assertDatabaseHas('sso_role_sync_logs', ['outcome' => SsoRoleSyncLog::OUTCOME_PROVIDER_ERROR]);
    }

    public function test_manual_grants_are_never_touched_by_a_sync(): void
    {
        $user = $this->member();
        $manual = $this->role('Hand assigned', ['audit.view']);
        $mapped = $this->role('Mapped', ['devices.view']);
        $user->adminRoles()->attach($manual->id, ['origin' => 'manual']);
        $this->mapping('ldap', 'dir-a', 'cn=helpdesk,dc=example,dc=com', $mapped);

        $this->sync($user, 'ldap', 'dir-a', ['cn=helpdesk,dc=example,dc=com']);
        $this->sync($user, 'ldap', 'dir-a', []);

        $this->assertTrue($user->fresh()?->adminRoles()->whereKey($manual->id)->exists());
        $this->assertFalse($user->fresh()?->adminRoles()->whereKey($mapped->id)->exists());
    }

    public function test_one_provider_never_revokes_another_providers_grant(): void
    {
        $user = $this->member();
        $role = $this->role('Shared', ['devices.view']);
        $this->mapping('oidc', 'authentik', 'admins', $role);
        $this->sync($user, 'oidc', 'authentik', ['admins']);

        // A different directory reporting no groups must not touch what authentik granted.
        $this->sync($user, 'ldap', 'dir-a', []);

        $this->assertTrue($user->fresh()?->adminRoles()->whereKey($role->id)->exists());
    }

    public function test_a_full_administrator_is_never_altered_by_a_provider(): void
    {
        $admin = User::create([
            'username' => 'breakglass', 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);
        $role = $this->role('Mapped', ['devices.view']);
        $this->mapping('oidc', 'authentik', 'admins', $role);

        $this->sync($admin, 'oidc', 'authentik', ['admins']);

        $this->assertFalse($admin->fresh()?->adminRoles()->exists());
        $this->assertDatabaseHas('sso_role_sync_logs', ['outcome' => SsoRoleSyncLog::OUTCOME_SKIPPED_FULL_ADMIN]);
    }

    public function test_a_global_role_is_refused_unless_the_server_opts_in(): void
    {
        $user = $this->member();
        $global = $this->role('Everything', [], AdminRole::TYPE_GLOBAL);
        $this->mapping('oidc', 'authentik', 'admins', $global);

        config()->set('rustdesk.sso_role_mapping.allow_global_roles', false);
        $this->sync($user, 'oidc', 'authentik', ['admins']);
        $this->assertFalse($user->fresh()?->adminRoles()->exists());
        $this->assertDatabaseHas('sso_role_sync_logs', ['outcome' => SsoRoleSyncLog::OUTCOME_REFUSED_GLOBAL]);

        config()->set('rustdesk.sso_role_mapping.allow_global_roles', true);
        $this->sync($user, 'oidc', 'authentik', ['admins']);
        $this->assertTrue($user->fresh()?->adminRoles()->whereKey($global->id)->exists());
    }

    public function test_turning_the_global_opt_in_off_revokes_what_it_granted(): void
    {
        $user = $this->member();
        $global = $this->role('Everything', [], AdminRole::TYPE_GLOBAL);
        $this->mapping('oidc', 'authentik', 'admins', $global);

        config()->set('rustdesk.sso_role_mapping.allow_global_roles', true);
        $this->sync($user, 'oidc', 'authentik', ['admins']);
        $this->assertTrue($user->fresh()?->adminRoles()->whereKey($global->id)->exists());

        config()->set('rustdesk.sso_role_mapping.allow_global_roles', false);
        $this->sync($user, 'oidc', 'authentik', ['admins']);
        $this->assertFalse($user->fresh()?->adminRoles()->whereKey($global->id)->exists());
    }

    public function test_a_disabled_mapping_grants_nothing(): void
    {
        $user = $this->member();
        $role = $this->role('Helpdesk', ['devices.view']);
        $mapping = $this->mapping('oidc', 'authentik', 'admins', $role);
        $mapping->forceFill(['enabled' => false])->save();

        $this->sync($user, 'oidc', 'authentik', ['admins']);

        $this->assertFalse($user->fresh()?->adminRoles()->exists());
    }

    public function test_a_changed_grant_invalidates_prior_sessions_and_tokens(): void
    {
        $user = $this->member();
        $role = $this->role('Helpdesk', ['devices.view']);
        $this->mapping('oidc', 'authentik', 'admins', $role);
        $before = (int) $user->credential_version;
        AuthToken::create([
            'user_id' => $user->id, 'credential_version' => $before, 'token' => 'stale-token',
            'rustdesk_id' => 'dev', 'uuid' => 'uuid', 'is_admin' => false,
            'expires_at' => now()->addDay(),
        ]);

        $this->sync($user, 'oidc', 'authentik', ['admins']);

        $this->assertGreaterThan($before, (int) $user->fresh()?->credential_version);
        $this->assertDatabaseMissing('auth_tokens', ['token' => 'stale-token']);
    }

    public function test_an_unchanged_effective_set_does_not_churn_the_credential_version(): void
    {
        $user = $this->member();
        $role = $this->role('Helpdesk', ['devices.view']);
        $this->mapping('oidc', 'authentik', 'admins', $role);
        $this->sync($user, 'oidc', 'authentik', ['admins']);
        $settled = (int) $user->fresh()?->credential_version;

        $this->sync($user, 'oidc', 'authentik', ['admins']);

        $this->assertSame($settled, (int) $user->fresh()?->credential_version);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function equivalentDistinguishedNameProvider(): iterable
    {
        yield 'padded separators' => ['cn=admins,ou=groups,dc=x', 'cn=admins, ou=groups, dc=x'];
        yield 'mixed case' => ['CN=Admins,OU=Groups,DC=X', 'cn=admins,ou=groups,dc=x'];
        yield 'surrounding whitespace' => ['cn=admins,ou=groups,dc=x', '  cn=admins,ou=groups,dc=x  '];
        yield 'padded equals' => ['cn=admins,ou=groups,dc=x', 'cn = admins,ou = groups,dc = x'];
    }

    #[DataProvider('equivalentDistinguishedNameProvider')]
    public function test_distinguished_names_match_despite_insignificant_formatting(string $configured, string $asserted): void
    {
        $user = $this->member();
        $role = $this->role('Helpdesk', ['devices.view']);
        $this->mapping('ldap', 'dir-a', $configured, $role);

        $this->sync($user, 'ldap', 'dir-a', [$asserted]);

        $this->assertTrue($user->fresh()?->adminRoles()->whereKey($role->id)->exists());
    }

    public function test_an_oidc_group_is_matched_literally_and_not_as_a_distinguished_name(): void
    {
        $user = $this->member();
        $role = $this->role('Helpdesk', ['devices.view']);
        $this->mapping('oidc', 'authentik', 'team=a, team=b', $role);

        // The LDAP separator rules must not apply here: an OIDC group is an opaque string.
        $this->sync($user, 'oidc', 'authentik', ['team=a,team=b']);
        $this->assertFalse($user->fresh()?->adminRoles()->exists());

        $this->sync($user, 'oidc', 'authentik', ['team=a, team=b']);
        $this->assertTrue($user->fresh()?->adminRoles()->whereKey($role->id)->exists());
    }

    public function test_an_unrelated_group_grants_nothing(): void
    {
        $user = $this->member();
        $role = $this->role('Helpdesk', ['devices.view']);
        $this->mapping('oidc', 'authentik', 'admins', $role);

        $this->sync($user, 'oidc', 'authentik', ['everyone', 'developers']);

        $this->assertFalse($user->fresh()?->adminRoles()->exists());
    }

    public function test_an_oversized_group_list_is_treated_as_unreadable_rather_than_authoritative(): void
    {
        $user = $this->member();
        $role = $this->role('Helpdesk', ['devices.view']);
        $this->mapping('oidc', 'authentik', 'admins', $role);
        $this->sync($user, 'oidc', 'authentik', ['admins']);
        $this->assertTrue($user->fresh()?->adminRoles()->whereKey($role->id)->exists());

        // Truncating a list and then acting on it would revoke roles for exactly the users who
        // belong to the most groups. A list too long to read in full is unknown, not empty.
        config()->set('rustdesk.sso_role_mapping.max_groups', 5);
        $groups = array_map(static fn (int $i): string => 'filler-'.$i, range(1, 50));
        $groups[] = 'admins';

        $this->sync($user, 'oidc', 'authentik', $groups);

        $this->assertTrue($user->fresh()?->adminRoles()->whereKey($role->id)->exists());
        $this->assertDatabaseHas('sso_role_sync_logs', ['outcome' => SsoRoleSyncLog::OUTCOME_PROVIDER_ERROR]);
    }

    public function test_a_role_already_held_manually_is_not_re_granted_on_every_sign_in(): void
    {
        $user = $this->member();
        $role = $this->role('Helpdesk', ['devices.view']);
        $user->adminRoles()->attach($role->id, ['origin' => 'manual']);
        $this->mapping('oidc', 'authentik', 'admins', $role);

        $this->sync($user, 'oidc', 'authentik', ['admins']);
        $settled = (int) $user->fresh()?->credential_version;
        $this->sync($user, 'oidc', 'authentik', ['admins']);

        // The pivot is unique on (role, user), so the federated row can never be inserted next to
        // the manual one. Without recognising that, every sign-in would believe it had just
        // granted the role and would delete the account's client tokens forever.
        $this->assertSame($settled, (int) $user->fresh()?->credential_version);
        $this->assertSame(1, DB::table('admin_role_user')->where('user_id', $user->id)->count());
        $this->assertSame('manual', DB::table('admin_role_user')->where('user_id', $user->id)->value('origin'));
    }

    public function test_a_group_claim_shape_that_cannot_be_read_never_revokes(): void
    {
        $user = $this->member();
        $role = $this->role('Helpdesk', ['devices.view']);
        $this->mapping('oidc', 'authentik', 'admins', $role);
        $this->sync($user, 'oidc', 'authentik', ['admins']);

        // A claim that held entries but no usable strings is an unrecognised shape, not an empty
        // membership statement. OauthService reports it as null; the engine must not revoke.
        $this->sync($user, 'oidc', 'authentik', null);

        $this->assertTrue($user->fresh()?->adminRoles()->whereKey($role->id)->exists());
    }

    public function test_deleting_a_role_removes_the_mappings_that_targeted_it(): void
    {
        $role = $this->role('Helpdesk', ['devices.view']);
        $this->mapping('oidc', 'authentik', 'admins', $role);

        $role->delete();

        $this->assertDatabaseCount('sso_role_mappings', 0);
    }

    private function sync(User $user, string $kind, string $key, ?array $groups): void
    {
        app(SsoRoleSyncService::class)->sync($user, $kind, $key, $groups, 'test');
    }

    private function member(): User
    {
        return User::create([
            'username' => 'member', 'password' => 'secret12345',
            'is_admin' => false, 'status' => User::STATUS_NORMAL,
        ]);
    }

    /**
     * @param  list<string>  $perms
     */
    private function role(string $name, array $perms, string $type = AdminRole::TYPE_INDIVIDUAL): AdminRole
    {
        return AdminRole::create(['name' => $name, 'type' => $type, 'scope' => [], 'perms' => $perms]);
    }

    private function mapping(string $kind, string $key, string $group, AdminRole $role): SsoRoleMapping
    {
        return SsoRoleMapping::create([
            'provider_kind' => $kind,
            'provider_key' => $key,
            'group_value' => $group,
            'group_key' => SsoGroupKey::digest($kind, $group),
            'admin_role_id' => $role->id,
            'enabled' => true,
        ]);
    }
}
