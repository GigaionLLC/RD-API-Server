@extends('layouts.admin')
@section('title', 'API Keys')

@section('content')
    @include('admin.partials.flash')

    <header class="rd-page-header">
        <div class="rd-page-header__copy">
            <p class="rd-page-header__eyebrow">Integrations</p>
            <h1 class="rd-page-header__title">API Keys</h1>
            <p class="rd-page-header__description">Issue scoped credentials for trusted automation and external tools.</p>
        </div>
    </header>

    <div class="rd-stack rd-stack--lg">
    @if (session('new_api_key'))
        <div class="rd-callout rd-callout--success rd-actions rd-align-start">
            <i class="ri-key-2-line rd-success" aria-hidden="true"></i>
            <div class="rd-stack rd-stack--sm rd-grow">
                <strong>Your new API key</strong>
                <p class="rd-help">Copy it now — it is shown only once.</p>
                <div class="rd-actions rd-actions--wrap">
                    <input class="rd-input rd-mono rd-grow" id="newKey" value="{{ session('new_api_key') }}" readonly aria-label="New API key">
                    <button type="button" class="rd-btn rd-btn--ghost" onclick="navigator.clipboard.writeText(document.getElementById('newKey').value);RD.toast('Copied','success');"><i class="ri-file-copy-line" aria-hidden="true"></i> Copy</button>
                </div>
            </div>
        </div>
    @endif

    <div class="rd-grid rd-grid--2 rd-align-start">
        <div class="rd-card">
            <div class="rd-card__header"><h3 class="rd-card__title">Create API key</h3></div>
            <div class="rd-card__body">
                <form method="POST" action="{{ route('admin.api-keys.store') }}">
                    @csrf
                    <div class="rd-form-grid rd-form-grid--2">
                    <div class="rd-field rd-form-grid__full">
                        <label class="rd-label" for="name">Name</label>
                        <input class="rd-input" id="name" name="name" placeholder="e.g. CI automation" required>
                        @error('name')<span class="rd-help rd-help--error">{{ $message }}</span>@enderror
                    </div>
                    <div class="rd-field rd-form-grid__full">
                        <label class="rd-label">Scopes</label>
                        <div class="rd-stack rd-stack--sm">
                            @foreach ($scopeList as $scope => $label)
                                <label class="rd-check">
                                    <input type="checkbox" name="scopes[]" value="{{ $scope }}"> {{ $label }}
                                    <code class="rd-code">{{ $scope }}</code>
                                </label>
                            @endforeach
                        </div>
                        @error('scopes')<span class="rd-help rd-help--error">{{ $message }}</span>@enderror
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="allowed_ips">Allowed IPs <span class="rd-muted">(optional)</span></label>
                        <input class="rd-input" id="allowed_ips" name="allowed_ips" placeholder="e.g. 203.0.113.7, 198.51.100.10">
                        <span class="rd-help">Comma-separated. Leave blank to allow any source IP (exact match, no CIDR).</span>
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="expires_at">Expires (optional)</label>
                        <input class="rd-input" id="expires_at" name="expires_at" type="date">
                    </div>
                    <div class="rd-form-grid__full">
                        <button type="submit" class="rd-btn rd-btn--primary"><i class="ri-add-line" aria-hidden="true"></i> Create key</button>
                    </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="rd-card">
            <div class="rd-card__header"><h3 class="rd-card__title">Using the API</h3></div>
            <div class="rd-card__body">
                <p class="rd-help">Send the key as a bearer token (or <code>X-API-Key</code>) to <code>/api/v1</code>:</p>
                <pre class="rd-code-block">curl -H "Authorization: Bearer rdk_xxx" \
  {{ url('/api/v1/devices') }}</pre>
                <p class="rd-help">Endpoints: <code>GET /api/v1/devices</code>, <code>/users</code>, <code>/strategies</code>, <code>/audit/connections</code>, <code>/address-books</code> (+ <code>/{id}/peers</code> read &amp; write). Each requires the matching scope.</p>
            </div>
        </div>
    </div>

    <div class="rd-card rd-card--flush">
        <div class="rd-card__header"><h3 class="rd-card__title">Existing keys</h3></div>
        <div class="rd-table-wrap" role="region" aria-label="Existing API keys" tabindex="0">
            <table class="rd-table">
                <thead><tr><th>Name</th><th>Prefix</th><th>Scopes</th><th>Allowed IPs</th><th>Owner</th><th>Last used</th><th>Expires</th><th class="rd-table__actions">Actions</th></tr></thead>
                <tbody>
                @forelse ($keys as $key)
                    <tr>
                        <td><span class="rd-table__primary">{{ $key->name }}</span></td>
                        <td><code class="rd-code">{{ $key->prefix }}…</code></td>
                        <td><div class="rd-actions rd-actions--wrap">@foreach ($key->scopes as $s)<span class="rd-badge rd-badge--muted">{{ $s }}</span>@endforeach</div></td>
                        <td><span class="rd-table__meta rd-mono">{{ $key->allowed_ips ?: 'any' }}</span></td>
                        <td class="rd-muted">{{ $key->user->username ?? '—' }}</td>
                        <td class="rd-muted">{{ $key->last_used_at?->diffForHumans() ?? 'never' }}@if($key->last_used_ip)<div class="rd-table__meta rd-mono">{{ $key->last_used_ip }}</div>@endif</td>
                        <td class="rd-muted">{{ $key->expires_at?->toDateString() ?? '—' }}</td>
                        <td class="rd-table__actions">
                            <div class="rd-actions rd-actions--end">
                                <form method="POST" action="{{ route('admin.api-keys.rotate', $key) }}" class="m-0">
                                    @csrf
                                    <button type="submit" class="rd-icon-btn" data-confirm="Rotate '{{ $key->name }}'? The current secret stops working immediately." title="Rotate secret" aria-label="Rotate {{ $key->name }} secret"><i class="ri-refresh-line" aria-hidden="true"></i></button>
                                </form>
                                <form method="POST" action="{{ route('admin.api-keys.destroy', $key) }}" class="m-0">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="rd-icon-btn rd-icon-btn--danger" data-confirm="Revoke '{{ $key->name }}'? Clients using it will stop working." title="Revoke API key" aria-label="Revoke {{ $key->name }} API key"><i class="ri-delete-bin-line" aria-hidden="true"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8"><div class="rd-empty"><i class="rd-empty__icon ri-key-2-line" aria-hidden="true"></i><p class="rd-empty__title">No API keys yet</p><p class="rd-empty__body">Create a scoped key to connect trusted automation.</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    </div>
@endsection
