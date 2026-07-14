<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Strategy;
use App\Services\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Mockery;
use Tests\TestCase;

class DeviceAutoRegistrationSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE_ONE = '203.0.113.10';

    private const SOURCE_TWO = '203.0.113.11';

    private const SOURCE_THREE = '203.0.113.12';

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('rd:device-auto-register:global');
        foreach ([self::SOURCE_ONE, self::SOURCE_TWO, self::SOURCE_THREE] as $sourceIp) {
            RateLimiter::clear($this->ipRateLimitKey($sourceIp));
        }
    }

    public function test_unknown_devices_fail_closed_without_rows_webhooks_or_strategy_secrets(): void
    {
        $this->assertTrue((bool) config('rustdesk.devices.require_deployment'));
        $this->assertFalse((bool) config('rustdesk.devices.auto_register'));

        Strategy::create([
            'name' => 'Default secret policy',
            'enabled' => true,
            'is_default' => true,
            'options' => [
                'default-connect-password' => 'do-not-disclose',
                'proxy-password' => 'proxy-secret',
            ],
            'modified_at' => 77,
        ]);
        Cache::put('rd:disconnect:unknown-device', [13], now()->addMinutes(5));

        $this->mock(WebhookService::class)
            ->shouldReceive('dispatch')
            ->never();

        $heartbeat = $this->withServerVariables(['REMOTE_ADDR' => self::SOURCE_ONE])
            ->postJson('/api/heartbeat', [
                'id' => 'unknown-device',
                'uuid' => 'attacker-uuid',
                'modified_at' => 0,
            ])->assertOk();

        $this->assertSame('{}', $heartbeat->getContent());
        $this->assertStringNotContainsString('do-not-disclose', $heartbeat->getContent());

        $this->withServerVariables(['REMOTE_ADDR' => self::SOURCE_ONE])
            ->postJson('/api/sysinfo', [
                'id' => 'unknown-sysinfo-device',
                'uuid' => 'attacker-uuid',
                'hostname' => 'Attacker controlled',
                'device_group_name' => 'Attacker group',
                'address_book_name' => 'Attacker book',
            ])->assertOk()->assertSeeText('ID_NOT_FOUND');

        $this->assertDatabaseCount('devices', 0);
        $this->assertDatabaseMissing('device_groups', ['name' => 'Attacker group']);
        $this->assertDatabaseMissing('address_books', ['name' => 'Attacker book']);
        $this->assertSame([13], Cache::get('rd:disconnect:unknown-device'));
    }

    public function test_denied_first_claim_does_not_reserve_an_identifier(): void
    {
        $strategy = Strategy::create([
            'name' => 'Trusted policy',
            'enabled' => true,
            'options' => ['default-connect-password' => 'trusted-secret'],
            'modified_at' => 91,
        ]);
        $this->mock(WebhookService::class)
            ->shouldReceive('dispatch')
            ->never();

        $claim = $this->withServerVariables(['REMOTE_ADDR' => self::SOURCE_ONE])
            ->postJson('/api/heartbeat', [
                'id' => 'shared-id',
                'uuid' => 'attacker-uuid',
                'modified_at' => 0,
            ])->assertOk();

        $this->assertSame('{}', $claim->getContent());
        $this->assertDatabaseMissing('devices', ['rustdesk_id' => 'shared-id']);

        $device = Device::create([
            'rustdesk_id' => 'shared-id',
            'uuid' => 'trusted-uuid',
            'strategy_id' => $strategy->id,
            'approved' => true,
        ]);
        Cache::put('rd:disconnect:shared-id', [21], now()->addMinutes(5));

        $wrongIdentity = $this->withServerVariables(['REMOTE_ADDR' => self::SOURCE_ONE])
            ->postJson('/api/heartbeat', [
                'id' => 'shared-id',
                'uuid' => 'attacker-uuid',
                'modified_at' => 0,
            ])->assertOk();

        $this->assertSame('{}', $wrongIdentity->getContent());
        $this->assertFalse($device->refresh()->is_online);
        $this->assertSame([21], Cache::get('rd:disconnect:shared-id'));

        $this->withServerVariables(['REMOTE_ADDR' => self::SOURCE_TWO])
            ->postJson('/api/heartbeat', [
                'id' => 'shared-id',
                'uuid' => 'trusted-uuid',
                'modified_at' => 0,
            ])->assertOk()
            ->assertJsonPath('strategy.config_options.default-connect-password', 'trusted-secret')
            ->assertJsonPath('disconnect.0', 21);
    }

    public function test_explicit_legacy_opt_in_preserves_stock_success_shapes(): void
    {
        $this->enableOpenRegistration();

        $this->mock(WebhookService::class)
            ->shouldReceive('dispatch')
            ->once()
            ->with('device.new', Mockery::on(
                fn (array $payload): bool => $payload['peer_id'] === 'heartbeat-device'
                    && $payload['uuid'] === 'heartbeat-uuid'
            ));

        $heartbeat = $this->withServerVariables(['REMOTE_ADDR' => self::SOURCE_ONE])
            ->postJson('/api/heartbeat', [
                'id' => 'heartbeat-device',
                'uuid' => 'heartbeat-uuid',
                'modified_at' => 0,
            ])->assertOk();

        $this->assertSame('{}', $heartbeat->getContent());
        $this->assertDatabaseHas('devices', [
            'rustdesk_id' => 'heartbeat-device',
            'uuid' => 'heartbeat-uuid',
            'approved' => true,
        ]);

        $secondHeartbeat = $this->withServerVariables(['REMOTE_ADDR' => self::SOURCE_ONE])
            ->postJson('/api/heartbeat', [
                'id' => 'heartbeat-device',
                'uuid' => 'heartbeat-uuid',
                'modified_at' => 0,
            ])->assertOk();
        $this->assertSame('{}', $secondHeartbeat->getContent());

        $this->withServerVariables(['REMOTE_ADDR' => self::SOURCE_ONE])
            ->postJson('/api/sysinfo', [
                'id' => 'sysinfo-device',
                'uuid' => 'sysinfo-uuid',
                'hostname' => 'Open enrollment host',
            ])->assertOk()->assertSeeText('SYSINFO_UPDATED');

        $this->assertDatabaseHas('devices', [
            'rustdesk_id' => 'sysinfo-device',
            'uuid' => 'sysinfo-uuid',
            'hostname' => 'Open enrollment host',
            'approved' => true,
        ]);
    }

    public function test_require_deployment_overrides_legacy_auto_registration(): void
    {
        config()->set('rustdesk.devices.require_deployment', true);
        config()->set('rustdesk.devices.auto_register', true);

        $this->mock(WebhookService::class)
            ->shouldReceive('dispatch')
            ->never();

        $response = $this->withServerVariables(['REMOTE_ADDR' => self::SOURCE_ONE])
            ->postJson('/api/heartbeat', [
                'id' => 'still-unknown',
                'uuid' => 'still-unknown-uuid',
            ])->assertOk();

        $this->assertSame('{}', $response->getContent());
        $this->assertDatabaseCount('devices', 0);
    }

    public function test_open_registration_shares_the_per_ip_limit_across_endpoints(): void
    {
        $this->enableOpenRegistration(perIp: 2);

        $this->mock(WebhookService::class)
            ->shouldReceive('dispatch')
            ->once();

        $this->withServerVariables(['REMOTE_ADDR' => self::SOURCE_ONE])
            ->postJson('/api/heartbeat', ['id' => 'allowed-heartbeat', 'uuid' => 'uuid-1'])
            ->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => self::SOURCE_ONE])
            ->postJson('/api/sysinfo', ['id' => 'allowed-sysinfo', 'uuid' => 'uuid-2'])
            ->assertOk()->assertSeeText('SYSINFO_UPDATED');

        $blocked = $this->withServerVariables(['REMOTE_ADDR' => self::SOURCE_ONE])
            ->postJson('/api/heartbeat', ['id' => 'blocked-heartbeat', 'uuid' => 'uuid-3'])
            ->assertOk();

        $this->assertSame('{}', $blocked->getContent());
        $this->assertDatabaseCount('devices', 2);
        $this->assertDatabaseMissing('devices', ['rustdesk_id' => 'blocked-heartbeat']);
    }

    public function test_open_registration_global_limit_bounds_ip_rotation(): void
    {
        $this->enableOpenRegistration(perIp: 100, global: 2);

        $this->mock(WebhookService::class)
            ->shouldReceive('dispatch')
            ->once();

        $this->withServerVariables(['REMOTE_ADDR' => self::SOURCE_ONE])
            ->postJson('/api/heartbeat', ['id' => 'global-one', 'uuid' => 'uuid-1'])
            ->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => self::SOURCE_TWO])
            ->postJson('/api/sysinfo', ['id' => 'global-two', 'uuid' => 'uuid-2'])
            ->assertOk()->assertSeeText('SYSINFO_UPDATED');
        $this->withServerVariables(['REMOTE_ADDR' => self::SOURCE_THREE])
            ->postJson('/api/heartbeat', ['id' => 'global-blocked', 'uuid' => 'uuid-3'])
            ->assertOk();

        $this->assertDatabaseCount('devices', 2);
        $this->assertDatabaseMissing('devices', ['rustdesk_id' => 'global-blocked']);
    }

    public function test_open_registration_total_quota_bounds_database_and_webhook_growth(): void
    {
        $this->enableOpenRegistration(maxDevices: 2);
        Device::create(['rustdesk_id' => 'deployed-device', 'uuid' => 'trusted-uuid']);

        $this->mock(WebhookService::class)
            ->shouldReceive('dispatch')
            ->once();

        $this->withServerVariables(['REMOTE_ADDR' => self::SOURCE_ONE])
            ->postJson('/api/heartbeat', ['id' => 'quota-allowed', 'uuid' => 'uuid-1'])
            ->assertOk();
        $blockedHeartbeat = $this->withServerVariables(['REMOTE_ADDR' => self::SOURCE_TWO])
            ->postJson('/api/heartbeat', ['id' => 'quota-blocked', 'uuid' => 'uuid-2'])
            ->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => self::SOURCE_THREE])
            ->postJson('/api/sysinfo', ['id' => 'quota-also-blocked', 'uuid' => 'uuid-3'])
            ->assertOk()->assertSeeText('ID_NOT_FOUND');

        $this->assertSame('{}', $blockedHeartbeat->getContent());
        $this->assertDatabaseCount('devices', 2);
        $this->assertDatabaseMissing('devices', ['rustdesk_id' => 'quota-blocked']);
        $this->assertDatabaseMissing('devices', ['rustdesk_id' => 'quota-also-blocked']);
    }

    private function enableOpenRegistration(
        int $perIp = 30,
        int $global = 100,
        int $maxDevices = 5000,
    ): void {
        config()->set('rustdesk.devices.require_deployment', false);
        config()->set('rustdesk.devices.auto_register', true);
        config()->set('rustdesk.devices.auto_registration.per_ip_per_minute', $perIp);
        config()->set('rustdesk.devices.auto_registration.global_per_minute', $global);
        config()->set('rustdesk.devices.auto_registration.max_devices', $maxDevices);
    }

    private function ipRateLimitKey(string $sourceIp): string
    {
        return 'rd:device-auto-register:ip:'.hash('sha256', $sourceIp);
    }
}
