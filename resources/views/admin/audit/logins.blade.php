@extends('layouts.admin')
@section('title', 'Login Logs')

@section('content')
    <header class="rd-page-header">
        <div class="rd-page-header__copy">
            <p class="rd-page-header__eyebrow">Activity &amp; Security</p>
            <h1 class="rd-page-header__title">Login Logs</h1>
            <p class="rd-page-header__description">Audit sign-ins by user, client, device, platform, and source address.</p>
        </div>
    </header>

    <div class="rd-card rd-card--flush">
        <div class="rd-toolbar">
            <form method="GET" action="{{ route('admin.audit.logins') }}" class="rd-toolbar__group">
                <label class="visually-hidden" for="login-log-search">Search login logs</label>
                <input class="rd-input rd-toolbar__search" id="login-log-search" type="search" name="q" value="{{ $q }}" placeholder="Search client / device / ip">
                <button class="rd-btn rd-btn--ghost" type="submit"><i class="ri-search-line" aria-hidden="true"></i> Search</button>
                <a class="rd-btn rd-btn--ghost" href="{{ route('admin.audit.logins.export', request()->query()) }}"><i class="ri-download-2-line" aria-hidden="true"></i> Export CSV</a>
                @if (filled($q))
                    <a class="rd-btn rd-btn--ghost" href="{{ route('admin.audit.logins') }}">Reset</a>
                @endif
            </form>
        </div>
        <div class="rd-table-wrap" role="region" aria-label="Login logs" tabindex="0">
            <table class="rd-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>User</th>
                        <th>Type</th>
                        <th>Client</th>
                        <th>Device</th>
                        <th>Platform</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td class="rd-muted rd-mono">{{ $log->created_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                        <td><span class="rd-table__primary">{{ $log->user->username ?? '—' }}</span></td>
                        <td><span class="rd-badge rd-badge--muted">{{ $log->type }}</span></td>
                        <td class="rd-muted">{{ $log->client ?: '—' }}</td>
                        <td class="rd-muted rd-mono">{{ $log->device_id ?: '—' }}</td>
                        <td class="rd-muted">{{ $log->platform ?: '—' }}</td>
                        <td class="rd-muted rd-mono">{{ $log->ip ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="rd-empty"><i class="rd-empty__icon ri-login-box-line" aria-hidden="true"></i><p class="rd-empty__title">No login logs</p><p class="rd-empty__body">User sign-ins will appear here.</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('admin.partials.pagination', ['paginator' => $logs])
    </div>
@endsection
