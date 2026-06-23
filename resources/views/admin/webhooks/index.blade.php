@extends('layouts.admin')
@section('title', 'Webhooks')

@section('content')
    @include('admin.partials.flash')
    <div class="rd-breadcrumb">System / Webhooks</div>

    <div class="rd-grid rd-grid--2" style="align-items:start;">
        <div class="rd-card">
            <div class="rd-card__header"><h3 class="rd-card__title">Create webhook</h3></div>
            <div class="rd-card__body">
                <form method="POST" action="{{ route('admin.webhooks.store') }}">
                    @csrf
                    <div class="rd-field">
                        <label class="rd-label" for="name">Name</label>
                        <input class="rd-input" id="name" name="name" placeholder="e.g. Ops Slack channel" required>
                        @error('name')<span class="rd-help rd-help--error">{{ $message }}</span>@enderror
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="type">Type</label>
                        <select class="rd-input" id="type" name="type">
                            @foreach ($typeList as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="url">URL</label>
                        <input class="rd-input" id="url" name="url" placeholder="https://hooks.slack.com/services/…" required>
                        @error('url')<span class="rd-help rd-help--error">{{ $message }}</span>@enderror
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="secret">Secret <span class="rd-muted">(optional)</span></label>
                        <input class="rd-input" id="secret" name="secret" placeholder="HMAC signing secret — or, for Telegram, the chat id">
                        <span class="rd-help">Generic: signs the body as <code>X-RustDesk-Signature: sha256=…</code>. Telegram: the target chat id. Slack: leave blank.</span>
                    </div>
                    <div class="rd-field">
                        <label class="rd-label">Events</label>
                        <div style="display:flex;flex-direction:column;gap:6px;">
                            @foreach ($eventList as $event => $label)
                                <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
                                    <input type="checkbox" name="events[]" value="{{ $event }}"> {{ $label }}
                                    <code style="font-size:11px;color:var(--rd-text-muted);">{{ $event }}</code>
                                </label>
                            @endforeach
                        </div>
                        @error('events')<span class="rd-help rd-help--error">{{ $message }}</span>@enderror
                    </div>
                    <div class="rd-field">
                        <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
                            <input type="checkbox" name="enabled" value="1" checked> Enabled
                        </label>
                    </div>
                    <button type="submit" class="rd-btn rd-btn--primary"><i class="ri-add-line"></i> Create webhook</button>
                </form>
            </div>
        </div>

        <div class="rd-card">
            <div class="rd-card__header"><h3 class="rd-card__title">How webhooks work</h3></div>
            <div class="rd-card__body">
                <p class="rd-help" style="margin-top:0;">Server events are delivered best-effort the moment they happen — no queue worker required.</p>
                <ul class="rd-help" style="padding-left:18px;line-height:1.8;">
                    <li><strong>Slack</strong> — paste an incoming-webhook URL; a one-line message is posted.</li>
                    <li><strong>Telegram</strong> — URL <code>https://api.telegram.org/bot&lt;token&gt;/sendMessage</code>, secret = chat id.</li>
                    <li><strong>Generic</strong> — receives <code>{ event, summary, timestamp, data }</code>; set a secret to verify the <code>X-RustDesk-Signature</code> HMAC.</li>
                </ul>
                <p class="rd-help">Use <strong>Test</strong> on any row to send a sample payload and confirm the endpoint responds.</p>
            </div>
        </div>
    </div>

    <div class="rd-card" style="margin-top:18px;">
        <div class="rd-card__header"><h3 class="rd-card__title">Configured webhooks</h3></div>
        <div class="rd-card__body" style="padding:0;">
            <table class="rd-table">
                <thead><tr><th>Name</th><th>Type</th><th>Events</th><th>Status</th><th>Last fired</th><th>State</th><th style="text-align:right;">Actions</th></tr></thead>
                <tbody>
                @forelse ($webhooks as $hook)
                    <tr>
                        <td style="color:var(--rd-text-bright);font-weight:600;">{{ $hook->name }}<div class="rd-muted" style="font-weight:400;font-size:11px;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $hook->url }}</div></td>
                        <td class="rd-muted">{{ $typeList[$hook->type] ?? $hook->type }}</td>
                        <td>@foreach ($hook->events as $e)<span class="rd-badge rd-badge--muted" style="margin:0 3px 3px 0;">{{ $e }}</span>@endforeach</td>
                        <td class="rd-muted">
                            @if ($hook->last_status)
                                <span class="rd-badge {{ str_starts_with((string) $hook->last_status, '2') ? 'rd-badge--online' : 'rd-badge--offline' }}">{{ $hook->last_status }}</span>
                                @if ($hook->failure_count > 0)<span class="rd-muted" style="font-size:11px;"> ×{{ $hook->failure_count }} fail</span>@endif
                            @else
                                —
                            @endif
                        </td>
                        <td class="rd-muted">{{ $hook->last_triggered_at?->diffForHumans() ?? 'never' }}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.webhooks.toggle', $hook) }}" class="m-0">
                                @csrf
                                <button type="submit" class="rd-badge {{ $hook->enabled ? 'rd-badge--online' : 'rd-badge--muted' }}" style="cursor:pointer;border:0;">{{ $hook->enabled ? 'enabled' : 'disabled' }}</button>
                            </form>
                        </td>
                        <td style="text-align:right;white-space:nowrap;">
                            <form method="POST" action="{{ route('admin.webhooks.test', $hook) }}" class="m-0" style="display:inline;">
                                @csrf
                                <button type="submit" class="rd-btn rd-btn--ghost" title="Send a test event"><i class="ri-send-plane-line"></i></button>
                            </form>
                            <form method="POST" action="{{ route('admin.webhooks.destroy', $hook) }}" class="m-0" style="display:inline;">
                                @csrf @method('DELETE')
                                <button type="submit" class="rd-btn rd-btn--danger" data-confirm="Delete webhook '{{ $hook->name }}'?"><i class="ri-delete-bin-line"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="rd-muted" style="text-align:center;padding:24px;">No webhooks yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
