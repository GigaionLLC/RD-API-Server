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
        $this->assertStringContainsString(
            'access_token',
            app(OauthService::class)->pollResult($code, 'device', 'uuid')
        );
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'token.example.net'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'profile.example.net'));
    }

    public function test_private_networks_are_denied_until_an_operator_opts_in(): void
    {
        $this->assertSame([], config('rustdesk.oidc.allowed_networks'));
        $this->assertFalse((bool) config('rustdesk.oidc.allow_private_networks'));
    }

    public function test_an_allowlisted_private_issuer_host_resolves_and_pins(): void
    {
        config()->set('rustdesk.oidc.allowed_networks', ['10.169.169.0/24']);
        $resolver = $this->mock(OidcDnsResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->once()->with('authentik.lan')->andReturn(['10.169.169.253']);
        });
        $guard = new OidcDestinationGuard($resolver);

        $destination = $guard->resolve('https://authentik.lan/application/o/rustdesk', 'authentik.lan');

        $this->assertSame([
            'host' => 'authentik.lan',
            'port' => 443,
            'ip' => '10.169.169.253',
            'is_ip_literal' => false,
        ], $destination);
        $this->assertSame(
            ['authentik.lan:443:10.169.169.253'],
            $guard->requestOptions($destination)['curl'][constant('CURLOPT_RESOLVE')]
        );
    }

    public function test_an_allowlisted_bare_address_entry_covers_a_single_host(): void
    {
        config()->set('rustdesk.oidc.allowed_networks', ['10.169.169.253']);
        $resolver = $this->mock(OidcDnsResolver::class);
        $guard = new OidcDestinationGuard($resolver);

        $destination = $guard->resolve('https://10.169.169.253/oidc', '10.169.169.253');

        $this->assertSame('10.169.169.253', $destination['ip']);
        $this->assertTrue($destination['is_ip_literal']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-public');
        $guard->resolve('https://10.169.169.254/oidc', '10.169.169.254');
    }

    public function test_the_blanket_private_switch_admits_rfc1918_and_unique_local_space(): void
    {
        config()->set('rustdesk.oidc.allow_private_networks', true);
        $resolver = $this->mock(OidcDnsResolver::class);
        $guard = new OidcDestinationGuard($resolver);

        foreach (['10.169.169.253', '172.16.5.4', '192.168.1.10'] as $address) {
            $this->assertSame($address, $guard->resolve('https://'.$address.'/oidc', $address)['ip']);
        }

        $this->assertSame('fd00::1', $guard->resolve('https://[fd00::1]/oidc', 'fd00::1')['ip']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function neverTrustableAddressProvider(): iterable
    {
        yield 'loopback IPv4' => ['127.0.0.1'];
        yield 'loopback IPv6' => ['::1'];
        yield 'link-local IPv4' => ['169.254.1.1'];
        yield 'link-local IPv6' => ['fe80::1'];
        yield 'AWS instance metadata' => ['169.254.169.254'];
        yield 'AWS IPv6 instance metadata' => ['fd00:ec2::254'];
        yield 'Alibaba instance metadata' => ['100.100.100.200'];
        yield 'IPv4-mapped IPv6' => ['::ffff:10.169.169.253'];
        yield 'NAT64 translation' => ['64:ff9b::a9fe:a9fe'];
        yield '6to4 translation' => ['2002:a9fe:a9fe::1'];
        yield 'multicast' => ['224.0.0.1'];
    }

    #[DataProvider('neverTrustableAddressProvider')]
    public function test_hard_blocked_addresses_survive_every_opt_in(string $address): void
    {
        // Every entry here is deliberately one bit wider than a hard-blocked range, so it parses
        // and covers the address. Only the match-time refusal can stop these.
        config()->set('rustdesk.oidc.allow_private_networks', true);
        config()->set('rustdesk.oidc.allowed_networks', [
            '10.0.0.0/8', 'fd00::/8', '100.64.0.0/10', '169.254.0.0/15', '2002::/15', '64:ff9b::/95',
        ]);
        $resolver = $this->mock(OidcDnsResolver::class, function (MockInterface $mock) use ($address): void {
            $mock->shouldReceive('resolve')->andReturn([$address]);
        });
        $guard = new OidcDestinationGuard($resolver);

        $this->expectException(InvalidArgumentException::class);

        $guard->resolve('https://idp.example.com/oidc', 'idp.example.com');
    }

    public function test_a_trusted_private_address_is_refused_for_a_host_other_than_the_issuer(): void
    {
        config()->set('rustdesk.oidc.allowed_networks', ['10.169.169.0/24']);
        $resolver = $this->mock(OidcDnsResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->with('vault.internal.lan')->andReturn(['10.169.169.9']);
        });
        $guard = new OidcDestinationGuard($resolver);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be served by the issuer host');

        $guard->resolve('https://vault.internal.lan/v1/token', 'authentik.lan');
    }

    public function test_an_address_outside_the_allowlist_is_still_rejected(): void
    {
        config()->set('rustdesk.oidc.allowed_networks', ['10.169.169.0/24']);
        $resolver = $this->mock(OidcDnsResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->once()->andReturn(['10.20.30.40']);
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-public');

        (new OidcDestinationGuard($resolver))->resolve('https://authentik.lan/oidc', 'authentik.lan');
    }

    public function test_the_allowlist_does_not_rescue_a_split_dns_answer(): void
    {
        config()->set('rustdesk.oidc.allowed_networks', ['10.169.169.0/24']);
        $resolver = $this->mock(OidcDnsResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->once()->andReturn(['10.169.169.253', '127.0.0.1']);
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-public');

        (new OidcDestinationGuard($resolver))->resolve('https://authentik.lan/oidc', 'authentik.lan');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableAllowlistEntryProvider(): iterable
    {
        yield 'all IPv4 addresses' => ['0.0.0.0/0'];
        yield 'all IPv6 addresses' => ['::/0'];
        yield 'prefix above the family maximum' => ['10.169.169.0/33'];
        yield 'empty prefix' => ['10.169.169.0/'];
        yield 'non-numeric prefix' => ['10.169.169.0/eight'];
        yield 'host bits set' => ['10.169.169.5/24'];
        yield 'not an address' => ['authentik.lan/24'];
        yield 'prefix below the IPv4 floor' => ['10.0.0.0/4'];
        yield 'loopback' => ['127.0.0.0/8'];
        yield 'link-local' => ['169.254.0.0/16'];
        yield 'NAT64 translation' => ['64:ff9b::/96'];
        yield 'IPv4-mapped space' => ['::ffff:0:0/96'];
    }

    #[DataProvider('unusableAllowlistEntryProvider')]
    public function test_an_unusable_allowlist_entry_is_discarded_rather_than_honoured(string $entry): void
    {
        config()->set('rustdesk.oidc.allowed_networks', [$entry]);
        $resolver = $this->mock(OidcDnsResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->andReturn(['10.169.169.253']);
        });
        $guard = new OidcDestinationGuard($resolver);

        $this->assertSame([$entry], $guard->trustedNetworkDiagnostics()['rejected']);
        $this->assertSame(0, $guard->trustedNetworkDiagnostics()['trusted']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-public');
        $guard->resolve('https://authentik.lan/oidc', 'authentik.lan');
    }

    public function test_one_unusable_entry_does_not_disable_the_usable_ones(): void
    {
        config()->set('rustdesk.oidc.allowed_networks', ['10.169.169.0/24', 'not-a-network']);
        $resolver = $this->mock(OidcDnsResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->andReturn(['10.169.169.253']);
        });
        $guard = new OidcDestinationGuard($resolver);

        $this->assertSame('10.169.169.253', $guard->resolve('https://authentik.lan/oidc', 'authentik.lan')['ip']);
        $this->assertSame(['not-a-network'], $guard->trustedNetworkDiagnostics()['rejected']);
    }

    public function test_the_allowlist_does_not_loosen_the_allowed_port_boundary(): void
    {
        config()->set('rustdesk.oidc.allowed_networks', ['10.169.169.0/24']);
        $resolver = $this->mock(OidcDnsResolver::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('port that is not allowed');

        (new OidcDestinationGuard($resolver))->resolve('https://10.169.169.253:9443/oidc', '10.169.169.253');
    }

    public function test_an_allowlisted_private_issuer_completes_discovery(): void
    {
        config()->set('rustdesk.oidc.allowed_networks', ['10.169.169.0/24']);
        $this->mock(OidcDnsResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->with('authentik.lan')->andReturn(['10.169.169.253']);
        });
        $this->provider('https://authentik.lan/application/o/rustdesk');
        Http::fake([
            'https://authentik.lan/application/o/rustdesk/.well-known/openid-configuration' => Http::response([
                'issuer' => 'https://authentik.lan/application/o/rustdesk',
                'authorization_endpoint' => 'https://authentik.lan/application/o/authorize/',
                'token_endpoint' => 'https://authentik.lan/application/o/token/',
                'userinfo_endpoint' => 'https://authentik.lan/application/o/userinfo/',
            ]),
        ]);

        [$code, $url] = app(OauthService::class)->beginAuth('security-oidc', 'device', 'uuid', []);

        $this->assertNotSame('', $code);
        $this->assertStringStartsWith('https://authentik.lan/application/o/authorize/?', $url);
        Http::assertSentCount(1);
    }

    public function test_a_private_discovered_endpoint_on_another_host_still_aborts_the_login(): void
    {
        config()->set('rustdesk.oidc.allowed_networks', ['10.169.169.0/24']);
        $this->mock(OidcDnsResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->andReturn(['10.169.169.253']);
        });
        $this->provider('https://authentik.lan/application/o/rustdesk');
        Http::fake([
            'https://authentik.lan/application/o/rustdesk/.well-known/openid-configuration' => Http::response([
                'issuer' => 'https://authentik.lan/application/o/rustdesk',
                'authorization_endpoint' => 'https://authentik.lan/application/o/authorize/',
                'token_endpoint' => 'https://vault.internal.lan/v1/token',
                'userinfo_endpoint' => 'https://authentik.lan/application/o/userinfo/',
            ]),
        ]);

        $this->assertSame(
            ['', ''],
            app(OauthService::class)->beginAuth('security-oidc', 'device', 'uuid', [])
        );
        Http::assertSentCount(1);
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
