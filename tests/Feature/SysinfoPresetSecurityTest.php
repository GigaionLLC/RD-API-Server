<?php

namespace Tests\Feature;

use App\Models\AddressBook;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Strategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Device-supplied OPTION_PRESET_* values on /api/sysinfo: they file a newly enrolled device
 * once, fill only blanks, and can never override an administrator's assignment or create groups.
 */
class SysinfoPresetSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function sysinfo(array $body): void
    {
        $this->postJson('/api/sysinfo', $body)->assertOk()->assertSeeText('SYSINFO_UPDATED');
    }

    public function test_first_upload_of_a_newly_enrolled_device_applies_presets(): void
    {
        $strategy = Strategy::create(['name' => 'Kiosk', 'enabled' => true, 'options' => [], 'modified_at' => 1]);
        $group = DeviceGroup::create(['name' => 'Warehouse']);
        $device = Device::create(['rustdesk_id' => 'new-1', 'uuid' => 'uuid-1', 'approved' => true]);

        $this->sysinfo([
            'id' => 'new-1', 'uuid' => 'uuid-1', 'hostname' => 'host-1', 'os' => 'Windows',
            'strategy_name' => 'Kiosk', 'device_group_name' => 'Warehouse',
            'device_name' => 'Front desk', 'note' => 'Lobby',
            'address_book_name' => 'Rollout', 'address_book_tag' => 'lobby',
        ]);

        $device->refresh();
        $this->assertSame($strategy->id, $device->strategy_id);
        $this->assertSame($group->id, $device->device_group_id);
        $this->assertSame('Front desk', $device->device_name);
        $this->assertSame('Lobby', $device->note);
        $this->assertNotNull($device->presets_applied_at);
        $this->assertDatabaseHas('address_books', ['name' => 'Rollout']);
        $this->assertDatabaseHas('address_book_peers', ['rustdesk_id' => 'new-1']);
    }

    public function test_auto_registered_device_is_filed_by_its_presets(): void
    {
        config()->set('rustdesk.devices.require_deployment', false);
        config()->set('rustdesk.devices.auto_register', true);
        $strategy = Strategy::create(['name' => 'Branch', 'enabled' => true, 'options' => [], 'modified_at' => 1]);
        $group = DeviceGroup::create(['name' => 'Branch PCs']);

        $this->sysinfo([
            'id' => 'auto-1', 'uuid' => 'auto-uuid', 'hostname' => 'branch-01',
            'strategy_name' => 'Branch', 'device_group_name' => 'Branch PCs',
        ]);

        $device = Device::where('rustdesk_id', 'auto-1')->firstOrFail();
        $this->assertSame($strategy->id, $device->strategy_id);
        $this->assertSame($group->id, $device->device_group_id);
    }

    public function test_default_group_placement_from_heartbeat_still_yields_to_the_group_preset(): void
    {
        $default = DeviceGroup::create(['name' => 'Default', 'is_default' => true]);
        $target = DeviceGroup::create(['name' => 'Finance']);
        $device = Device::create(['rustdesk_id' => 'hb-first', 'uuid' => 'hb-uuid', 'approved' => true]);

        // A heartbeat usually precedes the first sysinfo and parks the device in the default group.
        $this->postJson('/api/heartbeat', ['id' => 'hb-first', 'uuid' => 'hb-uuid', 'modified_at' => 0])->assertOk();
        $this->assertSame($default->id, $device->refresh()->device_group_id);

        $this->sysinfo(['id' => 'hb-first', 'uuid' => 'hb-uuid', 'device_group_name' => 'Finance']);

        $this->assertSame($target->id, $device->refresh()->device_group_id);
    }

    public function test_admin_assignment_is_not_overwritten_by_sysinfo(): void
    {
        $adminStrategy = Strategy::create(['name' => 'Locked down', 'enabled' => true, 'options' => [], 'modified_at' => 1]);
        Strategy::create(['name' => 'Permissive', 'enabled' => true, 'options' => ['default-connect-password' => 'fleet-secret'], 'modified_at' => 1]);
        $adminGroup = DeviceGroup::create(['name' => 'Servers']);
        DeviceGroup::create(['name' => 'Lobby']);
        $device = Device::create([
            'rustdesk_id' => 'managed-1', 'uuid' => 'managed-uuid', 'approved' => true,
            'strategy_id' => $adminStrategy->id, 'device_group_id' => $adminGroup->id,
            'device_name' => 'Admin label',
        ]);

        // Even inside the enrollment window, an existing assignment is never replaced.
        $this->sysinfo([
            'id' => 'managed-1', 'uuid' => 'managed-uuid',
            'strategy_name' => 'Permissive', 'device_group_name' => 'Lobby', 'device_name' => 'DC01',
        ]);

        $device->refresh();
        $this->assertSame($adminStrategy->id, $device->strategy_id);
        $this->assertSame($adminGroup->id, $device->device_group_id);
        $this->assertSame('Admin label', $device->device_name);

        $this->postJson('/api/heartbeat', ['id' => 'managed-1', 'uuid' => 'managed-uuid', 'modified_at' => 0])
            ->assertOk()
            ->assertJsonMissingPath('strategy.config_options.default-connect-password');
    }

    public function test_presets_are_ignored_after_the_first_upload(): void
    {
        Strategy::create(['name' => 'Permissive', 'enabled' => true, 'options' => ['default-connect-password' => 'fleet-secret'], 'modified_at' => 5]);
        DeviceGroup::create(['name' => 'Operators']);
        $device = Device::create(['rustdesk_id' => 'enrolled-1', 'uuid' => 'enrolled-uuid', 'approved' => true]);

        $this->sysinfo(['id' => 'enrolled-1', 'uuid' => 'enrolled-uuid', 'hostname' => 'pc-1']);
        $this->assertNotNull($device->refresh()->presets_applied_at);

        // A local user who can read the id + uuid replays sysinfo with hand-picked presets.
        $this->sysinfo([
            'id' => 'enrolled-1', 'uuid' => 'enrolled-uuid', 'hostname' => 'pc-1b',
            'strategy_name' => 'Permissive', 'device_group_name' => 'Operators',
            'device_name' => 'DC01 - Domain Controller', 'address_book_name' => 'My address book',
        ]);

        $device->refresh();
        $this->assertSame('pc-1b', $device->hostname); // inventory still updates
        $this->assertNull($device->strategy_id);
        $this->assertNotSame(DeviceGroup::where('name', 'Operators')->value('id'), $device->device_group_id);
        $this->assertNull($device->device_name);
        $this->assertDatabaseMissing('address_books', ['name' => 'My address book']);

        $this->postJson('/api/heartbeat', ['id' => 'enrolled-1', 'uuid' => 'enrolled-uuid', 'modified_at' => 0])
            ->assertOk()
            ->assertJsonMissingPath('strategy.config_options.default-connect-password');
    }

    public function test_device_supplied_group_names_never_create_groups(): void
    {
        $device = Device::create(['rustdesk_id' => 'nogroup-1', 'uuid' => 'nogroup-uuid', 'approved' => true]);

        $this->sysinfo(['id' => 'nogroup-1', 'uuid' => 'nogroup-uuid', 'device_group_name' => 'Brand new group']);

        $this->assertDatabaseMissing('device_groups', ['name' => 'Brand new group']);
        $this->assertNotNull($device->refresh()->presets_applied_at);
    }

    public function test_non_string_presets_are_ignored_without_an_error(): void
    {
        Device::create(['rustdesk_id' => 'array-1', 'uuid' => 'array-uuid', 'approved' => true, 'hostname' => 'kept']);

        $this->sysinfo([
            'id' => 'array-1', 'uuid' => 'array-uuid', 'hostname' => ['x'],
            'strategy_name' => ['Default'], 'device_group_name' => ['a' => 'b'],
            'address_book_name' => ['book'], 'device_name' => ['n'],
        ]);

        $this->assertSame('kept', Device::where('rustdesk_id', 'array-1')->value('hostname'));
        $this->assertSame(0, AddressBook::count());
    }
}
