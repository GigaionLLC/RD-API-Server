<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bulk-assign on the devices list (owner / device group / strategy) and the live-search
 * endpoints that back the searchable comboboxes.
 */
class DeviceBulkAndSearchTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'username' => 'admin', 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);
    }

    public function test_bulk_assigns_strategy_to_only_the_selected_devices(): void
    {
        $s = Strategy::create(['name' => 'P', 'enabled' => true, 'options' => [], 'modified_at' => 1]);
        [$d1, $d2, $d3] = [
            Device::create(['rustdesk_id' => 'a1', 'uuid' => 'u1']),
            Device::create(['rustdesk_id' => 'a2', 'uuid' => 'u2']),
            Device::create(['rustdesk_id' => 'a3', 'uuid' => 'u3']),
        ];

        $this->actingAs($this->admin())->post(route('admin.devices.bulk'), [
            'ids' => [$d1->id, $d2->id], 'field' => 'strategy_id', 'value' => $s->id,
        ])->assertRedirect();

        $this->assertSame($s->id, $d1->fresh()->strategy_id);
        $this->assertSame($s->id, $d2->fresh()->strategy_id);
        $this->assertNull($d3->fresh()->strategy_id);
    }

    public function test_bulk_assigns_owner_and_group(): void
    {
        $owner = User::create(['username' => 'bob', 'password' => 'secret12345', 'status' => User::STATUS_NORMAL]);
        $group = DeviceGroup::create(['name' => 'Warehouse']);
        $d = Device::create(['rustdesk_id' => 'a4', 'uuid' => 'u4']);

        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.devices.bulk'), [
            'ids' => [$d->id], 'field' => 'user_id', 'value' => $owner->id,
        ])->assertRedirect();
        $this->actingAs($admin)->post(route('admin.devices.bulk'), [
            'ids' => [$d->id], 'field' => 'device_group_id', 'value' => $group->id,
        ])->assertRedirect();

        $d->refresh();
        $this->assertSame($owner->id, $d->user_id);
        $this->assertSame($group->id, $d->device_group_id);
    }

    public function test_bulk_blank_value_clears_the_field(): void
    {
        $group = DeviceGroup::create(['name' => 'G']);
        $d = Device::create(['rustdesk_id' => 'a5', 'uuid' => 'u5', 'device_group_id' => $group->id]);

        $this->actingAs($this->admin())->post(route('admin.devices.bulk'), [
            'ids' => [$d->id], 'field' => 'device_group_id', 'value' => null,
        ])->assertRedirect();

        $this->assertNull($d->fresh()->device_group_id);
    }

    public function test_bulk_rejects_a_field_not_in_the_allow_list(): void
    {
        $d = Device::create(['rustdesk_id' => 'a6', 'uuid' => 'u6', 'alias' => 'keep']);

        $this->actingAs($this->admin())->post(route('admin.devices.bulk'), [
            'ids' => [$d->id], 'field' => 'alias', 'value' => null,
        ])->assertSessionHasErrors('field');

        $this->assertSame('keep', $d->fresh()->alias);
    }

    public function test_device_search_returns_id_and_label(): void
    {
        Device::create(['rustdesk_id' => '123456', 'uuid' => 'u7', 'hostname' => 'web-01']);

        $data = $this->actingAs($this->admin())
            ->getJson(route('admin.devices.search', ['q' => 'web']))
            ->assertOk()
            ->json();

        $this->assertNotEmpty($data);
        $this->assertStringContainsString('web-01', $data[0]['text']);
        $this->assertArrayHasKey('id', $data[0]);
    }

    public function test_user_search_returns_matches(): void
    {
        User::create(['username' => 'alice', 'password' => 'secret12345', 'status' => User::STATUS_NORMAL]);

        $names = collect($this->actingAs($this->admin())
            ->getJson(route('admin.users.search', ['q' => 'ali']))
            ->assertOk()
            ->json())->pluck('text');

        $this->assertTrue($names->contains('alice'));
    }
}
