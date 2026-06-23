<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Strategy;
use App\Models\StrategyAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The default device group: new and currently-ungrouped devices are placed into the
 * designated default group on registration, so a group-level strategy applies to them
 * instead of them landing in "None".
 */
class DefaultDeviceGroupTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_device_joins_the_default_group_on_heartbeat(): void
    {
        $group = DeviceGroup::create(['name' => 'Default', 'is_default' => true]);

        $this->postJson('/api/heartbeat', ['id' => 'newdev', 'uuid' => 'u'])->assertOk();

        $this->assertSame($group->id, Device::where('rustdesk_id', 'newdev')->value('device_group_id'));
    }

    public function test_existing_ungrouped_device_is_adopted_on_next_heartbeat(): void
    {
        $device = Device::create(['rustdesk_id' => 'old', 'uuid' => 'u']); // device_group_id null
        $group = DeviceGroup::create(['name' => 'Default', 'is_default' => true]);

        $this->postJson('/api/heartbeat', ['id' => 'old', 'uuid' => 'u'])->assertOk();

        $this->assertSame($group->id, $device->fresh()->device_group_id);
    }

    public function test_auto_default_promotes_an_existing_group_when_none_is_default(): void
    {
        // No group is marked default, but auto-defaulting is on (the default): the oldest group
        // is promoted and the new device joins it.
        $group = DeviceGroup::create(['name' => 'Ordinary']);

        $this->postJson('/api/heartbeat', ['id' => 'x', 'uuid' => 'u'])->assertOk();

        $this->assertTrue($group->fresh()->is_default);
        $this->assertSame($group->id, Device::where('rustdesk_id', 'x')->value('device_group_id'));
    }

    public function test_auto_default_creates_a_group_when_none_exist(): void
    {
        $this->postJson('/api/heartbeat', ['id' => 'x', 'uuid' => 'u'])->assertOk();

        $group = DeviceGroup::where('is_default', true)->first();
        $this->assertNotNull($group);
        $this->assertSame('Default', $group->name);
        $this->assertSame($group->id, Device::where('rustdesk_id', 'x')->value('device_group_id'));
    }

    public function test_auto_default_can_be_disabled(): void
    {
        config(['rustdesk.devices.auto_default_group' => false]);
        DeviceGroup::create(['name' => 'Ordinary']); // not default

        $this->postJson('/api/heartbeat', ['id' => 'x', 'uuid' => 'u'])->assertOk();

        $this->assertNull(Device::where('rustdesk_id', 'x')->value('device_group_id'));
    }

    public function test_first_group_created_in_admin_becomes_default(): void
    {
        $admin = User::create([
            'username' => 'admin', 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);

        $this->actingAs($admin)->post(route('admin.device-groups.store'), ['name' => 'First'])->assertRedirect();
        $this->assertTrue(DeviceGroup::where('name', 'First')->value('is_default'));

        // A second group does NOT steal the default.
        $this->actingAs($admin)->post(route('admin.device-groups.store'), ['name' => 'Second'])->assertRedirect();
        $this->assertFalse((bool) DeviceGroup::where('name', 'Second')->value('is_default'));
    }

    public function test_default_group_strategy_is_pushed_in_the_same_heartbeat(): void
    {
        $group = DeviceGroup::create(['name' => 'Default', 'is_default' => true]);
        $strategy = Strategy::create([
            'name' => 'Group policy', 'enabled' => true,
            'options' => ['enable-audio' => 'N'], 'modified_at' => 10,
        ]);
        StrategyAssignment::create([
            'strategy_id' => $strategy->id,
            'target_type' => StrategyAssignment::TARGET_DEVICE_GROUP,
            'target_id' => $group->id,
        ]);

        // First heartbeat: the device joins the default group AND resolves the group strategy.
        $this->postJson('/api/heartbeat', ['id' => 'd1', 'uuid' => 'u', 'modified_at' => 0])
            ->assertOk()
            ->assertJsonPath('strategy.config_options.enable-audio', 'N');
    }

    public function test_index_explains_default_replacement(): void
    {
        $admin = User::create([
            'username' => 'admin', 'password' => 'secret12345', 'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);
        DeviceGroup::create(['name' => 'Alpha', 'is_default' => true]);
        DeviceGroup::create(['name' => 'Beta']);

        $this->actingAs($admin)->get(route('admin.device-groups.index'))
            ->assertOk()
            ->assertSee('Currently')
            ->assertSee('Alpha')                       // names the current default
            ->assertSee('replaces the current default', false)  // explicit replace warning on Beta
            ->assertSee('reverts to a normal group', false);    // and what happens to the old one
    }

    public function test_saving_a_group_as_default_clears_any_other_default(): void
    {
        // Even bypassing the controller (e.g. a direct create with is_default=true), the model
        // hook enforces exactly one default.
        $a = DeviceGroup::create(['name' => 'A', 'is_default' => true]);
        $b = DeviceGroup::create(['name' => 'B', 'is_default' => true]);

        $this->assertFalse($a->fresh()->is_default);
        $this->assertTrue($b->fresh()->is_default);
        $this->assertSame(1, DeviceGroup::where('is_default', true)->count());
    }

    public function test_default_id_is_deterministic_with_stray_duplicates(): void
    {
        // Simulate a direct DB edit that left two defaults: defaultId picks the oldest (lowest id).
        $a = DeviceGroup::create(['name' => 'A']);
        $b = DeviceGroup::create(['name' => 'B']);
        DeviceGroup::query()->whereIn('id', [$a->id, $b->id])->update(['is_default' => true]);

        $this->assertSame($a->id, DeviceGroup::defaultId());
    }

    public function test_set_default_is_exclusive_and_toggles(): void
    {
        $admin = User::create([
            'username' => 'admin', 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);
        $a = DeviceGroup::create(['name' => 'A', 'is_default' => true]);
        $b = DeviceGroup::create(['name' => 'B']);

        // Marking B default clears A.
        $this->actingAs($admin)->post(route('admin.device-groups.default', $b))
            ->assertRedirect(route('admin.device-groups.index'));
        $this->assertFalse($a->fresh()->is_default);
        $this->assertTrue($b->fresh()->is_default);

        // Toggling B again clears the default entirely.
        $this->actingAs($admin)->post(route('admin.device-groups.default', $b));
        $this->assertFalse($b->fresh()->is_default);
    }
}
