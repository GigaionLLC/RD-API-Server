<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\WebhookDestinationGuard;
use App\Services\WebhookDnsResolver;
use App\Services\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WebhookDestinationSecurityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return iterable<string, array{string}>
     */
    public static function blockedUrlProvider(): iterable
    {
        yield 'loopback IPv4' => ['http://127.0.0.1/hook'];
        yield 'short IPv4 form' => ['http://127.1/hook'];
        yield 'decimal IPv4 form' => ['http://2130706433/hook'];
        yield 'hexadecimal IPv4 form' => ['http://0x7f000001/hook'];
        yield 'octal IPv4 form' => ['http://0177.0.0.1/hook'];
        yield 'encoded IPv4 separators' => ['http://127%2e0%2e0%2e1/hook'];
        yield 'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'private IPv4' => ['https://10.20.30.40/hook'];
        yield 'carrier-grade NAT' => ['https://100.64.0.1/hook'];
        yield 'loopback IPv6' => ['http://[::1]/hook'];
        yield 'IPv4-mapped IPv6' => ['http://[::ffff:127.0.0.1]/hook'];
        yield 'link-local IPv6' => ['http://[fe80::1]/hook'];
        yield 'unique-local IPv6' => ['http://[fd00::1]/hook'];
        yield 'NAT64 special range' => ['http://[64:ff9b::a9fe:a9fe]/hook'];
        yield 'reserved IPv6 space' => ['http://[4000::1]/hook'];
        yield 'IPv6 documentation range' => ['http://[3fff::1]/hook'];
        yield 'embedded credentials' => ['https://operator:secret@example.com/hook'];
        yield 'non-web scheme' => ['gopher://example.com/_payload'];
        yield 'unexpected port' => ['https://example.com:22/hook'];
    }

    #[DataProvider('blockedUrlProvider')]
    public function test_guard_rejects_non_public_or_ambiguous_destinations(string $url): void
    {
        $resolver = $this->mock(WebhookDnsResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->andReturn([]);
        });

        $this->expectException(InvalidArgumentException::class);

        (new WebhookDestinationGuard($resolver))->resolve($url);
    }

    public function test_guard_rejects_a_hostname_when_any_dns_answer_is_non_public(): void
    {
        $resolver = $this->mock(WebhookDnsResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')
                ->once()
                ->with('hooks.example.com')
                ->andReturn(['8.8.8.8', '127.0.0.1']);
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-public');

        (new WebhookDestinationGuard($resolver))->resolve('https://hooks.example.com/events');
    }

    public function test_noncanonical_ip_notation_is_rejected_before_dns_resolution(): void
    {
        $resolver = $this->mock(WebhookDnsResolver::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('resolve');
        });
        $guard = new WebhookDestinationGuard($resolver);

        foreach (['127.1', '2130706433', '0x7f000001', '0177.0.0.1', '0x7f.0.0.1'] as $host) {
            try {
                $guard->resolve('http://'.$host.'/hook');
                $this->fail('Expected alternate IPv4 notation to be rejected: '.$host);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_guard_rejects_an_unresolved_hostname(): void
    {
        $resolver = $this->mock(WebhookDnsResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->once()->andReturn([]);
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('could not be resolved');

        (new WebhookDestinationGuard($resolver))->resolve('https://missing.example.com/events');
    }

    public function test_guard_allows_public_http_and_https_destinations(): void
    {
        $resolver = $this->mock(WebhookDnsResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->twice()->andReturn(['8.8.8.8']);
        });
        $guard = new WebhookDestinationGuard($resolver);

        $this->assertSame(80, $guard->resolve('http://hooks.example.com/events')['port']);
        $this->assertSame(443, $guard->resolve('https://hooks.example.com/events')['port']);
    }

    public function test_custom_public_port_must_be_explicitly_allowed(): void
    {
        config()->set('rustdesk.webhooks.allowed_ports', [80, 443, 8443]);
        $resolver = $this->mock(WebhookDnsResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->once()->andReturn(['8.8.8.8']);
        });

        $destination = (new WebhookDestinationGuard($resolver))
            ->resolve('https://hooks.example.com:8443/events');

        $this->assertSame(8443, $destination['port']);
    }

    public function test_request_options_pin_dns_and_disable_proxy_inheritance(): void
    {
        $resolver = $this->mock(WebhookDnsResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->once()->andReturn(['8.8.8.8']);
        });
        $guard = new WebhookDestinationGuard($resolver);

        $options = $guard->requestOptions($guard->resolve('https://hooks.example.com/events'));

        $this->assertSame('', $options['proxy']);
        $this->assertSame('', $options['curl'][constant('CURLOPT_PROXY')]);
        $this->assertFalse($options['curl'][constant('CURLOPT_FOLLOWLOCATION')]);
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $this->assertSame('http,https', $options['curl'][constant('CURLOPT_PROTOCOLS_STR')]);
        } else {
            $this->assertSame(
                constant('CURLPROTO_HTTP') | constant('CURLPROTO_HTTPS'),
                $options['curl'][constant('CURLOPT_PROTOCOLS')]
            );
        }
        $this->assertSame(
            ['hooks.example.com:443:8.8.8.8'],
            $options['curl'][constant('CURLOPT_RESOLVE')]
        );
        $this->assertTrue($options['curl'][constant('CURLOPT_FRESH_CONNECT')]);
        $this->assertTrue($options['curl'][constant('CURLOPT_FORBID_REUSE')]);
    }

    public function test_public_ipv6_literal_does_not_need_a_dns_pin(): void
    {
        $resolver = $this->mock(WebhookDnsResolver::class);
        $guard = new WebhookDestinationGuard($resolver);

        $destination = $guard->resolve('https://[2606:4700:4700::1111]/events');
        $options = $guard->requestOptions($destination);

        $this->assertTrue($destination['is_ip_literal']);
        $this->assertArrayNotHasKey(constant('CURLOPT_RESOLVE'), $options['curl']);
    }

    public function test_blocked_delivery_never_reaches_http_transport_and_is_recorded(): void
    {
        Http::fake();
        $this->mock(WebhookDnsResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->once()->andReturn(['169.254.169.254']);
        });
        $hook = $this->webhook('https://metadata.attacker.example/hook');

        $this->assertFalse(app(WebhookService::class)->deliver($hook, 'alarm.raised', []));

        Http::assertNothingSent();
        $delivery = WebhookDelivery::firstOrFail();
        $this->assertSame(WebhookDelivery::STATUS_FAILED, $delivery->status);
        $this->assertStringContainsString('non-public', (string) $delivery->error);
    }

    public function test_redirects_are_not_followed(): void
    {
        $this->mock(WebhookDnsResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->once()->andReturn(['8.8.8.8']);
        });
        Http::fake([
            'https://hooks.example.com/start' => Http::response('', 302, [
                'Location' => 'http://169.254.169.254/latest/meta-data/',
            ]),
        ]);
        $hook = $this->webhook('https://hooks.example.com/start');

        $this->assertFalse(app(WebhookService::class)->deliver($hook, 'alarm.raised', []));

        Http::assertSentCount(1);
        $this->assertSame('302', $hook->refresh()->last_status);
    }

    public function test_each_retry_resolves_and_revalidates_the_destination(): void
    {
        $this->mock(WebhookDnsResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')
                ->twice()
                ->andReturn(['8.8.8.8'], ['127.0.0.1']);
        });
        Http::fake(['*' => Http::response('retry', 500)]);
        $hook = $this->webhook('https://hooks.example.com/events');
        $service = app(WebhookService::class);

        $this->assertFalse($service->deliver($hook, 'alarm.raised', []));
        $delivery = WebhookDelivery::firstOrFail();
        $this->assertFalse($service->attempt($hook->refresh(), $delivery->refresh()));

        Http::assertSentCount(1);
        $this->assertStringContainsString('non-public', (string) $delivery->refresh()->error);
    }

    public function test_admin_validation_rejects_non_http_webhook_schemes(): void
    {
        $admin = User::create([
            'username' => 'admin'.uniqid(), 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);

        $this->actingAs($admin)->post(route('admin.webhooks.store'), [
            'name' => 'Unsafe',
            'type' => Webhook::TYPE_GENERIC,
            'url' => 'ftp://example.com/hook',
            'events' => ['alarm.raised'],
        ])->assertSessionHasErrors('url');

        $this->assertDatabaseCount('webhooks', 0);
    }

    private function webhook(string $url): Webhook
    {
        return Webhook::create([
            'name' => 'security-test',
            'type' => Webhook::TYPE_GENERIC,
            'url' => $url,
            'events' => ['alarm.raised'],
            'enabled' => true,
        ]);
    }
}
