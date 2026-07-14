<?php

namespace Tests\Feature;

use App\Models\OauthProvider;
use App\Services\OauthService;
use App\Services\OidcDestinationGuard;
use App\Services\OidcDnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OidcDestinationSecurityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return iterable<string, array{string}>
     */
    public static function blockedUrlProvider(): iterable
    {
        yield 'plain HTTP' => ['http://8.8.8.8/oidc'];
        yield 'loopback IPv4' => ['https://127.0.0.1/oidc'];
        yield 'short IPv4 form' => ['https://127.1/oidc'];
        yield 'decimal IPv4 form' => ['https://2130706433/oidc'];
        yield 'hexadecimal IPv4 form' => ['https://0x7f000001/oidc'];
        yield 'octal IPv4 form' => ['https://0177.0.0.1/oidc'];
        yield 'cloud metadata' => ['https://169.254.169.254/latest/meta-data/'];
        yield 'private IPv4' => ['https://10.20.30.40/oidc'];
        yield 'carrier-grade NAT' => ['https://100.64.0.1/oidc'];
        yield 'loopback IPv6' => ['https://[::1]/oidc'];
        yield 'IPv4-mapped IPv6' => ['https://[::ffff:127.0.0.1]/oidc'];
        yield 'link-local IPv6' => ['https://[fe80::1]/oidc'];
        yield 'unique-local IPv6' => ['https://[fd00::1]/oidc'];
        yield 'reserved IPv6 space' => ['https://[4000::1]/oidc'];
        yield 'embedded credentials' => ['https://operator:secret@example.com/oidc'];
        yield 'unexpected port' => ['https://example.com:8443/oidc'];
        yield 'URL fragment' => ['https://example.com/oidc#internal'];
        yield 'ASCII whitespace' => ["https://example.com/oidc\r\nX-Test: yes"];
    }

    #[DataProvider('blockedUrlProvider')]
    public function test_guard_rejects_non_public_or_ambiguous_destinations(string $url): void
    {
        $resolver = $this->mock(OidcDnsResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->andReturn(['8.8.8.8']);
        });

        $this->expectException(InvalidArgumentException::class);

        (new OidcDestinationGuard($resolver))->resolve($url);
    }

    public function test_issuer_rejects_query_and_fragment_and_requires_matching_metadata(): void
    {
        $resolver = $this->mock(OidcDnsResolver::class);
        $guard = new OidcDestinationGuard($resolver);

        foreach (['https://idp.example.com?tenant=one', 'https://idp.example.com#tenant'] as $issuer) {
            try {
                $guard->normalizeIssuer($issuer);
                $this->fail('Expected unsafe issuer to be rejected: '.$issuer);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertTrue($guard->issuerMatches(
            'https://IDP.example.com/realms/team/',
            'https://idp.example.com/realms/team'
        ));
        $this->assertFalse($guard->issuerMatches(
            'https://idp.example.com/realms/team',
            'https://idp.example.com/realms/other'
        ));
    }

    public function test_guard_rejects_a_hostname_when_any_dns_answer_is_non_public(): void
    {
        $resolver = $this->mock(OidcDnsResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')
                ->once()
                ->with('idp.example.com')
                ->andReturn(['8.8.8.8', '127.0.0.1']);
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-public');

        (new OidcDestinationGuard($resolver))->resolve('https://idp.example.com/oidc');
    }

    public function test_guard_rejects_an_unresolved_hostname(): void
    {
        $resolver = $this->mock(OidcDnsResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->once()->andReturn([]);
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('could not be resolved');

        (new OidcDestinationGuard($resolver))->resolve('https://missing.example.com/oidc');
    }

    public function test_request_options_pin_dns_and_disable_redirects_and_proxy_inheritance(): void
    {
        $resolver = $this->mock(OidcDnsResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->once()->andReturn(['8.8.8.8']);
        });
        $guard = new OidcDestinationGuard($resolver);

        $options = $guard->requestOptions($guard->resolve('https://idp.example.com/oidc'));

        $this->assertFalse($options['allow_redirects']);
        $this->assertSame('', $options['proxy']);
        $this->assertSame('', $options['curl'][constant('CURLOPT_PROXY')]);
        $this->assertFalse($options['curl'][constant('CURLOPT_FOLLOWLOCATION')]);
        $this->assertTrue($options['curl'][constant('CURLOPT_FRESH_CONNECT')]);
        $this->assertTrue($options['curl'][constant('CURLOPT_FORBID_REUSE')]);
        $this->assertSame(
            ['idp.example.com:443:8.8.8.8'],
            $options['curl'][constant('CURLOPT_RESOLVE')]
        );

        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $this->assertSame('https', $options['curl'][constant('CURLOPT_PROTOCOLS_STR')]);
        } else {
            $this->assertSame(
                constant('CURLPROTO_HTTPS'),
                $options['curl'][constant('CURLOPT_PROTOCOLS')]
            );
        }
    }

    public function test_public_custom_port_must_be_explicitly_allowed(): void
    {
        config()->set('rustdesk.oidc.allowed_ports', [443, 8443]);
        $resolver = $this->mock(OidcDnsResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->once()->andReturn(['8.8.8.8']);
        });
        $guard = new OidcDestinationGuard($resolver);

        $destination = $guard->resolve('https://idp.example.com:8443/oidc');
        $options = $guard->requestOptions($destination);

        $this->assertSame(8443, $destination['port']);
        $this->assertSame(
            ['idp.example.com:8443:8.8.8.8'],
            $options['curl'][constant('CURLOPT_RESOLVE')]
        );
    }

    public function test_public_ipv6_literal_is_allowed_without_a_dns_pin(): void
    {
        $resolver = $this->mock(OidcDnsResolver::class);
        $guard = new OidcDestinationGuard($resolver);

        $destination = $guard->resolve('https://[2606:4700:4700::1111]/oidc');
        $options = $guard->requestOptions($destination);

        $this->assertTrue($destination['is_ip_literal']);
        $this->assertArrayNotHasKey(constant('CURLOPT_RESOLVE'), $options['curl']);
    }

    public function test_private_issuer_is_blocked_before_http_transport(): void
    {
        Http::fake();
        $this->provider('https://169.254.169.254/tenant');

        $this->assertSame(
            ['', ''],
            app(OauthService::class)->beginAuth('security-oidc', 'device', 'uuid', [])
        );
        Http::assertNothingSent();
    }

    public function test_discovery_with_a_mismatched_issuer_is_rejected(): void
    {
        $this->fakePublicDns();
        $this->provider();
        Http::fake([
            $this->discoveryUrl() => Http::response($this->metadata([
                'issuer' => 'https://attacker.example.com/tenant',
            ])),
        ]);

        $this->assertSame(
            ['', ''],
            app(OauthService::class)->beginAuth('security-oidc', 'device', 'uuid', [])
        );
        Http::assertSentCount(1);
    }

    public function test_discovery_redirect_is_not_followed(): void
    {
        $this->fakePublicDns();
        $this->provider();
        Http::fake([
            $this->discoveryUrl() => Http::response('', 302, [
                'Location' => 'https://redirect.example.net/openid-configuration',
            ]),
            '*' => Http::response($this->metadata()),
        ]);

        $this->assertSame(
            ['', ''],
            app(OauthService::class)->beginAuth('security-oidc', 'device', 'uuid', [])
        );
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'redirect.example.net'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unsafeDiscoveredEndpointProvider(): iterable
    {
        yield 'authorization endpoint' => ['authorization_endpoint', 'https://127.0.0.1/authorize'];
        yield 'token endpoint' => ['token_endpoint', 'https://169.254.169.254/token'];
        yield 'userinfo endpoint' => ['userinfo_endpoint', 'https://10.0.0.10/userinfo'];
    }

    #[DataProvider('unsafeDiscoveredEndpointProvider')]
    public function test_private_discovered_endpoint_aborts_before_login_starts(string $field, string $url): void
    {
        $this->fakePublicDns();
        $this->provider();
        Http::fake([
            $this->discoveryUrl() => Http::response($this->metadata([$field => $url])),
        ]);

        $this->assertSame(
            ['', ''],
            app(OauthService::class)->beginAuth('security-oidc', 'device', 'uuid', [])
        );
        Http::assertSentCount(1);
    }

    public function test_token_endpoint_is_resolved_again_and_dns_rebinding_is_blocked(): void
    {
        $calls = [];
        $this->mock(OidcDnsResolver::class, function (MockInterface $mock) use (&$calls): void {
            $mock->shouldReceive('resolve')->andReturnUsing(function (string $host) use (&$calls): array {
                $calls[$host] = ($calls[$host] ?? 0) + 1;

                return $host === 'token.example.net' && $calls[$host] >= 3
                    ? ['127.0.0.1']
                    : ['8.8.8.8'];
            });
        });
        $this->provider();
        $this->fakeSuccessfulOidc();

        [$code] = app(OauthService::class)->beginAuth('security-oidc', 'device', 'uuid', []);
        $this->assertNotSame('', $code);
        $this->assertFalse(app(OauthService::class)->handleCallback($code, 'provider-code')['ok']);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'token.example.net'));
        $this->assertSame(3, $calls['token.example.net']);
    }

    public function test_userinfo_endpoint_is_resolved_again_before_bearer_token_is_sent(): void
    {
        $calls = [];
        $this->mock(OidcDnsResolver::class, function (MockInterface $mock) use (&$calls): void {
            $mock->shouldReceive('resolve')->andReturnUsing(function (string $host) use (&$calls): array {
                $calls[$host] = ($calls[$host] ?? 0) + 1;

                return $host === 'profile.example.net' && $calls[$host] >= 3
                    ? ['10.0.0.20']
                    : ['8.8.8.8'];
            });
        });
        $this->provider();
        $this->fakeSuccessfulOidc();

        [$code] = app(OauthService::class)->beginAuth('security-oidc', 'device', 'uuid', []);
        $this->assertFalse(app(OauthService::class)->handleCallback($code, 'provider-code')['ok']);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'token.example.net'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'profile.example.net'));
        $this->assertSame(3, $calls['profile.example.net']);
    }

    public function test_redirecting_token_endpoint_is_not_followed(): void
    {
        $this->fakePublicDns();
        $this->provider();
        Http::fake([
            $this->discoveryUrl() => Http::response($this->metadata()),
            'https://token.example.net/oauth/token' => Http::response('', 302, [
                'Location' => 'https://169.254.169.254/latest/meta-data/',
            ]),
            '*' => Http::response(['sub' => 'unexpected']),
        ]);

        [$code] = app(OauthService::class)->beginAuth('security-oidc', 'device', 'uuid', []);
        $this->assertFalse(app(OauthService::class)->handleCallback($code, 'provider-code')['ok']);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '169.254.169.254'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'profile.example.net'));
    }

    public function test_public_cross_host_oidc_flow_still_completes(): void
    {
        $this->fakePublicDns();
        $this->provider();
        $this->fakeSuccessfulOidc();

        [$code, $url] = app(OauthService::class)->beginAuth('security-oidc', 'device', 'uuid', []);

        $this->assertNotSame('', $code);
        $this->assertStringStartsWith('https://login.example.net/oauth/authorize?audience=desktop&', $url);
        $this->assertTrue(app(OauthService::class)->handleCallback($code, 'provider-code')['ok']);
        $this->assertStringContainsString('access_token', app(OauthService::class)->pollResult($code));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'token.example.net'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'profile.example.net'));
    }

    private function provider(string $issuer = 'https://issuer.example.com/tenant'): OauthProvider
    {
        return OauthProvider::create([
            'op' => 'security-oidc',
            'type' => OauthService::TYPE_OIDC,
            'client_id' => 'rustdesk',
            'client_secret' => 'secret',
            'scopes' => OauthService::DEFAULT_SCOPES,
            'issuer' => $issuer,
            'auto_register' => true,
            'pkce_enable' => true,
            'pkce_method' => 'S256',
            'enabled' => true,
        ]);
    }

    private function fakePublicDns(): void
    {
        $this->mock(OidcDnsResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->andReturn(['8.8.8.8']);
        });
    }

    private function discoveryUrl(): string
    {
        return 'https://issuer.example.com/tenant/.well-known/openid-configuration';
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function metadata(array $overrides = []): array
    {
        return array_merge([
            'issuer' => 'https://issuer.example.com/tenant',
            'authorization_endpoint' => 'https://login.example.net/oauth/authorize?audience=desktop',
            'token_endpoint' => 'https://token.example.net/oauth/token',
            'userinfo_endpoint' => 'https://profile.example.net/oidc/userinfo',
        ], $overrides);
    }

    private function fakeSuccessfulOidc(): void
    {
        Http::fake([
            $this->discoveryUrl() => Http::response($this->metadata()),
            'https://token.example.net/oauth/token' => Http::response(['access_token' => 'public-token']),
            'https://profile.example.net/oidc/userinfo' => Http::response([
                'sub' => 'public-subject',
                'email' => 'public@example.com',
                'preferred_username' => 'public-user',
                'email_verified' => true,
                'name' => 'Public User',
            ]),
        ]);
    }
}
