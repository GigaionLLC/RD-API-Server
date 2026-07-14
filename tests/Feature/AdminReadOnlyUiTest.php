<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\DeployToken;
use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\DeviceGroupAccess;
use App\Models\Group;
use App\Models\OauthProvider;
use App\Models\Strategy;
use App\Models\User;
use App\Models\UserGroupAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminReadOnlyUiTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(): User
    {
        $role = AdminRole::create([
            'name' => 'Console viewer',
            'type' => AdminRole::TYPE_INDIVIDUAL,
            'scope' => [],
            'perms' => [
                'devices.view',
                'groups.view',
                'device_groups.view',
                'strategies.view',
                'settings.view',
                'oauth.view',
                'deploy.view',
            ],
        ]);
        $viewer = User::create([
            'username' => 'viewer',
            'password' => 'secret12345',
            'is_admin' => false,
            'status' => User::STATUS_NORMAL,
        ]);
        $viewer->adminRoles()->attach($role);

        return $viewer;
    }

    public function test_read_only_indexes_hide_mutation_controls(): void
    {
        $viewer = $this->viewer();
        $device = Device::create(['rustdesk_id' => 'readonly-device', 'uuid' => 'readonly-device']);
        $group = Group::create(['name' => 'Read-only users', 'type' => Group::TYPE_DEFAULT]);
        $deviceGroup = DeviceGroup::create(['name' => 'Read-only fleet']);
        $strategy = Strategy::create(['name' => 'Read-only policy', 'enabled' => true, 'options' => []]);
        $provider = OauthProvider::create([
            'op' => 'readonly-oidc',
            'type' => 'oidc',
            'client_id' => 'readonly-client',
            'client_secret' => 'stored-secret',
            'issuer' => 'https://id.example.com',
            'enabled' => true,
        ]);
        $token = DeployToken::create([
            'user_id' => $viewer->id,
            'token' => 'readonly-token',
            'name' => 'Read-only token',
        ]);

        $this->actingAs($viewer)->get(route('admin.devices.index'))
            ->assertOk()
            ->assertSee('View')
            ->assertDontSee('id="bulkForm"', false)
            ->assertDontSee('action="'.route('admin.devices.destroy', $device).'"', false);

        $this->actingAs($viewer)->get(route('admin.groups.index'))
            ->assertOk()
            ->assertSee('View')
            ->assertDontSee(route('admin.groups.create'), false)
            ->assertDontSee('action="'.route('admin.groups.destroy', $group).'"', false);

        $this->actingAs($viewer)->get(route('admin.device-groups.index'))
            ->assertOk()
            ->assertSee('View')
            ->assertDontSee(route('admin.device-groups.create'), false)
            ->assertDontSee('action="'.route('admin.device-groups.default', $deviceGroup).'"', false);

        $this->actingAs($viewer)->get(route('admin.strategies.index'))
            ->assertOk()
            ->assertSee('View')
            ->assertDontSee(route('admin.strategies.create'), false)
            ->assertDontSee('action="'.route('admin.strategies.destroy', $strategy).'"', false);

        $this->actingAs($viewer)->get(route('admin.oauth-providers.index'))
            ->assertOk()
            ->assertSee('View')
            ->assertDontSee(route('admin.oauth-providers.create'), false)
            ->assertDontSee('action="'.route('admin.oauth-providers.destroy', $provider).'"', false);

        $this->actingAs($viewer)->get(route('admin.deploy-tokens.index'))
            ->assertOk()
            ->assertDontSee('Create token')
            ->assertDontSee('action="'.route('admin.deploy-tokens.destroy', $token).'"', false);
    }

    public function test_read_only_detail_pages_disable_forms_and_keep_navigation_available(): void
    {
        $viewer = $this->viewer();
        $device = Device::create(['rustdesk_id' => 'readonly-device', 'uuid' => 'readonly-device']);
        $group = Group::create(['name' => 'Read-only users', 'type' => Group::TYPE_DEFAULT]);
        $accessibleGroup = Group::create(['name' => 'Mapped user group', 'type' => Group::TYPE_DEFAULT]);
        $deviceGroup = DeviceGroup::create(['name' => 'Read-only fleet']);
        UserGroupAccess::create([
            'group_id' => $group->id,
            'can_access_group_id' => $accessibleGroup->id,
        ]);
        DeviceGroupAccess::create([
            'group_id' => $accessibleGroup->id,
            'device_group_id' => $deviceGroup->id,
        ]);
        $strategy = Strategy::create(['name' => 'Read-only policy', 'enabled' => true, 'options' => ['enable-audio' => 'N']]);
        $provider = OauthProvider::create([
            'op' => 'readonly-oidc',
            'type' => 'oidc',
            'client_id' => 'readonly-client',
            'client_secret' => 'stored-secret',
            'issuer' => 'https://id.example.com',
            'enabled' => true,
        ]);

        foreach ([
            route('admin.devices.edit', $device),
            route('admin.groups.edit', $group),
            route('admin.device-groups.edit', $deviceGroup),
            route('admin.settings.index'),
            route('admin.oauth-providers.edit', $provider),
        ] as $url) {
            $this->actingAs($viewer)->get($url)
                ->assertOk()
                ->assertSee('view-only access')
                ->assertSee('disabled', false)
                ->assertDontSee('rd-btn--save', false);
        }

        $this->actingAs($viewer)->get(route('admin.strategies.edit', $strategy))
            ->assertOk()
            ->assertSee('view-only access')
            ->assertSee('role="tab"', false)
            ->assertSee('disabled', false)
            ->assertDontSee('rd-btn--save', false)
            ->assertDontSee('action="'.route('admin.strategies.assignments.store', $strategy).'"', false);

        $this->actingAs($viewer)->get(route('admin.groups.edit', $group))
            ->assertOk()
            ->assertSee('Mapped user group')
            ->assertDontSee('id="can_access_groups"', false);

        $this->actingAs($viewer)->get(route('admin.device-groups.edit', $deviceGroup))
            ->assertOk()
            ->assertSee('Mapped user group')
            ->assertDontSee('id="access_groups"', false);
    }
}
