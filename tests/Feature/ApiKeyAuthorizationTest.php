<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Security boundaries between delegated console roles, scoped API keys, ordinary accounts,
 * and the full-administrator trust root.
 */
class ApiKeyAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<string>  $permissions
     */
    private function delegate(array $permissions, string $name = 'delegate'): User
    {
        $role = AdminRole::create([
            'name' => $name.' role',
            'type' => AdminRole::TYPE_INDIVIDUAL,
            'scope' => [],
            'perms' => $permissions,
        ]);
        $user = User::create([
            'username' => $name,
            'password' => 'secret12345',
            'is_admin' => false,
            'status' => User::STATUS_NORMAL,
        ]);
        $user->adminRoles()->attach($role);

        return $user;
    }

    private function fullAdmin(string $name = 'admin'): User
    {
        return User::create([
            'username' => $name,
            'password' => 'secret12345',
            'is_admin' => true,
            'status' => User::STATUS_NORMAL,
        ]);
    }

    /**
     * @param  list<string>  $scopes
     * @return array{0: ApiKey, 1: string}
     */
    private function keyFor(User $owner, array $scopes, string $name = 'Automation'): array
    {
        [$plain, $prefix, $hash] = ApiKey::generateSecret();
        $key = ApiKey::create([
            'user_id' => $owner->id,
            'name' => $name,
            'token_hash' => $hash,
            'prefix' => $prefix,
            'scopes' => $scopes,
        ]);

        return [$key, $plain];
    }

    public function test_minimal_delegate_cannot_view_or_mutate_api_keys(): void
    {
        $delegate = $this->delegate(['devices.view']);

        $this->actingAs($delegate)
            ->get(route('admin.api-keys.index'))
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHasErrors('permission');

        $this->actingAs($delegate)
            ->post(route('admin.api-keys.store'), ['name' => 'Denied', 'scopes' => ['devices.read']])
            ->assertForbidden();

        $this->assertDatabaseMissing('api_keys', ['name' => 'Denied']);
    }

    public function test_api_key_view_permission_is_read_only(): void
    {
        $delegate = $this->delegate(['api_keys.view', 'devices.view']);
        $this->keyFor($delegate, ['devices.read'], 'Visible key');

        $this->actingAs($delegate)
            ->get(route('admin.api-keys.index'))
            ->assertOk()
            ->assertSee('Visible key')
            ->assertDontSee('Create API key');

        $this->actingAs($delegate)
            ->post(route('admin.api-keys.store'), ['name' => 'Denied', 'scopes' => ['devices.read']])
            ->assertForbidden();
    }

    public function test_delegated_key_manager_can_only_issue_authority_they_hold(): void
    {
        $delegate = $this->delegate(['api_keys.view', 'api_keys.edit', 'devices.view']);

        $this->actingAs($delegate)
            ->post(route('admin.api-keys.store'), ['name' => 'Device reader', 'scopes' => ['devices.read']])
            ->assertSessionHas('new_api_key');
        $this->assertDatabaseHas('api_keys', ['user_id' => $delegate->id, 'name' => 'Device reader']);

        $this->actingAs($delegate)
            ->post(route('admin.api-keys.store'), ['name' => 'User writer', 'scopes' => ['users.write']])
            ->assertSessionHasErrors('scopes.0');
        $this->assertDatabaseMissing('api_keys', ['name' => 'User writer']);
    }

    public function test_delegated_key_manager_only_sees_and_manages_owned_keys(): void
    {
        $delegate = $this->delegate(['api_keys.view', 'api_keys.edit', 'devices.view']);
        [$own] = $this->keyFor($delegate, ['devices.read'], 'Mine');
        [$other] = $this->keyFor($this->fullAdmin('other-admin'), ['devices.read'], 'Theirs');

        $this->actingAs($delegate)
            ->get(route('admin.api-keys.index'))
            ->assertOk()
            ->assertSee('Mine')
            ->assertDontSee('Theirs');

        $this->actingAs($delegate)->post(route('admin.api-keys.rotate', $other))->assertForbidden();
        $this->actingAs($delegate)->delete(route('admin.api-keys.destroy', $other))->assertForbidden();
        $this->assertModelExists($other);

        $this->actingAs($delegate)->post(route('admin.api-keys.rotate', $own))->assertSessionHas('new_api_key');
        $this->actingAs($delegate)->delete(route('admin.api-keys.destroy', $own))->assertRedirect();
        $this->assertModelMissing($own);
    }

    public function test_delegated_key_stops_working_when_owner_loses_console_authority(): void
    {
        $delegate = $this->delegate(['api_keys.view', 'api_keys.edit', 'devices.view']);
        [, $plain] = $this->keyFor($delegate, ['devices.read']);

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->getJson('/api/v1/devices')
            ->assertOk();

        $delegate->adminRoles()->firstOrFail()->update(['perms' => ['api_keys.view', 'api_keys.edit']]);
        $delegate->unsetRelation('adminRoles');

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->getJson('/api/v1/devices')
            ->assertForbidden()
            ->assertJsonPath('error', 'The API key owner is no longer authorized for this operation');
    }

    public function test_users_write_scope_cannot_create_or_promote_an_administrator(): void
    {
        $admin = $this->fullAdmin();
        [, $plain] = $this->keyFor($admin, ['users.write']);

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->postJson('/api/v1/users', [
                'username' => 'api-root',
                'password' => 'secret12345',
                'is_admin' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_admin');
        $this->assertDatabaseMissing('users', ['username' => 'api-root']);

        $ordinary = User::create([
            'username' => 'ordinary',
            'password' => 'secret12345',
            'status' => User::STATUS_NORMAL,
        ]);
        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->putJson("/api/v1/users/{$ordinary->id}", ['is_admin' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_admin');
        $this->assertFalse($ordinary->refresh()->is_admin);
    }

    public function test_users_write_scope_cannot_take_over_privileged_accounts(): void
    {
        $admin = $this->fullAdmin();
        [, $plain] = $this->keyFor($admin, ['users.write']);
        $targetAdmin = $this->fullAdmin('target-admin');
        $targetDelegate = $this->delegate(['devices.view'], 'target-delegate');
        $oldAdminPassword = $targetAdmin->password;
        $oldDelegatePassword = $targetDelegate->password;

        foreach ([$targetAdmin, $targetDelegate] as $target) {
            $this->withHeader('Authorization', 'Bearer '.$plain)
                ->putJson("/api/v1/users/{$target->id}", [
                    'password' => 'attacker-password',
                    'status' => User::STATUS_DISABLED,
                ])
                ->assertForbidden();
        }

        $this->assertSame($oldAdminPassword, $targetAdmin->refresh()->password);
        $this->assertSame(User::STATUS_NORMAL, $targetAdmin->status);
        $this->assertSame($oldDelegatePassword, $targetDelegate->refresh()->password);
        $this->assertSame(User::STATUS_NORMAL, $targetDelegate->status);
    }

    public function test_delegated_user_manager_can_still_manage_ordinary_accounts(): void
    {
        $delegate = $this->delegate(['users.view', 'users.edit']);

        $this->actingAs($delegate)->post(route('admin.users.store'), [
            'username' => 'managed-user',
            'password' => 'secret12345',
            'status' => User::STATUS_NORMAL,
            'login_verify' => User::LOGIN_VERIFY_OFF,
        ])->assertRedirect(route('admin.users.index'));

        $managed = User::where('username', 'managed-user')->firstOrFail();
        $this->actingAs($delegate)->putJson(route('admin.users.update', $managed), [
            'email' => 'managed@example.com',
            'display_name' => 'Managed User',
            'status' => User::STATUS_NORMAL,
            'force_sso' => false,
            'login_verify' => User::LOGIN_VERIFY_OFF,
        ])->assertOk();
        $this->assertSame('Managed User', $managed->refresh()->display_name);

        $this->actingAs($delegate)->putJson(route('admin.users.password', $managed), [
            'password' => 'replacement-password',
        ])->assertOk();
        $this->assertTrue(Hash::check('replacement-password', $managed->refresh()->password));

        $this->actingAs($delegate)->delete(route('admin.users.destroy', $managed))->assertRedirect();
        $this->assertModelMissing($managed);
    }

    public function test_delegated_user_manager_cannot_create_a_full_administrator(): void
    {
        $delegate = $this->delegate(['users.view', 'users.edit']);

        $this->actingAs($delegate)->post(route('admin.users.store'), [
            'username' => 'promoted-at-create',
            'password' => 'secret12345',
            'is_admin' => true,
            'status' => User::STATUS_NORMAL,
            'login_verify' => User::LOGIN_VERIFY_OFF,
        ])->assertSessionHasErrors('is_admin');
        $this->assertDatabaseMissing('users', ['username' => 'promoted-at-create']);
    }

    public function test_delegated_user_manager_cannot_promote_or_assign_roles(): void
    {
        $delegate = $this->delegate(['users.view', 'users.edit']);
        $higherRole = AdminRole::create([
            'name' => 'Higher privilege',
            'type' => AdminRole::TYPE_GLOBAL,
            'scope' => [],
            'perms' => [],
        ]);

        $ordinary = User::create([
            'username' => 'promotion-target',
            'password' => 'secret12345',
            'status' => User::STATUS_NORMAL,
        ]);
        $response = $this->actingAs($delegate)->put(route('admin.users.update', $ordinary), [
            'is_admin' => true,
            'admin_role_ids' => (string) $higherRole->id,
            'status' => User::STATUS_NORMAL,
            'login_verify' => User::LOGIN_VERIFY_OFF,
        ]);
        $response->assertSessionHasErrors(['is_admin', 'admin_role_ids']);

        $this->assertFalse($ordinary->refresh()->is_admin);
        $this->assertFalse($ordinary->adminRoles()->exists());
    }

    public function test_delegated_user_manager_cannot_modify_or_bulk_affect_privileged_accounts(): void
    {
        $delegate = $this->delegate(['users.view', 'users.edit']);
        $targetAdmin = $this->fullAdmin('protected-admin');
        $targetDelegate = $this->delegate(['devices.view'], 'protected-delegate');

        $this->actingAs($delegate)->get(route('admin.users.edit', $targetAdmin))->assertForbidden();
        $this->actingAs($delegate)->putJson(route('admin.users.password', $targetAdmin), [
            'password' => 'attacker-password',
        ])->assertForbidden();
        $this->actingAs($delegate)->delete(route('admin.users.destroy', $targetAdmin))->assertForbidden();

        $this->actingAs($delegate)->post(route('admin.users.bulk'), [
            'ids' => [$targetDelegate->id],
            'action' => 'disable',
        ])->assertForbidden();

        $this->assertModelExists($targetAdmin);
        $this->assertSame(User::STATUS_NORMAL, $targetDelegate->refresh()->status);
    }

    public function test_delegated_role_viewer_cannot_rewrite_their_role_as_global(): void
    {
        // roles.edit may exist on installations created before this hardening. The controller
        // guard remains authoritative even though new role forms no longer offer it.
        $delegate = $this->delegate(['roles.view', 'roles.edit'], 'legacy-role-editor');
        $role = $delegate->adminRoles()->firstOrFail();

        $this->actingAs($delegate)
            ->get(route('admin.roles.edit', $role))
            ->assertOk()
            ->assertSee('Only a full administrator may change administrative authority.');

        $this->actingAs($delegate)->put(route('admin.roles.update', $role), [
            'name' => 'Escalated',
            'type' => AdminRole::TYPE_GLOBAL,
            'perms' => [],
            'scope' => [],
        ])->assertForbidden();

        $this->assertSame(AdminRole::TYPE_INDIVIDUAL, $role->refresh()->type);
    }

    public function test_full_admin_preserves_global_key_and_account_management(): void
    {
        $admin = $this->fullAdmin();
        $owner = $this->fullAdmin('key-owner');
        [$key] = $this->keyFor($owner, ['devices.read'], 'Owner key');

        $this->actingAs($admin)
            ->get(route('admin.api-keys.index'))
            ->assertOk()
            ->assertSee('Owner key')
            ->assertSee('Create API key');
        $this->actingAs($admin)->post(route('admin.api-keys.rotate', $key))->assertSessionHas('new_api_key');

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'username' => 'new-full-admin',
            'password' => 'secret12345',
            'is_admin' => true,
            'status' => User::STATUS_NORMAL,
            'login_verify' => User::LOGIN_VERIFY_OFF,
        ])->assertRedirect(route('admin.users.index'));
        $this->assertDatabaseHas('users', ['username' => 'new-full-admin', 'is_admin' => true]);

        $role = AdminRole::create([
            'name' => 'Editable role',
            'type' => AdminRole::TYPE_INDIVIDUAL,
            'scope' => [],
            'perms' => ['devices.view'],
        ]);
        $this->actingAs($admin)->put(route('admin.roles.update', $role), [
            'name' => 'Updated role',
            'type' => AdminRole::TYPE_INDIVIDUAL,
            'scope' => [],
            'perms' => ['devices.view', 'devices.edit'],
        ])->assertRedirect(route('admin.roles.index'));
        $this->assertSame(['devices.view', 'devices.edit'], $role->refresh()->perms);
    }
}
