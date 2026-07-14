<?php

namespace Tests\Feature;

use App\Models\AuditConn;
use App\Models\AuthToken;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\DeviceGroupAccess;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditNoteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_owner_can_fetch_the_guid_and_update_the_note(): void
    {
        [$owner, $token] = $this->account('owner');
        $device = $this->device('owner-peer', $owner);
        $audit = $this->audit($device, 'owner-session');

        $guidResponse = $this->withToken($token)
            ->getJson('/api/audit/conn/active?id=owner-peer&session_id=owner-session&conn_type=0')
            ->assertOk();

        $this->assertSame($audit->guid, $guidResponse->json());

        $noteResponse = $this->withToken($token)
            ->putJson('/api/audit', ['guid' => $audit->guid, 'note' => 'Owner note'])
            ->assertOk();

        $this->assertSame('{}', $noteResponse->getContent());
        $this->assertSame('Owner note', $audit->refresh()->note);
    }

    public function test_unrelated_account_cannot_fetch_or_update_another_devices_note(): void
    {
        [$owner] = $this->account('owner');
        [, $attackerToken] = $this->account('attacker');
        $device = $this->device('private-peer', $owner);
        $audit = $this->audit($device, 'private-session', 'Original note');

        $guidResponse = $this->withToken($attackerToken)
            ->getJson('/api/audit/conn/active?id=private-peer&session_id=private-session&conn_type=0')
            ->assertOk();

        $this->assertSame('""', $guidResponse->getContent());
        $this->assertSame('', $guidResponse->json());

        // PUT is independently scoped so a GUID learned elsewhere is not a write capability.
        $noteResponse = $this->withToken($attackerToken)
            ->putJson('/api/audit', ['guid' => $audit->guid, 'note' => 'Overwritten'])
            ->assertOk();

        $this->assertSame('{}', $noteResponse->getContent());
        $this->assertSame('Original note', $audit->refresh()->note);
    }

    public function test_device_group_grant_allows_the_note_flow(): void
    {
        $group = Group::create(['name' => 'Operators', 'type' => Group::TYPE_DEFAULT]);
        [, $token] = $this->account('operator', false, $group);
        [$owner] = $this->account('fleet-owner');
        $deviceGroup = DeviceGroup::create(['name' => 'Managed fleet']);
        DeviceGroupAccess::create([
            'group_id' => $group->id,
            'device_group_id' => $deviceGroup->id,
        ]);
        $device = $this->device('granted-peer', $owner, $deviceGroup);
        $audit = $this->audit($device, 'granted-session');

        $guid = $this->withToken($token)
            ->getJson('/api/audit/conn/active?id=granted-peer&session_id=granted-session&conn_type=0')
            ->assertOk()
            ->json();

        $this->assertSame($audit->guid, $guid);

        $this->withToken($token)
            ->putJson('/api/audit', ['guid' => $audit->guid, 'note' => 'Granted note'])
            ->assertOk();

        $this->assertSame('Granted note', $audit->refresh()->note);
    }

    public function test_administrator_can_update_any_devices_note(): void
    {
        [, $adminToken] = $this->account('admin', true);
        [$owner] = $this->account('owner');
        $device = $this->device('admin-peer', $owner);
        $audit = $this->audit($device, 'admin-session');

        $guid = $this->withToken($adminToken)
            ->getJson('/api/audit/conn/active?id=admin-peer&session_id=admin-session&conn_type=0')
            ->assertOk()
            ->json();

        $this->assertSame($audit->guid, $guid);

        $this->withToken($adminToken)
            ->putJson('/api/audit', ['guid' => $audit->guid, 'note' => 'Admin note'])
            ->assertOk();

        $this->assertSame('Admin note', $audit->refresh()->note);
    }

    public function test_malformed_or_oversized_note_requests_are_silent_no_ops(): void
    {
        [$owner, $token] = $this->account('owner');
        $device = $this->device('bounded-peer', $owner);
        $audit = $this->audit($device, 'bounded-session', 'Original note');

        foreach ([
            '/api/audit/conn/active?id[]=bounded-peer&session_id=bounded-session',
            '/api/audit/conn/active?id=bounded-peer&session_id[]=bounded-session',
            '/api/audit/conn/active?id='.str_repeat('p', 256).'&session_id=bounded-session',
            '/api/audit/conn/active?id=bounded-peer&session_id='.str_repeat('s', 256),
        ] as $url) {
            $response = $this->withToken($token)->getJson($url)->assertOk();
            $this->assertSame('""', $response->getContent());
        }

        foreach ([
            ['guid' => 'not-a-uuid', 'note' => 'Changed'],
            ['guid' => $audit->guid, 'note' => ['not', 'a', 'string']],
            ['guid' => $audit->guid, 'note' => str_repeat('n', 4001)],
        ] as $payload) {
            $response = $this->withToken($token)->putJson('/api/audit', $payload)->assertOk();
            $this->assertSame('{}', $response->getContent());
            $this->assertSame('Original note', $audit->refresh()->note);
        }
    }

    /**
     * @return array{User, string}
     */
    private function account(string $username, bool $isAdmin = false, ?Group $group = null): array
    {
        $user = User::create([
            'username' => $username,
            'password' => 'secret12345',
            'status' => User::STATUS_NORMAL,
            'is_admin' => $isAdmin,
            'group_id' => $group?->id,
        ]);
        $token = 'audit-note-'.Str::random(48);
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'status' => AuthToken::STATUS_ACTIVE,
        ]);

        return [$user, $token];
    }

    private function device(string $peerId, User $owner, ?DeviceGroup $group = null): Device
    {
        return Device::create([
            'rustdesk_id' => $peerId,
            'uuid' => 'uuid-'.$peerId,
            'user_id' => $owner->id,
            'device_group_id' => $group?->id,
        ]);
    }

    private function audit(Device $device, string $sessionId, ?string $note = null): AuditConn
    {
        return AuditConn::create([
            'guid' => (string) Str::uuid(),
            'action' => AuditConn::ACTION_NEW,
            'conn_id' => 1,
            'peer_id' => $device->rustdesk_id,
            'session_id' => $sessionId,
            'type' => 0,
            'note' => $note,
        ]);
    }
}
