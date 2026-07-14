<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\DeployToken;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeploymentSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $username, bool $admin = false): User
    {
        return User::create([
            'username' => $username,
            'password' => 'secret12345',
            'status' => User::STATUS_NORMAL,
            'is_admin' => $admin,
        ]);
    }

    private function tokenFor(User $owner, string $token): DeployToken
    {
        return DeployToken::create([
            'user_id' => $owner->id,
            'token' => $token,
            'name' => 'Security test',
        ]);
    }

    public function test_cli_requires_the_exact_uuid_before_mutating_an_existing_device(): void
    {
        $owner = $this->user('admin', true);
        $token = $this->tokenFor($owner, 'deploy-owner');
        $victimOwner = $this->user('victim');
        $device = Device::create([
            'rustdesk_id' => 'existing-device',
            'uuid' => 'trusted-uuid',
            'user_id' => $victimOwner->id,
            'approved' => true,
        ]);

        foreach ([[], ['uuid' => 'wrong-uuid']] as $identity) {
            $response = $this->withHeader('Authorization', 'Bearer '.$token->token)
                ->postJson('/api/devices/cli', [
                    'id' => $device->rustdesk_id,
                    'device_name' => 'Claimed',
                    ...$identity,
                ])
                ->assertOk();

            $this->assertNotSame('', $response->getContent());
            $this->assertSame($victimOwner->id, $device->refresh()->user_id);
            $this->assertNotSame('Claimed', $device->device_name);
        }
    }

    public function test_deploy_tokens_stop_working_when_the_owner_is_disabled_or_loses_permission(): void
    {
        $delegate = $this->user('delegate');
        $role = AdminRole::create([
            'name' => 'Deployment operator',
            'type' => AdminRole::TYPE_INDIVIDUAL,
            'scope' => [],
            'perms' => ['deploy.edit'],
        ]);
        $delegate->adminRoles()->attach($role);
        $token = $this->tokenFor($delegate, 'deploy-delegate');

        $this->withHeader('Authorization', 'Bearer '.$token->token)
            ->postJson('/api/devices/cli', ['id' => 'first', 'uuid' => 'uuid-first'])
            ->assertOk()
            ->assertContent('');

        $delegate->adminRoles()->detach($role);
        $this->withHeader('Authorization', 'Bearer '.$token->token)
            ->postJson('/api/devices/cli', ['id' => 'second', 'uuid' => 'uuid-second'])
            ->assertOk()
            ->assertSeeText('token');

        $delegate->adminRoles()->attach($role);
        $delegate->forceFill(['status' => User::STATUS_DISABLED])->save();
        $this->withHeader('Authorization', 'Bearer '.$token->token)
            ->postJson('/api/devices/cli', ['id' => 'third', 'uuid' => 'uuid-third'])
            ->assertOk()
            ->assertSeeText('token');

        $this->assertDatabaseMissing('devices', ['rustdesk_id' => 'second']);
        $this->assertDatabaseMissing('devices', ['rustdesk_id' => 'third']);
    }

    public function test_only_full_admin_token_owner_can_assign_a_device_to_another_user(): void
    {
        $target = $this->user('target');
        $delegate = $this->user('delegate');
        $role = AdminRole::create([
            'name' => 'Deployment operator',
            'type' => AdminRole::TYPE_INDIVIDUAL,
            'scope' => [],
            'perms' => ['deploy.edit'],
        ]);
        $delegate->adminRoles()->attach($role);
        $delegateToken = $this->tokenFor($delegate, 'deploy-delegate');

        $this->withHeader('Authorization', 'Bearer '.$delegateToken->token)
            ->postJson('/api/devices/cli', [
                'id' => 'delegate-device',
                'uuid' => 'delegate-uuid',
                'user_name' => $target->username,
            ])
            ->assertOk()
            ->assertSeeText('full administrator');
        $this->assertDatabaseMissing('devices', ['rustdesk_id' => 'delegate-device']);

        $admin = $this->user('admin', true);
        $adminToken = $this->tokenFor($admin, 'deploy-admin');
        $this->withHeader('Authorization', 'Bearer '.$adminToken->token)
            ->postJson('/api/devices/cli', [
                'id' => 'admin-device',
                'uuid' => 'admin-uuid',
                'user_name' => $target->username,
            ])
            ->assertOk()
            ->assertContent('');
        $this->assertDatabaseHas('devices', [
            'rustdesk_id' => 'admin-device',
            'user_id' => $target->id,
        ]);
    }
}
