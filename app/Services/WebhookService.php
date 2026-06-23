<?php

namespace App\Services;

use App\Models\Webhook;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers server events to configured outbound webhooks (Slack / Telegram / generic JSON).
 *
 * Delivery is synchronous and best-effort with a short timeout, so it works without a queue
 * worker running (the simple single-container deployment). It never throws — a failing or slow
 * webhook must never break the request that triggered it — and it returns early and cheaply
 * (one indexed query) when no webhook subscribes to the event.
 */
class WebhookService
{
    /** How long to wait on a single webhook endpoint before giving up. */
    private const TIMEOUT_SECONDS = 4;

    /**
     * Fan a server event out to every enabled webhook subscribed to it.
     *
     * @param  array<string, mixed>  $payload  event-specific data (becomes `data` in the body)
     */
    public function dispatch(string $event, array $payload): void
    {
        try {
            $hooks = Webhook::where('enabled', true)->get()
                ->filter(fn (Webhook $hook): bool => $hook->subscribesTo($event));

            foreach ($hooks as $hook) {
                $this->deliver($hook, $event, $payload);
            }
        } catch (Throwable $e) {
            Log::error('WebhookService dispatch failed', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Deliver a single event to one webhook, recording the outcome on the row. Returns whether
     * the endpoint accepted it (2xx). Used directly by the admin "Test" button.
     *
     * @param  array<string, mixed>  $payload
     */
    public function deliver(Webhook $hook, string $event, array $payload): bool
    {
        $status = 'error';
        $ok = false;

        try {
            $summary = $this->summarize($event, $payload);

            $response = match ($hook->type) {
                Webhook::TYPE_SLACK => Http::timeout(self::TIMEOUT_SECONDS)
                    ->asJson()
                    ->post($hook->url, ['text' => $summary]),
                Webhook::TYPE_TELEGRAM => Http::timeout(self::TIMEOUT_SECONDS)
                    ->asJson()
                    ->post($hook->url, [
                        'chat_id' => $hook->secret,
                        'text' => $summary,
                        'disable_web_page_preview' => true,
                    ]),
                default => $this->postGeneric($hook, $event, $summary, $payload),
            };

            $status = (string) $response->status();
            $ok = $response->successful();
        } catch (Throwable $e) {
            $status = str_contains(strtolower($e->getMessage()), 'timed out') ? 'timeout' : 'error';
            Log::warning('Webhook delivery failed', [
                'webhook_id' => $hook->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }

        $hook->forceFill([
            'last_triggered_at' => now(),
            'last_status' => $status,
            'failure_count' => $ok ? 0 : ($hook->failure_count + 1),
        ])->save();

        return $ok;
    }

    /**
     * POST the generic JSON envelope, HMAC-signing the body when the webhook has a secret.
     *
     * @param  array<string, mixed>  $payload
     */
    private function postGeneric(Webhook $hook, string $event, string $summary, array $payload): Response
    {
        $body = (string) json_encode([
            'event' => $event,
            'summary' => $summary,
            'timestamp' => now()->toIso8601String(),
            'data' => $payload,
        ], JSON_UNESCAPED_SLASHES);

        $headers = [
            'Content-Type' => 'application/json',
            'X-RustDesk-Event' => $event,
        ];

        if (! empty($hook->secret)) {
            $headers['X-RustDesk-Signature'] = 'sha256='.hash_hmac('sha256', $body, (string) $hook->secret);
        }

        return Http::timeout(self::TIMEOUT_SECONDS)
            ->withHeaders($headers)
            ->withBody($body, 'application/json')
            ->post($hook->url);
    }

    /**
     * A short human-readable line for chat-style targets (Slack / Telegram) and the generic
     * `summary` field.
     *
     * @param  array<string, mixed>  $payload
     */
    private function summarize(string $event, array $payload): string
    {
        $peer = (string) ($payload['peer_id'] ?? $payload['id'] ?? '');
        $ip = (string) ($payload['ip'] ?? '');
        $suffix = ($peer !== '' ? ' '.$peer : '').($ip !== '' ? ' ('.$ip.')' : '');

        return match ($event) {
            'alarm.raised' => '🔔 RustDesk alarm: '.((string) ($payload['message'] ?? 'alarm')).$suffix,
            'connection.new' => '🟢 RustDesk session started'.$suffix,
            'connection.closed' => '⚪ RustDesk session ended'.$suffix,
            'device.new' => '🆕 RustDesk device registered'.$suffix,
            default => 'RustDesk event '.$event.$suffix,
        };
    }
}
