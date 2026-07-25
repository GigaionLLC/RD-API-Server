<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\OauthProvider;
use App\Models\SsoRoleMapping;
use App\Models\User;
use App\Support\SsoGroupKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Who may author a mapping. Creating one decides who becomes an administrator, so it is the
 * same escalation surface as rewriting a role and carries the same full-administrator-only rule.
 */
class SsoRoleMappingConsoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_delegate_may_inspect_mappings_but_not_author_one(): void
    {
        $role = AdminRole::create([
            'name' => 'Viewer', 'type' => AdminRole::TYPE_INDIVIDUAL,
            'scope' => [], 'perms' => ['sso_mappings.view'],
        ]);
        $delegate = User::create([
            'username' => 'delegate', 'password' => 'secret12345',
            'is_admin' => false, 'status' => User::STATUS_NORMAL,
        ]);
        $delegate->adminRoles()->attach($role->id, ['origin' => 'manual']);
        $target = AdminRole::create([
            'name' => 'Target', 'type' => AdminRole::TYPE_INDIVIDUAL, 'scope' => [], 'perms' => [],
        ]);

        $this->actingAs($delegate)->get(route('admin.sso-role-mappings.index'))->assertOk();

        $this->actingAs($delegate)->post(route('admin.sso-role-mappings.store'), [
            'provider_kind' => 'oidc',
            'provider_key' => 'authentik',
            'group_value' => 'admins',
            'admin_role_id' => $target->id,
            'enabled' => 1,
        ])->assertForbidden();

        $this->assertDatabaseCount('sso_role_mappings', 0);
    }

    public function test_an_account_without_the_permission_cannot_reach_the_screen(): void
    {
        $role = AdminRole::create([
            'name' => 'Unrelated', 'type' => AdminRole::TYPE_INDIVIDUAL,
            'scope' => [], 'perms' => ['devices.view'],
        ]);
        $user = User::create([
            'username' => 'other', 'password' => 'secret12345',
            'is_admin' => false, 'status' => User::STATUS_NORMAL,
        ]);
        $user->adminRoles()->attach($role->id, ['origin' => 'manual']);

        $this->actingAs($user)->get(route('admin.sso-role-mappings.index'))
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_a_full_administrator_can_author_a_mapping(): void
    {
        $target = AdminRole::create([
            'name' => 'Helpdesk', 'type' => AdminRole::TYPE_INDIVIDUAL,
            'scope' => [], 'perms' => ['devices.view'],
        ]);

        $this->actingAs($this->fullAdmin())->post(route('admin.sso-role-mappings.store'), [
            'provider_kind' => 'ldap',
            'provider_key' => 'dir-a',
            'group_value' => 'CN=Helpdesk, DC=example, DC=com',
            'admin_role_id' => $target->id,
            'enabled' => 1,
        ])->assertRedirect(route('admin.sso-role-mappings.index'));

        $mapping = SsoRoleMapping::firstOrFail();
        $this->assertSame('CN=Helpdesk, DC=example, DC=com', $mapping->group_value);
        $this->assertSame(SsoGroupKey::digest('ldap', 'cn=helpdesk,dc=example,dc=com'), $mapping->group_key);
    }

    public function test_a_global_role_target_is_refused_while_the_opt_in_is_off(): void
    {
        config()->set('rustdesk.sso_role_mapping.allow_global_roles', false);
        $global = AdminRole::create([
            'name' => 'Everything', 'type' => AdminRole::TYPE_GLOBAL, 'scope' => [], 'perms' => [],
        ]);

        $this->actingAs($this->fullAdmin())->post(route('admin.sso-role-mappings.store'), [
            'provider_kind' => 'oidc',
            'provider_key' => 'authentik',
            'group_value' => 'admins',
            'admin_role_id' => $global->id,
            'enabled' => 1,
        ])->assertSessionHasErrors('admin_role_id');

        $this->assertDatabaseCount('sso_role_mappings', 0);
    }

    public function test_deleting_a_mapping_revokes_the_roles_it_granted(): void
    {
        $target = AdminRole::create([
            'name' => 'Helpdesk', 'type' => AdminRole::TYPE_INDIVIDUAL,
            'scope' => [], 'perms' => ['devices.view'],
        ]);
        $mapping = SsoRoleMapping::create([
            'provider_kind' => 'oidc', 'provider_key' => 'authentik',
            'group_value' => 'admins', 'group_key' => SsoGroupKey::digest('oidc', 'admins'),
            'admin_role_id' => $target->id, 'enabled' => true,
        ]);
        $member = User::create([
            'username' => 'member', 'password' => 'secret12345',
            'is_admin' => false, 'status' => User::STATUS_NORMAL,
        ]);
        $member->adminRoles()->attach($target->id, ['origin' => 'idp:oidc:authentik']);

        $this->actingAs($this->fullAdmin())
            ->delete(route('admin.sso-role-mappings.destroy', $mapping))
            ->assertRedirect(route('admin.sso-role-mappings.index'));

        // Revocation is eager: waiting for a sign-in that may never come would leave the grant
        // standing indefinitely after the operator believed they removed it.
        $this->assertFalse($member->fresh()?->adminRoles()->exists());
    }

    public function test_a_duplicate_mapping_is_refused(): void
    {
        $target = AdminRole::create([
            'name' => 'Helpdesk', 'type' => AdminRole::TYPE_INDIVIDUAL, 'scope' => [], 'perms' => [],
        ]);
        SsoRoleMapping::create([
            'provider_kind' => 'oidc', 'provider_key' => 'authentik',
            'group_value' => 'admins', 'group_key' => SsoGroupKey::digest('oidc', 'admins'),
            'admin_role_id' => $target->id, 'enabled' => true,
        ]);

        $this->actingAs($this->fullAdmin())->post(route('admin.sso-role-mappings.store'), [
            'provider_kind' => 'oidc',
            'provider_key' => 'authentik',
            'group_value' => 'ADMINS',
            'admin_role_id' => $target->id,
            'enabled' => 1,
        ])->assertSessionHasErrors('group_value');

        $this->assertDatabaseCount('sso_role_mappings', 1);
    }

    public function test_editing_a_user_never_clears_a_federated_grant(): void
    {
        $federated = AdminRole::create([
            'name' => 'From provider', 'type' => AdminRole::TYPE_INDIVIDUAL, 'scope' => [], 'perms' => [],
        ]);
        $manual = AdminRole::create([
            'name' => 'By hand', 'type' => AdminRole::TYPE_INDIVIDUAL, 'scope' => [], 'perms' => [],
        ]);
        $member = User::create([
            'username' => 'member', 'password' => 'secret12345',
            'is_admin' => false, 'status' => User::STATUS_NORMAL,
        ]);
        $member->adminRoles()->attach($federated->id, ['origin' => 'idp:oidc:authentik']);
        $member->adminRoles()->attach($manual->id, ['origin' => 'manual']);

        // Submitting the form with no roles selected must clear only the hand-assigned one.
        $this->actingAs($this->fullAdmin())->putJson(route('admin.users.update', $member), [
            'status' => User::STATUS_NORMAL,
            'login_verify' => User::LOGIN_VERIFY_OFF,
            'admin_role_ids' => '',
        ])->assertOk();

        $origins = DB::table('admin_role_user')->where('user_id', $member->id)->pluck('origin')->all();
        $this->assertSame(['idp:oidc:authentik'], $origins);
    }

    public function test_retargeting_a_mapping_revokes_the_role_it_previously_granted(): void
    {
        $oldRole = AdminRole::create([
            'name' => 'Broad', 'type' => AdminRole::TYPE_INDIVIDUAL, 'scope' => [], 'perms' => ['settings.edit'],
        ]);
        $newRole = AdminRole::create([
            'name' => 'Narrow', 'type' => AdminRole::TYPE_INDIVIDUAL, 'scope' => [], 'perms' => ['devices.view'],
        ]);
        $mapping = SsoRoleMapping::create([
            'provider_kind' => 'oidc', 'provider_key' => 'authentik',
            'group_value' => 'staff', 'group_key' => SsoGroupKey::digest('oidc', 'staff'),
            'admin_role_id' => $oldRole->id, 'enabled' => true,
        ]);
        $member = User::create([
            'username' => 'member', 'password' => 'secret12345',
            'is_admin' => false, 'status' => User::STATUS_NORMAL,
        ]);
        $member->adminRoles()->attach($oldRole->id, ['origin' => 'idp:oidc:authentik']);

        $this->actingAs($this->fullAdmin())->put(route('admin.sso-role-mappings.update', $mapping), [
            'provider_kind' => 'oidc',
            'provider_key' => 'authentik',
            'group_value' => 'staff',
            'admin_role_id' => $newRole->id,
            'enabled' => 1,
        ])->assertRedirect(route('admin.sso-role-mappings.index'));

        // The broad role must not be left standing on users who would otherwise keep it until
        // they happen to sign in through the provider again.
        $this->assertFalse($member->fresh()?->adminRoles()->whereKey($oldRole->id)->exists());
    }

    public function test_removing_one_mapping_keeps_a_role_another_mapping_still_grants(): void
    {
        $role = AdminRole::create([
            'name' => 'Shared', 'type' => AdminRole::TYPE_INDIVIDUAL, 'scope' => [], 'perms' => ['devices.view'],
        ]);
        $first = SsoRoleMapping::create([
            'provider_kind' => 'oidc', 'provider_key' => 'authentik',
            'group_value' => 'staff', 'group_key' => SsoGroupKey::digest('oidc', 'staff'),
            'admin_role_id' => $role->id, 'enabled' => true,
        ]);
        SsoRoleMapping::create([
            'provider_kind' => 'oidc', 'provider_key' => 'authentik',
            'group_value' => 'contractors', 'group_key' => SsoGroupKey::digest('oidc', 'contractors'),
            'admin_role_id' => $role->id, 'enabled' => true,
        ]);
        $member = User::create([
            'username' => 'member', 'password' => 'secret12345',
            'is_admin' => false, 'status' => User::STATUS_NORMAL,
        ]);
        $member->adminRoles()->attach($role->id, ['origin' => 'idp:oidc:authentik']);

        $this->actingAs($this->fullAdmin())
            ->delete(route('admin.sso-role-mappings.destroy', $first))
            ->assertRedirect(route('admin.sso-role-mappings.index'));

        // The contractors mapping still justifies this role for this provider.
        $this->assertTrue($member->fresh()?->adminRoles()->whereKey($role->id)->exists());
    }

    public function test_a_provider_groups_claim_can_be_configured_from_the_console(): void
    {
        $this->actingAs($this->fullAdmin())->post(route('admin.oauth-providers.store'), [
            'op' => 'authentik',
            'type' => 'oidc',
            'client_id' => 'rustdesk',
            'client_secret' => 'secret',
            'scopes' => 'openid,profile,email,groups',
            'groups_claim' => 'groups',
            'issuer' => 'https://idp.example.com/application/o/rustdesk',
            'pkce_method' => 'S256',
        ])->assertRedirect();

        // Without a write path the entire OIDC half of the feature is inert.
        $this->assertSame('groups', OauthProvider::where('op', 'authentik')->value('groups_claim'));
    }

    public function test_the_user_form_shows_federated_roles_read_only_and_preselects_only_manual_ones(): void
    {
        $federated = AdminRole::create([
            'name' => 'Granted by provider', 'type' => AdminRole::TYPE_INDIVIDUAL, 'scope' => [], 'perms' => [],
        ]);
        $manual = AdminRole::create([
            'name' => 'Assigned by hand', 'type' => AdminRole::TYPE_INDIVIDUAL, 'scope' => [], 'perms' => [],
        ]);
        $member = User::create([
            'username' => 'member', 'password' => 'secret12345',
            'is_admin' => false, 'status' => User::STATUS_NORMAL,
        ]);
        $member->adminRoles()->attach($federated->id, ['origin' => 'idp:oidc:authentik']);
        $member->adminRoles()->attach($manual->id, ['origin' => 'manual']);

        $response = $this->actingAs($this->fullAdmin())->get(route('admin.users.edit', $member));

        $response->assertOk()
            ->assertSee('Roles from an identity provider')
            ->assertSee('idp:oidc:authentik', false);

        // The editable selector must carry the manual grant only, so saving the form unchanged
        // cannot silently drop the federated one.
        $response->assertSee('name="admin_role_ids" value="'.$manual->id.'"', false);
    }

    private function fullAdmin(): User
    {
        return User::create([
            'username' => 'root', 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);
    }
}
