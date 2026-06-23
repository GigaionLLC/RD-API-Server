<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use App\Models\Webhook;
use App\Services\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Outbound webhooks: event fan-out, per-type payload shaping, HMAC signing, delivery
 * bookkeeping, and admin management.
 */
class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'username' => 'admin'.uniqid(), 'password' => 'secret12345',
            'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);
    }

    public function test_dispatch_only_hits_enabled_subscribed_webhooks(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $hit = Webhook::create([
            'name' => 'hit', 'type' => Webhook::TYPE_GENERIC, 'url' => 'https://example.test/hook',
            'events' => ['alarm.raised'], 'enabled' => true,
        ]);
        Webhook::create([
            'name' => 'disabled', 'type' => Webhook::TYPE_GENERIC, 'url' => 'https://example.test/off',
            'events' => ['alarm.raised'], 'enabled' => false,
        ]);
        Webhook::create([
            'name' => 'other-event', 'type' => Webhook::TYPE_GENERIC, 'url' => 'https://example.test/other',
            'events' => ['connection.new'], 'enabled' => true,
        ]);

        app(WebhookService::class)->dispatch('alarm.raised', ['peer_id' => '1', 'message' => 'boom']);

        Http::assertSentCount(1);
        Http::assertSent(fn ($req) => $req->url() === 'https://example.test/hook');

        $this->assertSame('200', $hit->refresh()->last_status);
        $this->assertNotNull($hit->last_triggered_at);
    }

    public function test_generic_payload_is_signed_when_a_secret_is_set(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $hook = Webhook::create([
            'name' => 'signed', 'type' => Webhook::TYPE_GENERIC, 'url' => 'https://example.test/s',
            'secret' => 'topsecret', 'events' => ['alarm.raised'], 'enabled' => true,
        ]);

        app(WebhookService::class)->deliver($hook, 'alarm.raised', ['peer_id' => '9']);

        Http::assertSent(function ($req) {
            $sig = $req->header('X-RustDesk-Signature')[0] ?? '';
            $expected = 'sha256='.hash_hmac('sha256', $req->body(), 'topsecret');

            return $sig === $expected && ($req->header('X-RustDesk-Event')[0] ?? '') === 'alarm.raised';
        });
    }

    public function test_slack_webhook_posts_a_text_field(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $hook = Webhook::create([
            'name' => 'slack', 'type' => Webhook::TYPE_SLACK, 'url' => 'https://hooks.slack.test/x',
            'events' => ['connection.new'], 'enabled' => true,
        ]);

        $this->assertTrue(app(WebhookService::class)->deliver($hook, 'connection.new', ['peer_id' => '7']));
        Http::assertSent(fn ($req) => isset($req->data()['text']) && str_contains($req->data()['text'], '7'));
    }

    public function test_failed_delivery_records_failure(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);

        $hook = Webhook::create([
            'name' => 'bad', 'type' => Webhook::TYPE_GENERIC, 'url' => 'https://example.test/bad',
            'events' => ['alarm.raised'], 'enabled' => true,
        ]);

        $this->assertFalse(app(WebhookService::class)->deliver($hook, 'alarm.raised', []));
        $this->assertSame(1, $hook->refresh()->failure_count);
        $this->assertSame('500', $hook->last_status);
    }

    public function test_alarm_ingestion_fans_out_to_webhooks(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        Webhook::create([
            'name' => 'alarmhook', 'type' => Webhook::TYPE_GENERIC, 'url' => 'https://example.test/a',
            'events' => ['alarm.raised'], 'enabled' => true,
        ]);
        Device::create(['rustdesk_id' => '123', 'uuid' => 'u']);

        $this->postJson('/api/audit/alarm', ['id' => '123', 'typ' => 1, 'info' => '{}'])->assertOk();

        Http::assertSent(fn ($req) => $req->url() === 'https://example.test/a');
    }

    public function test_admin_can_create_test_and_delete_a_webhook(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.webhooks.store'), [
            'name' => 'Ops', 'type' => Webhook::TYPE_SLACK, 'url' => 'https://hooks.slack.test/y',
            'events' => ['alarm.raised'], 'enabled' => '1',
        ])->assertRedirect();

        $hook = Webhook::firstOrFail();
        $this->assertSame(['alarm.raised'], $hook->events);

        $this->actingAs($admin)->post(route('admin.webhooks.test', $hook))->assertSessionHas('status');
        $this->actingAs($admin)->delete(route('admin.webhooks.destroy', $hook))->assertRedirect();
        $this->assertModelMissing($hook);
    }
}
