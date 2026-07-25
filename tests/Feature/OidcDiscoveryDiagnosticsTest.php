<?php

namespace Tests\Feature;

use App\Models\OauthProvider;
use App\Services\OauthService;
use App\Services\OidcDnsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * A rejected OIDC destination is indistinguishable from a wrong client secret at the sign-in
 * screen. These tests pin that the reason always reaches the operator's log, and that nothing
 * that could disclose a credential travels with it.
 */
class OidcDiscoveryDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_blocked_discovery_destination_logs_the_offending_address(): void
    {
        Log::spy();
        $this->mock(OidcDnsResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->andReturn(['10.169.169.253']);
        });
        $this->provider('https://authentik.lan/application/o/rustdesk');
        Http::fake();

        $this->assertSame(
            ['', ''],
            app(OauthService::class)->beginAuth('security-oidc', 'device', 'uuid', [])
        );

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->withArgs(
            function (string $message, array $context): bool {
                return $message === 'OIDC discovery failed'
                    && ($context['op'] ?? null) === 'security-oidc'
                    && str_contains((string) ($context['reason'] ?? ''), 'non-public')
                    && str_contains((string) ($context['reason'] ?? ''), '10.169.169.253')
                    && ($context['trusted'] ?? null) === 0;
            }
        );
    }

    public function test_the_log_names_allowlist_entries_that_could_not_be_used(): void
    {
        Log::spy();
        config()->set('rustdesk.oidc.allowed_networks', ['10.169.169.0/33']);
        $this->mock(OidcDnsResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->andReturn(['10.169.169.253']);
        });
        $this->provider('https://authentik.lan/application/o/rustdesk');
        Http::fake();

        app(OauthService::class)->beginAuth('security-oidc', 'device', 'uuid', []);

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context): bool => $message === 'OIDC discovery failed'
                && ($context['rejected'] ?? []) === ['10.169.169.0/33']
        );
    }

    public function test_a_transport_failure_logs_a_reason_without_the_request_url(): void
    {
        Log::spy();
        $this->fakePublicDns();
        $this->provider();
        Http::fake(function (): never {
            throw new RuntimeException('cURL error 60: SSL certificate problem for https://operator:hunter2@issuer.example.com/tenant?token=SECRETVALUE');
        });

        app(OauthService::class)->beginAuth('security-oidc', 'device', 'uuid', []);

        Log::shouldHaveReceived('warning')->withArgs(
            function (string $message, array $context): bool {
                $encoded = (string) json_encode($context);

                return $message === 'OIDC discovery failed'
                    && str_contains((string) ($context['reason'] ?? ''), 'SSL certificate problem')
                    && ! str_contains($encoded, 'hunter2')
                    && ! str_contains($encoded, 'SECRETVALUE');
            }
        );
    }

    public function test_a_non_success_discovery_response_logs_its_status(): void
    {
        Log::spy();
        $this->fakePublicDns();
        $this->provider();
        Http::fake([
            $this->discoveryUrl() => Http::response('', 503),
        ]);

        app(OauthService::class)->beginAuth('security-oidc', 'device', 'uuid', []);

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context): bool => $message === 'OIDC discovery failed'
                && ($context['status'] ?? null) === 503
        );
    }

    public function test_an_issuer_mismatch_says_so_instead_of_failing_silently(): void
    {
        Log::spy();
        $this->fakePublicDns();
        $this->provider();
        Http::fake([
            $this->discoveryUrl() => Http::response([
                'issuer' => 'https://attacker.example.com/tenant',
                'authorization_endpoint' => 'https://login.example.net/oauth/authorize',
                'token_endpoint' => 'https://token.example.net/oauth/token',
                'userinfo_endpoint' => 'https://profile.example.net/oidc/userinfo',
            ]),
        ]);

        app(OauthService::class)->beginAuth('security-oidc', 'device', 'uuid', []);

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context): bool => $message === 'OIDC discovery failed'
                && str_contains((string) ($context['reason'] ?? ''), 'different issuer')
        );
    }

    public function test_no_discovery_context_ever_carries_the_client_secret(): void
    {
        Log::spy();
        $this->fakePublicDns();
        $this->provider();
        Http::fake([
            $this->discoveryUrl() => Http::response(['issuer' => 'https://issuer.example.com/tenant']),
        ]);

        app(OauthService::class)->beginAuth('security-oidc', 'device', 'uuid', []);

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context): bool => $message === 'OIDC discovery failed'
                && ! str_contains((string) json_encode($context), 'top-secret-client-value')
        );
    }

    public function test_a_rejected_endpoint_names_which_endpoint_and_which_host(): void
    {
        Log::spy();
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

        app(OauthService::class)->beginAuth('security-oidc', 'device', 'uuid', []);

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context): bool => $message === 'OIDC discovery failed'
                && ($context['endpoint'] ?? null) === 'token_endpoint'
                && ($context['endpoint_url'] ?? null) === 'https://vault.internal.lan/v1/token'
        );
    }

    public function test_an_issuer_carrying_credentials_is_reduced_before_it_is_logged(): void
    {
        Log::spy();
        $this->fakePublicDns();
        $this->provider('https://operator:hunter2@issuer.example.com/tenant?token=SECRETVALUE');
        Http::fake();

        app(OauthService::class)->beginAuth('security-oidc', 'device', 'uuid', []);

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->withArgs(
            function (string $message, array $context): bool {
                $encoded = (string) json_encode($context);

                return $message === 'OIDC discovery failed'
                    && ($context['issuer'] ?? null) === 'https://issuer.example.com/tenant'
                    && ! str_contains($encoded, 'hunter2')
                    && ! str_contains($encoded, 'SECRETVALUE');
            }
        );
    }

    public function test_a_successful_discovery_stays_quiet(): void
    {
        Log::spy();
        $this->fakePublicDns();
        $this->provider();
        Http::fake([
            $this->discoveryUrl() => Http::response([
                'issuer' => 'https://issuer.example.com/tenant',
                'authorization_endpoint' => 'https://login.example.net/oauth/authorize',
                'token_endpoint' => 'https://token.example.net/oauth/token',
                'userinfo_endpoint' => 'https://profile.example.net/oidc/userinfo',
            ]),
        ]);

        [$code] = app(OauthService::class)->beginAuth('security-oidc', 'device', 'uuid', []);

        $this->assertNotSame('', $code);
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    private function provider(string $issuer = 'https://issuer.example.com/tenant'): OauthProvider
    {
        return OauthProvider::create([
            'op' => 'security-oidc',
            'type' => OauthService::TYPE_OIDC,
            'client_id' => 'rustdesk',
            'client_secret' => 'top-secret-client-value',
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
}
