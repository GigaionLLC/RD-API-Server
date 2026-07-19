<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Device;
use App\Models\User;
use App\Support\TrustedProxyConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_explicit_wildcard_trusts_the_immediate_callers_forwarded_ip(): void
    {
        config(['trustedproxy.proxies' => '*']);

        [$plain, $key] = $this->makeKey('203.0.113.7');
        Device::create(['rustdesk_id' => 'wildcard-proxy-device', 'uuid' => 'wildcard-proxy-uuid']);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
            ->withHeader('X-Forwarded-For', '203.0.113.7')
            ->withHeader('Authorization', 'Bearer '.$plain)
            ->getJson('/api/v1/devices')
            ->assertOk();

        $this->assertSame('203.0.113.7', $key->refresh()->last_used_ip);
    }

    public function test_trusted_https_proxy_generates_https_admin_assets(): void
    {
        config(['trustedproxy.proxies' => ['10.0.0.2']]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->get('http://admin.example.test/admin/login', [
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Port' => '443',
            ])
            ->assertOk()
            ->assertSee(
                'href="https://admin.example.test/assets/vendor/bootstrap/bootstrap.min.css"',
                false
            )
            ->assertSee(
                'src="https://admin.example.test/assets/vendor/jquery/jquery.min.js"',
                false
            )
            ->assertDontSee('http://admin.example.test/assets/', false);
    }

    public function test_trusted_https_proxy_generates_https_auth_redirect(): void
    {
        config(['trustedproxy.proxies' => ['10.0.0.2']]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->get('http://admin.example.test/admin', [
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Port' => '443',
            ])
            ->assertRedirect()
            ->assertHeader('Location', 'https://admin.example.test/admin/login');
    }

    public function test_trusted_https_proxy_marks_session_and_csrf_cookies_secure(): void
    {
        config([
            'trustedproxy.proxies' => ['10.0.0.2'],
            'session.secure' => null,
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->get('http://admin.example.test/admin/login', [
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Port' => '443',
            ])
            ->assertOk();

        $sessionCookie = $response->getCookie((string) config('session.cookie'), false);
        $csrfCookie = $response->getCookie('XSRF-TOKEN', false);

        $this->assertNotNull($sessionCookie);
        $this->assertNotNull($csrfCookie);
        $this->assertTrue($sessionCookie->isSecure());
        $this->assertTrue($csrfCookie->isSecure());
    }

    public function test_untrusted_forwarded_proto_cannot_spoof_generated_scheme(): void
    {
        config(['trustedproxy.proxies' => ['10.0.0.2']]);

        $headers = [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Port' => '443',
        ];

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
            ->get('http://admin.example.test/admin/login', $headers)
            ->assertOk()
            ->assertSee('http://admin.example.test/assets/', false)
            ->assertDontSee('https://admin.example.test/assets/', false);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
            ->get('http://admin.example.test/admin', $headers)
            ->assertRedirect()
            ->assertHeader('Location', 'http://admin.example.test/admin/login');
    }

    public function test_trusted_proxy_cannot_override_host_port_or_path_prefix(): void
    {
        config(['trustedproxy.proxies' => ['10.0.0.2']]);

        $headers = [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'attacker.example',
            'X-Forwarded-Port' => '8443',
            'X-Forwarded-Prefix' => '/spoofed',
        ];

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->get('http://admin.example.test/admin/login', $headers)
            ->assertOk()
            ->assertSee(
                'href="https://admin.example.test/assets/vendor/bootstrap/bootstrap.min.css"',
                false
            )
            ->assertDontSee('attacker.example', false)
            ->assertDontSee(':8443', false)
            ->assertDontSee('/spoofed', false);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->get('http://admin.example.test/admin', $headers)
            ->assertRedirect()
            ->assertHeader('Location', 'https://admin.example.test/admin/login');
    }

    public function test_trusted_https_proxy_uses_sanitized_host_for_nonstandard_port(): void
    {
        config(['trustedproxy.proxies' => ['10.0.0.2']]);

        $headers = [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'attacker.example',
            'X-Forwarded-Port' => '9443',
        ];

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->get('http://admin.example.test:8443/admin/login', $headers)
            ->assertOk()
            ->assertSee(
                'href="https://admin.example.test:8443/assets/vendor/bootstrap/bootstrap.min.css"',
                false
            )
            ->assertDontSee('attacker.example', false)
            ->assertDontSee(':9443', false);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->get('http://admin.example.test:8443/admin', $headers)
            ->assertRedirect()
            ->assertHeader('Location', 'https://admin.example.test:8443/admin/login');
    }

    public function test_proxy_configuration_accepts_an_explicit_wildcard(): void
    {
        $proxies = TrustedProxyConfiguration::parse('10.0.0.2,*');

        $this->assertSame('*', $proxies);
    }

    public function test_proxy_configuration_rejects_implicit_wildcards_and_invalid_networks(): void
    {
        $proxies = TrustedProxyConfiguration::parse(
            '**,REMOTE_ADDR,0.0.0.0/0,::/0,10.0.0.2,10.20.0.0/16,2001:db8::1'
        );

        $this->assertSame(
            ['10.0.0.2', '10.20.0.0/16', '2001:db8::1'],
            $proxies
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
