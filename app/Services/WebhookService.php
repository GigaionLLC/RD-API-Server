<?php

namespace App\Services;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Delivers server events to configured outbound webhooks (Slack / Telegram / generic JSON).
 *
 * Delivery is synchronous and best-effort with a short timeout, so it works without a queue
 * worker running (the simple single-container deployment). It never throws — a failing or slow
 * webhook must never break the request that triggered it — and it returns early and cheaply
 * (one indexed query) when no webhook subscribes to the event.
 *
 * Every send is recorded as a WebhookDelivery. A failed delivery is scheduled for retry
 * (next_attempt_at, exponential backoff) and re-driven by `php artisan webhooks:retry`, or
 * resent manually from the console.
 */
class WebhookService
{
    /** How long to wait on a single webhook endpoint before giving up. */
    private const TIMEOUT_SECONDS = 4;

    public function __construct(private readonly WebhookDestinationGuard $destinationGuard) {}

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
     * Record a delivery for an event and make the first attempt. Returns whether the endpoint
     * accepted it (2xx). Used by the alarm/audit hooks and the admin "Test" button.
     *
     * @param  array<string, mixed>  $payload
     */
    public function deliver(Webhook $hook, string $event, array $payload): bool
    {
        $delivery = WebhookDelivery::create([
            'webhook_id' => $hook->id,
            'event' => $event,
            'payload' => $payload,
            'status' => WebhookDelivery::STATUS_PENDING,
            'attempts' => 0,
        ]);

        return $this->attempt($hook, $delivery);
    }

    /**
     * (Re-)attempt a recorded delivery, updating both the delivery row and the webhook's status
     * counters. On failure within the attempt cap it schedules the next retry with exponential
     * backoff; once the cap is hit it stops scheduling. Returns whether the endpoint accepted it.
     */
    public function attempt(Webhook $hook, WebhookDelivery $delivery): bool
    {
        $statusCode = 'error';
        $ok = false;
        $error = null;

        try {
            $response = $this->send($hook, $delivery->event, (array) $delivery->payload);
            $statusCode = (string) $response->status();
            $ok = $response->successful();
            if (! $ok) {
                $error = 'HTTP '.$statusCode;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $statusCode = str_contains(strtolower($error), 'timed out') ? 'timeout' : 'error';
            Log::warning('Webhook delivery failed', [
                'webhook_id' => $hook->id,
                'event' => $delivery->event,
                'error' => $error,
            ]);
        }

        $attempts = $delivery->attempts + 1;

        $delivery->forceFill([
            'attempts' => $attempts,
            'status' => $ok ? WebhookDelivery::STATUS_SUCCESS : WebhookDelivery::STATUS_FAILED,
            'status_code' => $statusCode,
            'error' => $ok ? null : Str::limit((string) $error, 500, ''),
            'delivered_at' => $ok ? now() : $delivery->delivered_at,
            'next_attempt_at' => $ok || $attempts >= WebhookDelivery::MAX_ATTEMPTS
                ? null
                : now()->addMinutes(min(60, 2 ** $attempts)),
        ])->save();

        $hook->forceFill([
            'last_triggered_at' => now(),
            'last_status' => $statusCode,
            'failure_count' => $ok ? 0 : $hook->failure_count + 1,
        ])->save();

        return $ok;
    }

    /**
     * Build and send the per-type HTTP request. Throws on transport failure / timeout.
     *
     * @param  array<string, mixed>  $payload
     */
    private function send(Webhook $hook, string $event, array $payload): Response
    {
        $summary = $this->summarize($event, $payload);

        // Resolve and pin immediately before every attempt. This protects automatic retries and
        // records a normal failed delivery when DNS changes to a private/reserved destination.
        $destination = $this->destinationGuard->resolve($hook->url);
        $request = Http::timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(self::TIMEOUT_SECONDS)
            ->withoutRedirecting()
            ->withOptions($this->destinationGuard->requestOptions($destination));

        return match ($hook->type) {
            Webhook::TYPE_SLACK => $request
                ->asJson()
                ->post($hook->url, ['text' => $summary]),
            Webhook::TYPE_TELEGRAM => $request
                ->asJson()
                ->post($hook->url, [
                    'chat_id' => $hook->secret,
                    'text' => $summary,
                    'disable_web_page_preview' => true,
                ]),
            default => $this->postGeneric($request, $hook, $event, $summary, $payload),
        };
    }

    /**
     * POST the generic JSON envelope, HMAC-signing the body when the webhook has a secret.
     *
     * @param  array<string, mixed>  $payload
     */
    private function postGeneric(
        PendingRequest $request,
        Webhook $hook,
        string $event,
        string $summary,
        array $payload
    ): Response {
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

        return $request
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
