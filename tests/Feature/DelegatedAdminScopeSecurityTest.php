<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\DeployToken;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Group;
use App\Models\Strategy;
use App\Models\StrategyAssignment;
use App\Models\User;
use App\Services\AdminScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Delegated (group-scoped) administrators must not reach devices outside their scope through
 * strategy edits or deploy-token `--assign`, and never set server-redirect options.
 */
class DelegatedAdminScopeSecurityTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{User, User, User, Device, Device} */
    private function fixture(array $permissions): array
    {
        $inside = Group::create(['name' => 'Inside', 'type' => Group::TYPE_DEFAULT]);
        $outside = Group::create(['name' => 'Outside', 'type' => Group::TYPE_DEFAULT]);
        $delegate = User::create(['username' => 'delegate', 'password' => 'secret12345', 'status' => User::STATUS_NORMAL, 'group_id' => $inside->id]);
        $insideUser = User::create(['username' => 'inside-user', 'password' => 'secret12345', 'status' => User::STATUS_NORMAL, 'group_id' => $inside->id]);
        $outsideUser = User::create(['username' => 'outside-user', 'password' => 'secret12345', 'status' => User::STATUS_NORMAL, 'group_id' => $outside->id]);
        $insideDevice = Device::create(['rustdesk_id' => 'inside-peer', 'uuid' => 'uuid-inside', 'user_id' => $insideUser->id, 'approved' => true]);
        $outsideDevice = Device::create(['rustdesk_id' => 'outside-peer', 'uuid' => 'uuid-outside', 'user_id' => $outsideUser->id, 'approved' => true]);

        $delegate->adminRoles()->attach(AdminRole::create([
            'name' => 'Scoped', 'type' => AdminRole::TYPE_GROUP, 'scope' => [$inside->id], 'perms' => $permissions,
        ]));

        return [$delegate, $insideUser, $outsideUser, $insideDevice, $outsideDevice];
    }

    public function test_strategy_directly_assigned_to_an_out_of_scope_device_is_out_of_scope(): void
    {
        [$delegate, , , $insideDevice, $outsideDevice] = $this->fixture(['strategies.view', 'strategies.edit']);
        $strategy = Strategy::create(['name' => 'Shared', 'enabled' => true, 'options' => [], 'modified_at' => 1]);
        $strategy->assignments()->create(['target_type' => StrategyAssignment::TARGET_DEVICE, 'target_id' => $insideDevice->id]);

        $scope = new AdminScopeService;
        $this->assertContains($strategy->id, $scope->strategyIds($delegate, 'strategies.edit'));

        $outsideDevice->forceFill(['strategy_id' => $strategy->id])->save();

        $scope = new AdminScopeService;
        $this->assertNotContains($strategy->id, $scope->strategyIds($delegate, 'strategies.edit'));

        $this->actingAs($delegate)
            ->putJson(route('admin.strategies.update', $strategy), [
                'name' => 'Shared', 'enabled' => 1,
                'option_keys' => ['custom-rendezvous-server'], 'option_values' => ['evil.example'],
            ])->assertForbidden();
        $this->assertSame([], $strategy->refresh()->options);
    }

    public function test_delegate_cannot_set_or_change_server_redirect_options(): void
    {
        [$delegate, , , $insideDevice] = $this->fixture(['strategies.view', 'strategies.edit']);
        $strategy = Strategy::create([
            'name' => 'Inside policy', 'enabled' => true, 'modified_at' => 1,
            'options' => ['relay-server' => 'relay.corp.example', 'enable-audio' => 'Y'],
        ]);
        $strategy->assignments()->create(['target_type' => StrategyAssignment::TARGET_DEVICE, 'target_id' => $insideDevice->id]);

        foreach ([
            ['option_keys' => ['relay-server', 'custom-rendezvous-server'], 'option_values' => ['relay.corp.example', 'evil.example']],
            ['option_keys' => ['relay-server'], 'option_values' => ['evil.example']],
            ['option_keys' => ['Relay-Server'], 'option_values' => ['evil.example']],
            ['option_keys' => [], 'option_values' => []],
            ['opt' => ['relay-server' => 'relay.corp.example', 'proxy-url' => 'socks5://evil.example']],
        ] as $payload) {
            $this->actingAs($delegate)
                ->putJson(route('admin.strategies.update', $strategy), ['name' => 'Inside policy', 'enabled' => 1] + $payload)
                ->assertUnprocessable();
        }
        $this->assertSame('relay.corp.example', $strategy->refresh()->options['relay-server']);

        // Keeping the stored value while editing other options is fine.
        $this->actingAs($delegate)
            ->putJson(route('admin.strategies.update', $strategy), [
                'name' => 'Inside policy', 'enabled' => 1,
                'option_keys' => ['relay-server', 'enable-audio'], 'option_values' => ['relay.corp.example', 'N'],
            ])->assertOk();
        $this->assertSame(['relay-server' => 'relay.corp.example', 'enable-audio' => 'N'], $strategy->refresh()->options);
    }

    public function test_full_admin_can_still_set_server_redirect_options(): void
    {
        $admin = User::create(['username' => 'root', 'password' => 'secret12345', 'status' => User::STATUS_NORMAL, 'is_admin' => true]);
        $strategy = Strategy::create(['name' => 'Global', 'enabled' => true, 'options' => [], 'modified_at' => 1]);

        $this->actingAs($admin)
            ->putJson(route('admin.strategies.update', $strategy), [
                'name' => 'Global', 'enabled' => 1,
                'option_keys' => ['custom-rendezvous-server', 'key'], 'option_values' => ['hbbs.corp.example', 'PUBKEY='],
            ])->assertOk();

        $this->assertSame('hbbs.corp.example', $strategy->refresh()->options['custom-rendezvous-server']);
    }

    public function test_delegate_deploy_token_cli_is_bounded_by_the_owners_scope(): void
    {
        config()->set('rustdesk.devices.require_deployment', true);
        [$delegate, , , $insideDevice, $outsideDevice] = $this->fixture(['deploy.view', 'deploy.edit']);
        DeployToken::create(['user_id' => $delegate->id, 'token' => 'delegate-token', 'name' => 'Scoped rollout']);

        $outsideStrategy = Strategy::create(['name' => 'Outside policy', 'enabled' => true, 'options' => [], 'modified_at' => 1]);
        $outsideStrategy->assignments()->create(['target_type' => StrategyAssignment::TARGET_DEVICE, 'target_id' => $outsideDevice->id]);
        $insideStrategy = Strategy::create(['name' => 'Inside policy', 'enabled' => true, 'options' => [], 'modified_at' => 1]);
        $insideStrategy->assignments()->create(['target_type' => StrategyAssignment::TARGET_DEVICE, 'target_id' => $insideDevice->id]);
        $outsideGroup = DeviceGroup::create(['name' => 'Outside devices']);
        $outsideDevice->forceFill(['device_group_id' => $outsideGroup->id])->save();

        $cli = fn (array $body) => $this->withHeader('Authorization', 'Bearer delegate-token')
            ->postJson('/api/devices/cli', $body)->assertOk()->getContent();

        // Out-of-scope strategy, unknown/out-of-scope group, and adopting someone else's device.
        $this->assertStringContainsString('scope', $cli(['id' => 'new-1', 'uuid' => 'u-new-1', 'strategy_name' => 'Outside policy']));
        $this->assertStringContainsString('scope', $cli(['id' => 'new-2', 'uuid' => 'u-new-2', 'device_group_name' => 'Brand new']));
        $this->assertStringContainsString('scope', $cli(['id' => 'new-3', 'uuid' => 'u-new-3', 'device_group_name' => 'Outside devices']));
        $this->assertStringContainsString('scope', $cli(['id' => 'outside-peer', 'uuid' => 'uuid-outside']));

        $this->assertDatabaseMissing('devices', ['rustdesk_id' => 'new-1']);
        $this->assertDatabaseMissing('device_groups', ['name' => 'Brand new']);
        $this->assertNotSame($delegate->id, $outsideDevice->refresh()->user_id);
        $this->assertNull($outsideDevice->strategy_id);

        // /api/devices/deploy refuses to adopt the out-of-scope device as well.
        $this->withHeader('Authorization', 'Bearer delegate-token')
            ->postJson('/api/devices/deploy', ['id' => 'outside-peer', 'uuid' => 'uuid-outside', 'pk' => ''])
            ->assertOk()->assertJson(['result' => 'ID_TAKEN']);

        // In-scope strategy for a brand-new device still works.
        $this->assertSame('', $cli(['id' => 'new-4', 'uuid' => 'u-new-4', 'strategy_name' => 'Inside policy']));
        $device = Device::where('rustdesk_id', 'new-4')->firstOrFail();
        $this->assertSame($insideStrategy->id, $device->strategy_id);
        $this->assertSame($delegate->id, $device->user_id);
    }

    public function test_full_admin_deploy_token_keeps_its_existing_behaviour(): void
    {
        $admin = User::create(['username' => 'root', 'password' => 'secret12345', 'status' => User::STATUS_NORMAL, 'is_admin' => true]);
        DeployToken::create(['user_id' => $admin->id, 'token' => 'admin-token', 'name' => 'Rollout']);
        Strategy::create(['name' => 'Anything', 'enabled' => true, 'options' => [], 'modified_at' => 1]);

        $this->withHeader('Authorization', 'Bearer admin-token')
            ->postJson('/api/devices/cli', [
                'id' => 'fleet-1', 'uuid' => 'fleet-uuid', 'strategy_name' => 'Anything', 'device_group_name' => 'Created on demand',
            ])->assertOk()->assertContent('');

        $this->assertDatabaseHas('device_groups', ['name' => 'Created on demand']);
    }
}
