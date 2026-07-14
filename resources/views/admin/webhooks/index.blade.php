@extends('layouts.admin')
@section('title', 'Webhooks')

@section('content')
    @include('admin.partials.flash')

    <header class="rd-page-header">
        <div class="rd-page-header__copy">
            <p class="rd-page-header__eyebrow">Integrations</p>
            <h1 class="rd-page-header__title">Webhooks</h1>
            <p class="rd-page-header__description">Send operational events to Slack, Telegram, or a generic HTTP endpoint.</p>
        </div>
    </header>

    <div class="rd-stack rd-stack--lg">
    <div class="rd-grid rd-grid--2 rd-align-start">
        <div class="rd-card">
            <div class="rd-card__header"><h3 class="rd-card__title">Create webhook</h3></div>
            <div class="rd-card__body">
                <form method="POST" action="{{ route('admin.webhooks.store') }}">
                    @csrf
                    <div class="rd-form-grid rd-form-grid--2">
                    <div class="rd-field">
                        <label class="rd-label" for="name">Name</label>
                        <input class="rd-input" id="name" name="name" placeholder="e.g. Ops Slack channel" required>
                        @error('name')<span class="rd-help rd-help--error">{{ $message }}</span>@enderror
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="type">Type</label>
                        <select class="rd-select" id="type" name="type">
                            @foreach ($typeList as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="rd-field rd-form-grid__full">
                        <label class="rd-label" for="url">URL</label>
                        <input class="rd-input" id="url" name="url" placeholder="https://hooks.slack.com/services/…" required>
                        @error('url')<span class="rd-help rd-help--error">{{ $message }}</span>@enderror
                    </div>
                    <div class="rd-field rd-form-grid__full">
                        <label class="rd-label" for="secret">Secret <span class="rd-muted">(optional)</span></label>
                        <input class="rd-input" id="secret" name="secret" placeholder="HMAC signing secret — or, for Telegram, the chat id">
                        <span class="rd-help">Generic: signs the body as <code>X-RustDesk-Signature: sha256=…</code>. Telegram: the target chat id. Slack: leave blank.</span>
                    </div>
                    <div class="rd-field rd-form-grid__full">
                        <label class="rd-label">Events</label>
                        <div class="rd-stack rd-stack--sm">
                            @foreach ($eventList as $event => $label)
                                <label class="rd-check">
                                    <input type="checkbox" name="events[]" value="{{ $event }}"> {{ $label }}
                                    <code class="rd-code">{{ $event }}</code>
                                </label>
                            @endforeach
                        </div>
                        @error('events')<span class="rd-help rd-help--error">{{ $message }}</span>@enderror
                    </div>
                    <div class="rd-field rd-form-grid__full">
                        <label class="rd-check">
                            <input type="checkbox" name="enabled" value="1" checked> Enabled
                        </label>
                    </div>
                    <div class="rd-form-grid__full">
                        <button type="submit" class="rd-btn rd-btn--primary"><i class="ri-add-line" aria-hidden="true"></i> Create webhook</button>
                    </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="rd-card">
            <div class="rd-card__header"><h3 class="rd-card__title">How webhooks work</h3></div>
            <div class="rd-card__body">
                <p class="rd-help">Server events are delivered best-effort the moment they happen — no queue worker required.</p>
                <ul class="rd-help rd-stack rd-stack--sm">
                    <li><strong>Slack</strong> — paste an incoming-webhook URL; a one-line message is posted.</li>
                    <li><strong>Telegram</strong> — URL <code>https://api.telegram.org/bot&lt;token&gt;/sendMessage</code>, secret = chat id.</li>
                    <li><strong>Generic</strong> — receives <code>{ event, summary, timestamp, data }</code>; set a secret to verify the <code>X-RustDesk-Signature</code> HMAC.</li>
                </ul>
                <p class="rd-help">Use <strong>Test</strong> on any row to send a sample payload and confirm the endpoint responds.</p>
            </div>
        </div>
    </div>

    <div class="rd-card rd-card--flush">
        <div class="rd-card__header"><h3 class="rd-card__title">Configured webhooks</h3></div>
        <div class="rd-table-wrap" role="region" aria-label="Configured webhooks" tabindex="0">
            <table class="rd-table">
                <thead><tr><th>Name</th><th>Type</th><th>Events</th><th>Status</th><th>Last fired</th><th>State</th><th class="rd-table__actions">Actions</th></tr></thead>
                <tbody>
                @forelse ($webhooks as $hook)
                    <tr>
                        <td><span class="rd-table__primary">{{ $hook->name }}</span><div class="rd-table__meta rd-mono" title="{{ $hook->url }}">{{ $hook->url }}</div></td>
                        <td class="rd-muted">{{ $typeList[$hook->type] ?? $hook->type }}</td>
                        <td><div class="rd-actions rd-actions--wrap">@foreach ($hook->events as $e)<span class="rd-badge rd-badge--muted">{{ $e }}</span>@endforeach</div></td>
                        <td class="rd-muted">
                            @if ($hook->last_status)
                                <span class="rd-badge {{ str_starts_with((string) $hook->last_status, '2') ? 'rd-badge--online' : 'rd-badge--offline' }}">{{ $hook->last_status }}</span>
                                @if ($hook->failure_count > 0)<span class="rd-table__meta"> ×{{ $hook->failure_count }} fail</span>@endif
                            @else
                                —
                            @endif
                        </td>
                        <td class="rd-muted">{{ $hook->last_triggered_at?->diffForHumans() ?? 'never' }}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.webhooks.toggle', $hook) }}" class="m-0">
                                @csrf
                                <button type="submit" class="rd-btn rd-btn--ghost" aria-pressed="{{ $hook->enabled ? 'true' : 'false' }}" aria-label="{{ $hook->enabled ? 'Disable '.$hook->name : 'Enable '.$hook->name }} webhook"><span class="rd-badge {{ $hook->enabled ? 'rd-badge--online' : 'rd-badge--muted' }}">{{ $hook->enabled ? 'enabled' : 'disabled' }}</span></button>
                            </form>
                        </td>
                        <td class="rd-table__actions">
                            <div class="rd-actions rd-actions--end">
                            <a href="{{ route('admin.webhooks.deliveries', $hook) }}" class="rd-icon-btn" title="Delivery history" aria-label="View {{ $hook->name }} delivery history"><i class="ri-history-line" aria-hidden="true"></i></a>
                            <form method="POST" action="{{ route('admin.webhooks.test', $hook) }}" class="m-0">
                                @csrf
                                <button type="submit" class="rd-icon-btn" title="Send a test event" aria-label="Send a test event to {{ $hook->name }}"><i class="ri-send-plane-line" aria-hidden="true"></i></button>
                            </form>
                            <form method="POST" action="{{ route('admin.webhooks.destroy', $hook) }}" class="m-0">
                                @csrf @method('DELETE')
                                <button type="submit" class="rd-icon-btn rd-icon-btn--danger" data-confirm="Delete webhook '{{ $hook->name }}'?" title="Delete webhook" aria-label="Delete {{ $hook->name }} webhook"><i class="ri-delete-bin-line" aria-hidden="true"></i></button>
                            </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="rd-empty"><i class="rd-empty__icon ri-webhook-line" aria-hidden="true"></i><p class="rd-empty__title">No webhooks yet</p><p class="rd-empty__body">Create a webhook to send operational events elsewhere.</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    </div>
@endsection
