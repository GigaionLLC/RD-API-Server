<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\WebhookDnsResolver;
use App\Services\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class WebhookCredentialRedactionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(WebhookDnsResolver::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resolve')->andReturn(['8.8.8.8']);
        });
    }

    /**
     * @return array<string, array{string, string, string, array<int, string>}>
     */
    public static function credentialUrlProvider(): array
    {
        return [
            'Slack userinfo, path, query, and fragment' => [
                Webhook::TYPE_SLACK,
                'https://alice:password@hooks.slack.com/services/T012/B345/SLACK_TOKEN?signature=QUERY_SECRET#FRAGMENT_SECRET',
                'https://hooks.slack.com/services/[redacted]?[redacted]#[redacted]',
                ['alice', 'password', 'T012', 'B345', 'SLACK_TOKEN', 'QUERY_SECRET', 'FRAGMENT_SECRET'],
            ],
            'Telegram bot token and query' => [
                Webhook::TYPE_TELEGRAM,
                'https://api.telegram.org/bot123456:TELEGRAM_TOKEN/sendMessage?chat_id=987654',
                'https://api.telegram.org/bot[redacted]/sendMessage?[redacted]',
                ['123456', 'TELEGRAM_TOKEN', '987654'],
            ],
            'Generic userinfo, private path, and query' => [
                Webhook::TYPE_GENERIC,
                'https://api-user:api-password@example.test:8443/team/PATH_TOKEN?api_key=QUERY_SECRET#FRAGMENT_SECRET',
                'https://example.test:8443/[redacted]?[redacted]#[redacted]',
                ['api-user', 'api-password', 'team', 'PATH_TOKEN', 'api_key', 'QUERY_SECRET', 'FRAGMENT_SECRET'],
            ],
        ];
    }

    /**
     * @param  array<int, string>  $forbidden
     */
    #[DataProvider('credentialUrlProvider')]
    public function test_webhook_model_redacts_every_credential_bearing_url_component(
        string $type,
        string $url,
        string $expected,
        array $forbidden
    ): void {
        $webhook = new Webhook(['type' => $type, 'url' => $url]);

        $this->assertSame($expected, $webhook->redactedUrl());
        foreach ($forbidden as $credential) {
            $this->assertStringNotContainsString($credential, $webhook->redactedUrl());
        }
    }

    public function test_transport_failures_are_redacted_before_storage_and_logging(): void
    {
        $url = 'https://hooks.slack.com/services/T012/B345/TRANSPORT_TOKEN?signature=QUERY_SECRET';
        $secret = 'HMAC_SECRET';
        $webhook = $this->webhook($url, Webhook::TYPE_SLACK, $secret);

        Http::fake(static function () use ($url, $secret): never {
            throw new RuntimeException("POST {$url} with {$secret} failed");
        });
        Log::spy();

        $this->assertFalse(app(WebhookService::class)
            ->deliver($webhook, 'alarm.raised', ['peer_id' => '42']));

        $error = (string) WebhookDelivery::firstOrFail()->error;
        $this->assertStringContainsString('[redacted]', $error);
        foreach (['T012', 'B345', 'TRANSPORT_TOKEN', 'QUERY_SECRET', $secret] as $credential) {
            $this->assertStringNotContainsString($credential, $error);
        }

        Log::shouldHaveReceived('warning')->once()->withArgs(
            function (string $message, array $context) use ($secret): bool {
                $loggedError = (string) ($context['error'] ?? '');

                return $message === 'Webhook delivery failed'
                    && str_contains($loggedError, '[redacted]')
                    && ! str_contains($loggedError, 'TRANSPORT_TOKEN')
                    && ! str_contains($loggedError, 'QUERY_SECRET')
                    && ! str_contains($loggedError, $secret);
            }
        );
    }

    public function test_raw_destination_and_secret_are_hidden_from_model_serialization(): void
    {
        $webhook = new Webhook([
            'name' => 'Serialized webhook',
            'type' => Webhook::TYPE_GENERIC,
            'url' => 'https://example.test/private-token',
            'secret' => 'serialization-secret',
            'events' => ['alarm.raised'],
        ]);

        $serialized = $webhook->toArray();
        $serializedDelivery = (new WebhookDelivery(['error' => 'https://example.test/private-token']))->toArray();

        $this->assertArrayNotHasKey('url', $serialized);
        $this->assertArrayNotHasKey('secret', $serialized);
        $this->assertArrayNotHasKey('error', $serializedDelivery);
    }

    public function test_full_editor_keeps_management_workflow_but_only_sees_safe_history_labels(): void
    {
        $admin = $this->fullAdmin();
        $url = 'https://hooks.slack.com/services/T012/B345/UI_TOKEN?signature=QUERY_SECRET';
        $secret = 'STORED_SECRET';
        $webhook = $this->webhook($url, Webhook::TYPE_SLACK, $secret);
        $delivery = $this->failedDelivery($webhook, "POST {$url} using {$secret} failed");

        $index = $this->actingAs($admin)->get(route('admin.webhooks.index'));
        $index->assertOk()
            ->assertSee('Create webhook')
            ->assertSee('https://hooks.slack.com/services/[redacted]?[redacted]')
            ->assertSee('action="'.route('admin.webhooks.toggle', $webhook).'"', false)
            ->assertSee('action="'.route('admin.webhooks.test', $webhook).'"', false)
            ->assertSee('action="'.route('admin.webhooks.destroy', $webhook).'"', false);
        $this->assertResponseHidesCredentials($index, [$url, 'T012', 'B345', 'UI_TOKEN', 'QUERY_SECRET', $secret]);

        $history = $this->actingAs($admin)->get(route('admin.webhooks.deliveries', $webhook));
        $history->assertOk()
            ->assertSee('https://hooks.slack.com/services/[redacted]?[redacted]')
            ->assertSee('action="'.route('admin.webhooks.deliveries.resend', $delivery).'"', false);
        $this->assertResponseHidesCredentials($history, [$url, 'T012', 'B345', 'UI_TOKEN', 'QUERY_SECRET', $secret]);
    }

    public function test_view_only_delegate_receives_no_secrets_or_mutation_controls(): void
    {
        $viewer = $this->viewer();
        $url = 'https://api-user:api-password@example.test/team/PATH_TOKEN?api_key=QUERY_SECRET#FRAGMENT_SECRET';
        $secret = 'STORED_SECRET';
        $webhook = $this->webhook($url, Webhook::TYPE_GENERIC, $secret);
        $delivery = $this->failedDelivery($webhook, "POST {$url} using {$secret} failed");
        $forbidden = [
            $url, 'api-user', 'api-password', 'team', 'PATH_TOKEN', 'api_key',
            'QUERY_SECRET', 'FRAGMENT_SECRET', $secret,
        ];

        $index = $this->actingAs($viewer)->get(route('admin.webhooks.index'));
        $index->assertOk()
            ->assertSee('view-only access')
            ->assertSee('https://example.test/[redacted]?[redacted]#[redacted]')
            ->assertDontSee('Create webhook')
            ->assertDontSee('action="'.route('admin.webhooks.toggle', $webhook).'"', false)
            ->assertDontSee('action="'.route('admin.webhooks.test', $webhook).'"', false)
            ->assertDontSee('action="'.route('admin.webhooks.destroy', $webhook).'"', false);
        $this->assertResponseHidesCredentials($index, $forbidden);

        $history = $this->actingAs($viewer)->get(route('admin.webhooks.deliveries', $webhook));
        $history->assertOk()
            ->assertSee('view-only access')
            ->assertSee('https://example.test/[redacted]?[redacted]#[redacted]')
            ->assertDontSee('action="'.route('admin.webhooks.deliveries.resend', $delivery).'"', false);
        $this->assertResponseHidesCredentials($history, $forbidden);

        $this->actingAs($viewer)->post(route('admin.webhooks.store'), [
            'name' => 'Denied',
            'type' => Webhook::TYPE_GENERIC,
            'url' => 'https://example.test/denied',
            'events' => ['alarm.raised'],
        ])->assertForbidden();
        $this->actingAs($viewer)->put(route('admin.webhooks.update', $webhook), [])->assertForbidden();
        $this->actingAs($viewer)->post(route('admin.webhooks.toggle', $webhook))->assertForbidden();
        $this->actingAs($viewer)->post(route('admin.webhooks.test', $webhook))->assertForbidden();
        $this->actingAs($viewer)->post(route('admin.webhooks.deliveries.resend', $delivery))->assertForbidden();
        $this->actingAs($viewer)->delete(route('admin.webhooks.destroy', $webhook))->assertForbidden();
    }

    private function fullAdmin(): User
    {
        return User::create([
            'username' => 'webhook-admin',
            'password' => 'secret12345',
            'is_admin' => true,
            'status' => User::STATUS_NORMAL,
        ]);
    }

    private function viewer(): User
    {
        $role = AdminRole::create([
            'name' => 'Webhook viewer',
            'type' => AdminRole::TYPE_INDIVIDUAL,
            'scope' => [],
            'perms' => ['webhooks.view'],
        ]);
        $viewer = User::create([
            'username' => 'webhook-viewer',
            'password' => 'secret12345',
            'is_admin' => false,
            'status' => User::STATUS_NORMAL,
        ]);
        $viewer->adminRoles()->attach($role);

        return $viewer;
    }

    private function webhook(string $url, string $type, ?string $secret = null): Webhook
    {
        return Webhook::create([
            'name' => 'Credential test',
            'type' => $type,
            'url' => $url,
            'secret' => $secret,
            'events' => ['alarm.raised'],
            'enabled' => true,
        ]);
    }

    private function failedDelivery(Webhook $webhook, string $error): WebhookDelivery
    {
        return WebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'event' => 'alarm.raised',
            'payload' => ['peer_id' => '42'],
            'status' => WebhookDelivery::STATUS_FAILED,
            'status_code' => 'error',
            'attempts' => 1,
            'error' => $error,
        ]);
    }

    /**
     * @param  array<int, string>  $credentials
     */
    private function assertResponseHidesCredentials(TestResponse $response, array $credentials): void
    {
        foreach ($credentials as $credential) {
            $response->assertDontSee($credential, false);
        }
    }
}
