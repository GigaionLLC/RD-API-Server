<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Strategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DeviceTelemetrySecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_heartbeat_requires_the_stored_uuid_before_returning_strategy_or_disconnects(): void
    {
        $strategy = Strategy::create([
            'name' => 'Sensitive policy',
            'enabled' => true,
            'options' => [
                'default-connect-password' => 'do-not-disclose',
                'proxy-password' => 'proxy-secret',
            ],
            'modified_at' => 100,
        ]);
        $device = Device::create([
            'rustdesk_id' => 'known-device',
            'uuid' => 'trusted-uuid',
            'strategy_id' => $strategy->id,
            'approved' => true,
            'hostname' => 'Trusted host',
        ]);
        Cache::put('rd:disconnect:known-device', [42], now()->addMinutes(5));

        foreach (['', 'attacker-uuid'] as $uuid) {
            $response = $this->postJson('/api/heartbeat', [
                'id' => 'known-device',
                'uuid' => $uuid,
                'modified_at' => 0,
                'conns' => [1, 2, 3],
            ])->assertOk();

            $this->assertSame('{}', $response->getContent());
            $this->assertSame('trusted-uuid', $device->refresh()->uuid);
            $this->assertSame('Trusted host', $device->hostname);
            $this->assertFalse($device->is_online);
            $this->assertSame([42], Cache::get('rd:disconnect:known-device'));
        }

        $this->postJson('/api/heartbeat', [
            'id' => 'known-device',
            'uuid' => 'trusted-uuid',
            'modified_at' => 0,
        ])->assertOk()
            ->assertJsonPath('strategy.config_options.default-connect-password', 'do-not-disclose')
            ->assertJsonPath('disconnect.0', 42);

        $this->assertNull(Cache::get('rd:disconnect:known-device'));
        $this->assertTrue($device->refresh()->is_online);
    }

    public function test_sysinfo_rejects_wrong_identity_without_mutating_inventory_or_presets(): void
    {
        $device = Device::create([
            'rustdesk_id' => 'inventory-device',
            'uuid' => 'trusted-uuid',
            'hostname' => 'Original hostname',
            'approved' => true,
        ]);

        $this->postJson('/api/sysinfo', [
            'id' => 'inventory-device',
            'uuid' => 'wrong-uuid',
            'hostname' => 'Attacker hostname',
            'strategy_name' => 'Attacker strategy',
            'device_group_name' => 'Attacker group',
            'address_book_name' => 'Attacker book',
        ])->assertOk()->assertSeeText('ID_NOT_FOUND');

        $device->refresh();
        $this->assertSame('trusted-uuid', $device->uuid);
        $this->assertSame('Original hostname', $device->hostname);
        $this->assertNull($device->strategy_id);
        $this->assertNull($device->device_group_id);
        $this->assertDatabaseMissing('device_groups', ['name' => 'Attacker group']);
        $this->assertDatabaseMissing('address_books', ['name' => 'Attacker book']);
    }

    public function test_deployment_gate_blocks_heartbeat_registration_and_unapproved_telemetry(): void
    {
        config()->set('rustdesk.devices.require_deployment', true);
        config()->set('rustdesk.devices.auto_register', true);

        $this->postJson('/api/heartbeat', [
            'id' => 'unknown-device',
            'uuid' => 'unknown-uuid',
            'modified_at' => 0,
        ])->assertOk()->assertExactJson([]);
        $this->assertDatabaseMissing('devices', ['rustdesk_id' => 'unknown-device']);

        $strategy = Strategy::create([
            'name' => 'Pending secret',
            'enabled' => true,
            'options' => ['default-connect-password' => 'pending-secret'],
            'modified_at' => 200,
        ]);
        $device = Device::create([
            'rustdesk_id' => 'pending-device',
            'uuid' => 'pending-uuid',
            'strategy_id' => $strategy->id,
            'approved' => false,
            'hostname' => 'Pending host',
        ]);
        Cache::put('rd:disconnect:pending-device', [9], now()->addMinutes(5));

        $this->postJson('/api/heartbeat', [
            'id' => 'pending-device',
            'uuid' => 'pending-uuid',
            'modified_at' => 0,
        ])->assertOk()->assertExactJson([]);
        $this->assertSame([9], Cache::get('rd:disconnect:pending-device'));
        $this->assertFalse($device->refresh()->is_online);

        $this->postJson('/api/sysinfo', [
            'id' => 'pending-device',
            'uuid' => 'pending-uuid',
            'hostname' => 'Changed while pending',
        ])->assertOk()->assertSeeText('ID_NOT_FOUND');
        $this->assertSame('Pending host', $device->refresh()->hostname);
    }

    public function test_auto_registration_requires_both_bounded_identity_fields(): void
    {
        config()->set('rustdesk.devices.require_deployment', false);
        config()->set('rustdesk.devices.auto_register', true);

        foreach ([
            ['id' => 'missing-uuid', 'uuid' => ''],
            ['id' => '', 'uuid' => 'orphan-uuid'],
            ['id' => str_repeat('a', 256), 'uuid' => 'uuid'],
            ['id' => 'oversized-uuid', 'uuid' => str_repeat('b', 256)],
        ] as $identity) {
            $this->postJson('/api/heartbeat', $identity)->assertOk();
        }

        $this->assertDatabaseCount('devices', 0);
    }
}
