<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use Tests\TestCase;

class TrustedProxySecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_untrusted_forwarded_ip_cannot_bypass_an_api_key_allowlist(): void
    {
        [$plain] = $this->makeKey('203.0.113.7');

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
            ->withHeader('X-Forwarded-For', '203.0.113.7')
            ->withHeader('Authorization', 'Bearer '.$plain)
            ->getJson('/api/v1/devices')
            ->assertForbidden();
    }

    public function test_untrusted_forwarded_ip_cannot_rotate_the_login_throttle_key(): void
    {
        User::create([
            'username' => 'proxy-victim',
            'password' => 'secret12345',
            'status' => User::STATUS_NORMAL,
        ]);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
                ->withHeader('X-Forwarded-For', '203.0.113.'.($attempt + 1))
                ->postJson('/api/login', [
                    'username' => 'proxy-victim',
                    'password' => 'wrong',
                ])
                ->assertOk();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
            ->withHeader('X-Forwarded-For', '203.0.113.200')
            ->postJson('/api/login', [
                'username' => 'proxy-victim',
                'password' => 'wrong',
            ])
            ->assertTooManyRequests();
    }

    public function test_an_explicitly_trusted_proxy_supplies_the_client_ip(): void
    {
        config(['trustedproxy.proxies' => ['10.0.0.2']]);

        [$plain, $key] = $this->makeKey('203.0.113.7');
        Device::create(['rustdesk_id' => 'trusted-proxy-device', 'uuid' => 'trusted-proxy-uuid']);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->withHeader('X-Forwarded-For', '203.0.113.7')
            ->withHeader('Authorization', 'Bearer '.$plain)
            ->getJson('/api/v1/devices')
            ->assertOk();

        $this->assertSame('203.0.113.7', $key->refresh()->last_used_ip);
    }

    public function test_proxy_configuration_rejects_wildcards_and_invalid_networks(): void
    {
        $repository = Env::getRepository();
        $repository->set('TRUSTED_PROXIES', '*,**,REMOTE_ADDR,0.0.0.0/0,::/0,10.0.0.2,10.20.0.0/16,2001:db8::1');

        try {
            $configuration = require config_path('trustedproxy.php');
        } finally {
            $repository->clear('TRUSTED_PROXIES');
        }

        $this->assertSame(
            ['10.0.0.2', '10.20.0.0/16', '2001:db8::1'],
            $configuration['proxies']
        );
    }

    /**
     * @return array{0: string, 1: ApiKey}
     */
    private function makeKey(string $allowedIps): array
    {
        $user = User::create([
            'username' => 'proxy-operator-'.uniqid(),
            'password' => 'secret12345',
            'is_admin' => true,
            'status' => User::STATUS_NORMAL,
        ]);
        [$plain, $prefix, $hash] = ApiKey::generateSecret();
        $key = ApiKey::create([
            'user_id' => $user->id,
            'name' => 'proxy-security-key',
            'token_hash' => $hash,
            'prefix' => $prefix,
            'scopes' => ['devices.read'],
            'allowed_ips' => $allowedIps,
        ]);

        return [$plain, $key];
    }
}
