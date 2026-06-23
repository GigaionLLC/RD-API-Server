@extends('layouts.admin')
@section('title', 'API Keys')

@section('content')
    @include('admin.partials.flash')
    <div class="rd-breadcrumb">Management / API Keys</div>

    @if (session('new_api_key'))
        <div class="rd-card" style="margin-bottom:18px;border:1px solid var(--rd-primary);">
            <div class="rd-card__body">
                <strong style="color:var(--rd-text-bright);"><i class="ri-key-2-line"></i> Your new API key</strong>
                <p class="rd-help" style="margin:6px 0;">Copy it now — it is shown only once.</p>
                <div style="display:flex;gap:8px;align-items:center;">
                    <input class="rd-input" id="newKey" value="{{ session('new_api_key') }}" readonly style="font-family:monospace;">
                    <button type="button" class="rd-btn rd-btn--ghost" onclick="navigator.clipboard.writeText(document.getElementById('newKey').value);RD.toast('Copied','success');"><i class="ri-file-copy-line"></i> Copy</button>
                </div>
            </div>
        </div>
    @endif

    <div class="rd-grid rd-grid--2" style="align-items:start;">
        <div class="rd-card">
            <div class="rd-card__header"><h3 class="rd-card__title">Create API key</h3></div>
            <div class="rd-card__body">
                <form method="POST" action="{{ route('admin.api-keys.store') }}">
                    @csrf
                    <div class="rd-field">
                        <label class="rd-label" for="name">Name</label>
                        <input class="rd-input" id="name" name="name" placeholder="e.g. CI automation" required>
                        @error('name')<span class="rd-help rd-help--error">{{ $message }}</span>@enderror
                    </div>
                    <div class="rd-field">
                        <label class="rd-label">Scopes</label>
                        <div style="display:flex;flex-direction:column;gap:6px;">
                            @foreach ($scopeList as $scope => $label)
                                <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
                                    <input type="checkbox" name="scopes[]" value="{{ $scope }}"> {{ $label }}
                                    <code style="font-size:11px;color:var(--rd-text-muted);">{{ $scope }}</code>
                                </label>
                            @endforeach
                        </div>
                        @error('scopes')<span class="rd-help rd-help--error">{{ $message }}</span>@enderror
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="expires_at">Expires (optional)</label>
                        <input class="rd-input" id="expires_at" name="expires_at" type="date">
                    </div>
                    <button type="submit" class="rd-btn rd-btn--primary"><i class="ri-add-line"></i> Create key</button>
                </form>
            </div>
        </div>

        <div class="rd-card">
            <div class="rd-card__header"><h3 class="rd-card__title">Using the API</h3></div>
            <div class="rd-card__body">
                <p class="rd-help" style="margin-top:0;">Send the key as a bearer token (or <code>X-API-Key</code>) to <code>/api/v1</code>:</p>
                <pre style="background:var(--rd-surface-2);border:1px solid var(--rd-border);border-radius:8px;padding:11px;overflow:auto;font-size:12px;color:var(--rd-text);">curl -H "Authorization: Bearer rdk_xxx" \
  {{ url('/api/v1/devices') }}</pre>
                <p class="rd-help">Endpoints: <code>GET /api/v1/devices</code>, <code>/users</code>, <code>/strategies</code>, <code>/audit/connections</code>, <code>/address-books</code> (+ <code>/{id}/peers</code> read &amp; write). Each requires the matching scope.</p>
            </div>
        </div>
    </div>

    <div class="rd-card" style="margin-top:18px;">
        <div class="rd-card__header"><h3 class="rd-card__title">Existing keys</h3></div>
        <div class="rd-card__body" style="padding:0;">
            <table class="rd-table">
                <thead><tr><th>Name</th><th>Prefix</th><th>Scopes</th><th>Owner</th><th>Last used</th><th>Expires</th><th style="text-align:right;">Action</th></tr></thead>
                <tbody>
                @forelse ($keys as $key)
                    <tr>
                        <td style="color:var(--rd-text-bright);font-weight:600;">{{ $key->name }}</td>
                        <td class="rd-muted"><code>{{ $key->prefix }}…</code></td>
                        <td>@foreach ($key->scopes as $s)<span class="rd-badge rd-badge--muted" style="margin:0 3px 3px 0;">{{ $s }}</span>@endforeach</td>
                        <td class="rd-muted">{{ $key->user->username ?? '—' }}</td>
                        <td class="rd-muted">{{ $key->last_used_at?->diffForHumans() ?? 'never' }}</td>
                        <td class="rd-muted">{{ $key->expires_at?->toDateString() ?? '—' }}</td>
                        <td style="text-align:right;">
                            <form method="POST" action="{{ route('admin.api-keys.destroy', $key) }}" class="m-0">
                                @csrf @method('DELETE')
                                <button type="submit" class="rd-btn rd-btn--danger" data-confirm="Revoke '{{ $key->name }}'? Clients using it will stop working."><i class="ri-delete-bin-line"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="rd-muted" style="text-align:center;padding:24px;">No API keys yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
