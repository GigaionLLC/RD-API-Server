<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Strategy;
use App\Models\User;
use App\Services\StrategyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Default-strategy fallback (applied to devices with no device/user/group assignment) and the
 * "apply to all matching the filter" bulk action on the devices list.
 */
class DefaultStrategyAndBulkAllTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'username' => 'admin'.uniqid(), 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);
    }

    public function test_default_strategy_is_the_fallback_for_an_unassigned_device(): void
    {
        Strategy::create(['name' => 'Baseline', 'enabled' => true, 'is_default' => true, 'options' => ['enable-audio' => 'N'], 'modified_at' => 5]);
        $device = Device::create(['rustdesk_id' => 'd1', 'uuid' => 'u1']);

        $resolved = app(StrategyService::class)->resolveForDevice($device);
        $this->assertNotNull($resolved);
        $this->assertSame('Baseline', $resolved->name);
    }

    public function test_disabled_default_is_not_applied(): void
    {
        Strategy::create(['name' => 'Off', 'enabled' => false, 'is_default' => true, 'options' => [], 'modified_at' => 1]);
        $device = Device::create(['rustdesk_id' => 'd1', 'uuid' => 'u1']);

        $this->assertNull(app(StrategyService::class)->resolveForDevice($device));
    }

    public function test_heartbeat_pushes_the_default_strategy_to_a_new_device(): void
    {
        Strategy::create(['name' => 'Baseline', 'enabled' => true, 'is_default' => true, 'options' => ['enable-audio' => 'N'], 'modified_at' => 7]);

        $res = $this->postJson('/api/heartbeat', ['id' => 'new-dev', 'uuid' => 'uuid-1', 'modified_at' => 0]);
        $res->assertOk();
        $this->assertSame(7, $res->json('modified_at'));
        $this->assertSame('N', $res->json('strategy.config_options.enable-audio'));
    }

    public function test_set_default_keeps_only_one(): void
    {
        $admin = $this->admin();
        $a = Strategy::create(['name' => 'A', 'enabled' => true, 'options' => [], 'modified_at' => 1]);
        $b = Strategy::create(['name' => 'B', 'enabled' => true, 'options' => [], 'modified_at' => 1]);

        $this->actingAs($admin)->post(route('admin.strategies.default', $a))->assertRedirect();
        $this->assertTrue($a->refresh()->is_default);

        $this->actingAs($admin)->post(route('admin.strategies.default', $b))->assertRedirect();
        $this->assertFalse($a->refresh()->is_default);
        $this->assertTrue($b->refresh()->is_default);

        // Toggling B off leaves no default.
        $this->actingAs($admin)->post(route('admin.strategies.default', $b))->assertRedirect();
        $this->assertFalse($b->refresh()->is_default);
    }

    public function test_bulk_all_matching_updates_the_whole_filtered_set(): void
    {
        $admin = $this->admin();
        $owner = User::create(['username' => 'owner', 'password' => 'secret12345', 'status' => User::STATUS_NORMAL]);

        Device::create(['rustdesk_id' => 'web-1', 'uuid' => 'a', 'hostname' => 'web-1']);
        Device::create(['rustdesk_id' => 'web-2', 'uuid' => 'b', 'hostname' => 'web-2']);
        Device::create(['rustdesk_id' => 'db-1', 'uuid' => 'c', 'hostname' => 'db-1']);

        // Apply to all matching q=web (no explicit ids) — both web hosts, not the db host.
        $this->actingAs($admin)->post(route('admin.devices.bulk'), [
            'all' => '1', 'q' => 'web', 'field' => 'user_id', 'value' => $owner->id,
        ])->assertRedirect();

        $this->assertSame($owner->id, Device::where('rustdesk_id', 'web-1')->value('user_id'));
        $this->assertSame($owner->id, Device::where('rustdesk_id', 'web-2')->value('user_id'));
        $this->assertNull(Device::where('rustdesk_id', 'db-1')->value('user_id')); // filtered out
    }

    public function test_bulk_without_ids_or_all_is_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.devices.bulk'), [
            'field' => 'user_id', 'value' => null,
        ])->assertSessionHasErrors('ids');
    }
}
