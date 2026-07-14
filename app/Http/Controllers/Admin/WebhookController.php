<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\WebhookService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Manage outbound webhooks / notification targets (Slack / Telegram / generic JSON). Events
 * are delivered best-effort by WebhookService; this screen creates, toggles, tests and
 * revokes them.
 */
class WebhookController extends Controller
{
    public function index(): View
    {
        $webhooks = Webhook::orderByDesc('id')->get();

        return view('admin.webhooks.index', [
            'webhooks' => $webhooks,
            'typeList' => Webhook::TYPES,
            'eventList' => Webhook::EVENTS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateWebhook($request);

        Webhook::create([
            'name' => $data['name'],
            'type' => $data['type'],
            'url' => $data['url'],
            'secret' => $data['secret'] ?? null,
            'events' => array_values($data['events']),
            'enabled' => $request->boolean('enabled', true),
        ]);

        return back()->with('status', 'Webhook created.');
    }

    public function update(Request $request, Webhook $webhook): RedirectResponse
    {
        $data = $this->validateWebhook($request);

        $webhook->update([
            'name' => $data['name'],
            'type' => $data['type'],
            'url' => $data['url'],
            'secret' => $data['secret'] ?? null,
            'events' => array_values($data['events']),
            'enabled' => $request->boolean('enabled'),
        ]);

        return back()->with('status', 'Webhook updated.');
    }

    /**
     * Flip a webhook's enabled flag without touching the rest of its config.
     */
    public function toggle(Webhook $webhook): RedirectResponse
    {
        $webhook->forceFill(['enabled' => ! $webhook->enabled])->save();

        return back()->with('status', $webhook->enabled ? 'Webhook enabled.' : 'Webhook disabled.');
    }

    /**
     * Send a sample event to the endpoint and report the delivery outcome inline.
     */
    public function test(Webhook $webhook, WebhookService $service): RedirectResponse
    {
        $event = $webhook->events[0] ?? 'alarm.raised';

        $ok = $service->deliver($webhook, $event, [
            'peer_id' => '123456789',
            'message' => 'Test notification from the RustDesk API console',
            'ip' => request()->ip(),
        ]);

        return back()->with(
            $ok ? 'status' : 'error',
            $ok
                ? "Test delivered to '{$webhook->name}' (HTTP {$webhook->last_status})."
                : "Test to '{$webhook->name}' failed ({$webhook->last_status}). Check the URL and try again."
        );
    }

    /**
     * Recent delivery history for a webhook (newest first).
     */
    public function deliveries(Webhook $webhook): View
    {
        $deliveries = $webhook->deliveries()
            ->orderByDesc('id')
            ->paginate(30);

        return view('admin.webhooks.deliveries', compact('webhook', 'deliveries'));
    }

    /**
     * Re-send a single recorded delivery now (manual retry).
     */
    public function resend(WebhookDelivery $delivery, WebhookService $service): RedirectResponse
    {
        $webhook = $delivery->webhook;
        if ($webhook === null) {
            return back()->with('error', 'The webhook for this delivery no longer exists.');
        }

        $ok = $service->attempt($webhook, $delivery);

        return back()->with(
            $ok ? 'status' : 'error',
            $ok ? 'Delivery resent successfully.' : "Resend failed ({$delivery->status_code})."
        );
    }

    public function destroy(Webhook $webhook): RedirectResponse
    {
        $webhook->delete();

        return back()->with('status', 'Webhook deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateWebhook(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(array_keys(Webhook::TYPES))],
            'url' => ['required', 'url:http,https', 'max:2048'],
            'secret' => ['nullable', 'string', 'max:255'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(array_keys(Webhook::EVENTS))],
        ]);
    }
}
