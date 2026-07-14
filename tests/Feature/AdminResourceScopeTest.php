<?php

namespace Tests\Feature;

use App\Models\AddressBook;
use App\Models\AdminRole;
use App\Models\Alarm;
use App\Models\ApiKey;
use App\Models\AuditConn;
use App\Models\ConsoleAudit;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\DeviceGroupAccess;
use App\Models\Group;
use App\Models\Recording;
use App\Models\Strategy;
use App\Models\User;
use App\Models\UserGroupAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminResourceScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_group_scoped_device_queries_exports_and_direct_mutations_stay_inside_scope(): void
    {
        [$delegate, $insideUser, $outsideUser, $insideDevice, $outsideDevice] = $this->groupFixture([
            'devices.view',
            'devices.edit',
        ]);

        $this->actingAs($delegate)
            ->get(route('admin.devices.index'))
            ->assertOk()
            ->assertSee($insideDevice->hostname)
            ->assertDontSee($outsideDevice->hostname);

        $csv = $this->actingAs($delegate)
            ->get(route('admin.devices.export'))
            ->assertOk()
            ->streamedContent();
        $this->assertStringContainsString((string) $insideDevice->rustdesk_id, $csv);
        $this->assertStringNotContainsString((string) $outsideDevice->rustdesk_id, $csv);

        $this->actingAs($delegate)
            ->putJson(route('admin.devices.update', $outsideDevice), ['alias' => 'stolen'])
            ->assertForbidden();
        $this->actingAs($delegate)
            ->delete(route('admin.devices.destroy', $outsideDevice))
            ->assertForbidden();
        $this->assertNull($outsideDevice->refresh()->alias);

        $this->actingAs($delegate)
            ->post(route('admin.devices.bulk'), [
                'ids' => [$insideDevice->id, $outsideDevice->id],
                'field' => 'user_id',
                'value' => $insideUser->id,
            ])
            ->assertForbidden();
        $this->assertSame($outsideUser->id, $outsideDevice->refresh()->user_id);

        $deviceGroup = DeviceGroup::create(['name' => 'Preserved device group']);
        $strategy = Strategy::create([
            'name' => 'Preserved strategy',
            'enabled' => true,
            'options' => [],
            'modified_at' => time(),
        ]);
        $insideDevice->forceFill([
            'note' => 'Keep this note',
            'device_group_id' => $deviceGroup->id,
            'strategy_id' => $strategy->id,
            'approved' => true,
        ])->save();

        $this->actingAs($delegate)
            ->putJson(route('admin.devices.update', $insideDevice), ['alias' => 'managed'])
            ->assertOk();
        $insideDevice->refresh();
        $this->assertSame('managed', $insideDevice->alias);
        $this->assertSame('Keep this note', $insideDevice->note);
        $this->assertSame($insideUser->id, $insideDevice->user_id);
        $this->assertSame($deviceGroup->id, $insideDevice->device_group_id);
        $this->assertSame($strategy->id, $insideDevice->strategy_id);
        $this->assertTrue($insideDevice->approved);

        $this->actingAs($delegate)
            ->putJson(route('admin.devices.update', $insideDevice), [
                'note' => null,
                'device_group_id' => null,
                'approved' => false,
            ])
            ->assertOk();
        $insideDevice->refresh();
        $this->assertNull($insideDevice->note);
        $this->assertNull($insideDevice->device_group_id);
        $this->assertSame($strategy->id, $insideDevice->strategy_id);
        $this->assertFalse($insideDevice->approved);
    }

    public function test_group_scoped_users_and_address_books_deny_cross_group_ids(): void
    {
        [$delegate, $insideUser, $outsideUser] = $this->groupFixture([
            'users.view',
            'users.edit',
            'address_books.view',
            'address_books.edit',
        ]);

        $insideBook = AddressBook::create(['user_id' => $insideUser->id, 'name' => 'Inside book']);
        $outsideBook = AddressBook::create(['user_id' => $outsideUser->id, 'name' => 'Outside book']);
        $outsideUser->forceFill(['email' => 'scope-leak@example.test'])->save();

        $this->actingAs($delegate)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee($insideUser->username)
            ->assertDontSee($outsideUser->username);
        $this->actingAs($delegate)
            ->get(route('admin.users.index', ['q' => 'scope-leak']))
            ->assertOk()
            ->assertDontSee($outsideUser->username);

        $this->actingAs($delegate)
            ->putJson(route('admin.users.update', $outsideUser), [
                'status' => User::STATUS_NORMAL,
                'login_verify' => User::LOGIN_VERIFY_OFF,
            ])
            ->assertForbidden();

        $this->actingAs($delegate)
            ->get(route('admin.address-books.index'))
            ->assertOk()
            ->assertSee($insideBook->name)
            ->assertDontSee($outsideBook->name);
        $this->actingAs($delegate)
            ->get(route('admin.address-books.show', $outsideBook))
            ->assertForbidden();
        $this->actingAs($delegate)
            ->delete(route('admin.address-books.destroy', $outsideBook))
            ->assertForbidden();
        $this->assertModelExists($outsideBook);
    }

    public function test_scoped_activity_surfaces_filter_and_deny_cross_device_actions(): void
    {
        [$delegate, $insideUser, $outsideUser, $insideDevice, $outsideDevice] = $this->groupFixture([
            'audit.view',
            'alarms.view',
            'alarms.edit',
            'recordings.view',
            'recordings.edit',
            'sessions.view',
            'sessions.edit',
        ]);

        AuditConn::create([
            'guid' => 'inside-guid',
            'action' => AuditConn::ACTION_NEW,
            'conn_id' => 10,
            'peer_id' => $insideDevice->rustdesk_id,
            'from_peer' => 'controller-a',
        ]);
        AuditConn::create([
            'guid' => 'outside-guid',
            'action' => AuditConn::ACTION_NEW,
            'conn_id' => 20,
            'peer_id' => $outsideDevice->rustdesk_id,
            'from_peer' => 'controller-b',
        ]);
        ConsoleAudit::create([
            'user_id' => $insideUser->id,
            'method' => 'POST',
            'route_name' => 'admin.inside-action',
            'path' => '/admin/inside-action',
            'ip' => '192.0.2.1',
        ]);
        ConsoleAudit::create([
            'user_id' => $outsideUser->id,
            'method' => 'POST',
            'route_name' => 'admin.scope-leak-route',
            'path' => '/admin/outside-action',
            'ip' => '192.0.2.2',
        ]);
        $insideAlarm = Alarm::create([
            'device_id' => $insideDevice->id,
            'peer_id' => $insideDevice->rustdesk_id,
            'type' => 'scope',
            'message' => 'Inside alarm',
        ]);
        $outsideAlarm = Alarm::create([
            'device_id' => $outsideDevice->id,
            'peer_id' => $outsideDevice->rustdesk_id,
            'type' => 'scope',
            'message' => 'Outside alarm',
        ]);
        $insideRecording = Recording::create([
            'peer_id' => $insideDevice->rustdesk_id,
            'filename' => 'inside.webm',
            'status' => 'complete',
        ]);
        $outsideRecording = Recording::create([
            'peer_id' => $outsideDevice->rustdesk_id,
            'filename' => 'outside.webm',
            'status' => 'complete',
        ]);

        $this->actingAs($delegate)->get(route('admin.audit.connections'))
            ->assertOk()->assertSee($insideDevice->rustdesk_id)->assertDontSee($outsideDevice->rustdesk_id);
        $this->actingAs($delegate)->get(route('admin.alarms.index'))
            ->assertOk()->assertSee($insideAlarm->message)->assertDontSee($outsideAlarm->message);
        $this->actingAs($delegate)->get(route('admin.recordings.index'))
            ->assertOk()->assertSee($insideRecording->filename)->assertDontSee($outsideRecording->filename);
        $this->actingAs($delegate)->get(route('admin.recordings.index', ['q' => 'outside.webm']))
            ->assertOk()->assertDontSee($outsideDevice->rustdesk_id);
        $this->actingAs($delegate)->get(route('admin.console-audit.index', ['q' => 'scope-leak-route']))
            ->assertOk()->assertDontSee('/admin/outside-action');
        $this->actingAs($delegate)->get(route('admin.sessions.index'))
            ->assertOk()->assertSee($insideDevice->rustdesk_id)->assertDontSee($outsideDevice->rustdesk_id);

        $this->actingAs($delegate)->delete(route('admin.alarms.destroy', $outsideAlarm))->assertForbidden();
        $this->actingAs($delegate)->delete(route('admin.recordings.destroy', $outsideRecording))->assertForbidden();
        $this->actingAs($delegate)->post(route('admin.sessions.disconnect'), [
            'peer_id' => $outsideDevice->rustdesk_id,
            'conn_id' => 20,
        ])->assertForbidden();

        $this->assertModelExists($outsideAlarm);
        $this->assertModelExists($outsideRecording);
    }

    public function test_scoped_api_key_cannot_read_or_update_an_out_of_scope_device(): void
    {
        [$delegate, , , $insideDevice, $outsideDevice] = $this->groupFixture([
            'api_keys.edit',
            'devices.view',
            'devices.edit',
        ]);
        [$plain, $prefix, $hash] = ApiKey::generateSecret();
        ApiKey::create([
            'user_id' => $delegate->id,
            'name' => 'Scoped automation',
            'token_hash' => $hash,
            'prefix' => $prefix,
            'scopes' => ['devices.read', 'devices.write'],
        ]);

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->getJson('/api/v1/devices')
            ->assertOk()
            ->assertJsonFragment(['rustdesk_id' => $insideDevice->rustdesk_id])
            ->assertJsonMissing(['rustdesk_id' => $outsideDevice->rustdesk_id]);

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->putJson('/api/v1/devices/'.$outsideDevice->id, ['alias' => 'api-stolen'])
            ->assertForbidden();
        $this->assertNull($outsideDevice->refresh()->alias);

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->putJson('/api/v1/devices/'.$insideDevice->id, ['alias' => 'api-managed'])
            ->assertOk();
        $this->assertSame('api-managed', $insideDevice->refresh()->alias);
    }

    public function test_individual_and_global_roles_keep_their_expected_boundaries(): void
    {
        $individual = $this->user('individual');
        $other = $this->user('other');
        $ownDevice = $this->device('own-peer', $individual, 'Own workstation');
        $otherDevice = $this->device('other-peer', $other, 'Other workstation');
        $individual->adminRoles()->attach(AdminRole::create([
            'name' => 'Individual device editor',
            'type' => AdminRole::TYPE_INDIVIDUAL,
            'scope' => [],
            'perms' => ['devices.view', 'devices.edit'],
        ]));

        $this->actingAs($individual)->get(route('admin.devices.index'))
            ->assertOk()->assertSee($ownDevice->hostname)->assertDontSee($otherDevice->hostname);
        $this->actingAs($individual)->delete(route('admin.devices.destroy', $otherDevice))->assertForbidden();

        $global = $this->user('global');
        $global->adminRoles()->attach(AdminRole::create([
            'name' => 'Global administrator',
            'type' => AdminRole::TYPE_GLOBAL,
            'scope' => [],
            'perms' => [],
        ]));
        $this->actingAs($global)->get(route('admin.devices.index'))
            ->assertOk()->assertSee($ownDevice->hostname)->assertSee($otherDevice->hostname);
        $this->actingAs($global)
            ->putJson(route('admin.devices.update', $otherDevice), ['alias' => 'global-managed'])
            ->assertOk();
    }

    public function test_scoped_device_editor_cannot_clear_ownership(): void
    {
        [$delegate, , , $insideDevice] = $this->groupFixture(['devices.edit']);

        $this->actingAs($delegate)
            ->putJson(route('admin.devices.update', $insideDevice), ['user_id' => null])
            ->assertForbidden();
        $this->assertNotNull($insideDevice->refresh()->user_id);

        $this->actingAs($delegate)
            ->post(route('admin.devices.bulk'), [
                'ids' => [$insideDevice->id],
                'field' => 'user_id',
                'value' => null,
            ])
            ->assertForbidden();
        $this->assertNotNull($insideDevice->refresh()->user_id);

        [$plain, $prefix, $hash] = ApiKey::generateSecret();
        ApiKey::create([
            'user_id' => $delegate->id,
            'name' => 'Scoped owner editor',
            'token_hash' => $hash,
            'prefix' => $prefix,
            'scopes' => ['devices.write'],
        ]);
        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->putJson('/api/v1/devices/'.$insideDevice->id, ['user_id' => null])
            ->assertForbidden();
        $this->assertNotNull($insideDevice->refresh()->user_id);
    }

    public function test_scoped_group_edit_preserves_hidden_out_of_scope_access_grants(): void
    {
        $insideGroup = Group::create(['name' => 'Inside scope', 'type' => Group::TYPE_DEFAULT]);
        $secondInsideGroup = Group::create(['name' => 'Second inside', 'type' => Group::TYPE_DEFAULT]);
        $outsideGroup = Group::create(['name' => 'Outside scope', 'type' => Group::TYPE_DEFAULT]);
        $delegate = $this->user('delegate', $insideGroup);
        $delegate->adminRoles()->attach(AdminRole::create([
            'name' => 'Scoped group editor',
            'type' => AdminRole::TYPE_GROUP,
            'scope' => [$insideGroup->id, $secondInsideGroup->id],
            'perms' => ['groups.edit', 'device_groups.edit'],
        ]));
        UserGroupAccess::create([
            'group_id' => $insideGroup->id,
            'can_access_group_id' => $outsideGroup->id,
        ]);

        $this->actingAs($delegate)
            ->putJson(route('admin.groups.update', $insideGroup), [
                'name' => $insideGroup->name,
                'type' => $insideGroup->type,
                'can_access_group_ids' => (string) $secondInsideGroup->id,
            ])
            ->assertOk();

        $this->assertDatabaseHas('user_group_access', [
            'group_id' => $insideGroup->id,
            'can_access_group_id' => $outsideGroup->id,
        ]);
        $this->assertDatabaseHas('user_group_access', [
            'group_id' => $insideGroup->id,
            'can_access_group_id' => $secondInsideGroup->id,
        ]);

        $deviceGroup = DeviceGroup::create(['name' => 'Scoped device group']);
        Device::create([
            'rustdesk_id' => 'grouped-peer',
            'uuid' => 'uuid-grouped-peer',
            'user_id' => $delegate->id,
            'device_group_id' => $deviceGroup->id,
        ]);
        DeviceGroupAccess::create([
            'group_id' => $outsideGroup->id,
            'device_group_id' => $deviceGroup->id,
        ]);

        $this->actingAs($delegate)
            ->putJson(route('admin.device-groups.update', $deviceGroup), [
                'name' => $deviceGroup->name,
                'access_group_ids' => (string) $secondInsideGroup->id,
            ])
            ->assertOk();

        $this->assertDatabaseHas('device_group_access', [
            'group_id' => $outsideGroup->id,
            'device_group_id' => $deviceGroup->id,
        ]);
        $this->assertDatabaseHas('device_group_access', [
            'group_id' => $secondInsideGroup->id,
            'device_group_id' => $deviceGroup->id,
        ]);
    }

    /**
     * @param  list<string>  $permissions
     * @return array{User, User, User, Device, Device}
     */
    private function groupFixture(array $permissions): array
    {
        $insideGroup = Group::create(['name' => 'Inside scope', 'type' => Group::TYPE_DEFAULT]);
        $outsideGroup = Group::create(['name' => 'Outside scope', 'type' => Group::TYPE_DEFAULT]);
        $delegate = $this->user('delegate', $insideGroup);
        $insideUser = $this->user('inside-user', $insideGroup);
        $outsideUser = $this->user('outside-user', $outsideGroup);
        $insideDevice = $this->device('inside-peer', $insideUser, 'Inside workstation');
        $outsideDevice = $this->device('outside-peer', $outsideUser, 'Outside workstation');

        $delegate->adminRoles()->attach(AdminRole::create([
            'name' => 'Scoped operations',
            'type' => AdminRole::TYPE_GROUP,
            'scope' => [$insideGroup->id],
            'perms' => $permissions,
        ]));

        return [$delegate, $insideUser, $outsideUser, $insideDevice, $outsideDevice];
    }

    private function user(string $username, ?Group $group = null): User
    {
        return User::create([
            'username' => $username,
            'password' => 'secret12345',
            'status' => User::STATUS_NORMAL,
            'group_id' => $group?->id,
        ]);
    }

    private function device(string $peerId, User $owner, string $hostname): Device
    {
        return Device::create([
            'rustdesk_id' => $peerId,
            'uuid' => 'uuid-'.$peerId,
            'user_id' => $owner->id,
            'hostname' => $hostname,
        ]);
    }
}
