@extends('layouts.admin')
@section('title', 'Connection Logs')

@section('content')
    <header class="rd-page-header">
        <div class="rd-page-header__copy">
            <p class="rd-page-header__eyebrow">Activity &amp; Security</p>
            <h1 class="rd-page-header__title">Connection Logs</h1>
            <p class="rd-page-header__description">Trace remote connection starts, closures, authentication, and controller attribution.</p>
        </div>
    </header>

    <div class="rd-card rd-card--flush">
        <div class="rd-toolbar">
            <form method="GET" action="{{ route('admin.audit.connections') }}" class="rd-toolbar__group">
                <label class="visually-hidden" for="connection-search">Search connection logs</label>
                <input class="rd-input rd-toolbar__search" id="connection-search" type="search" name="q" value="{{ $q }}" placeholder="Search peer / name / ip">
                <label class="visually-hidden" for="connection-action">Connection action</label>
                <select class="rd-select rd-toolbar__control" id="connection-action" name="action" onchange="this.form.submit()">
                    <option value="">All actions</option>
                    <option value="new"   @selected($action === 'new')>New</option>
                    <option value="close" @selected($action === 'close')>Close</option>
                </select>
                <button class="rd-btn rd-btn--ghost" type="submit"><i class="ri-search-line" aria-hidden="true"></i> Search</button>
                <a class="rd-btn rd-btn--ghost" href="{{ route('admin.audit.connections.export', request()->query()) }}"><i class="ri-download-2-line" aria-hidden="true"></i> Export CSV</a>
                @if (filled($q) || filled($action))
                    <a class="rd-btn rd-btn--ghost" href="{{ route('admin.audit.connections') }}">Reset</a>
                @endif
            </form>
        </div>
        <div class="rd-table-wrap" role="region" aria-label="Connection logs" tabindex="0">
            <table class="rd-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Action</th>
                        <th>Peer</th>
                        <th>From</th>
                        <th>Auth</th>
                        <th>IP</th>
                        <th>Session</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td class="rd-muted rd-mono">{{ $log->created_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                        <td>
                            <span class="rd-badge rd-badge--{{ $log->action === 'new' ? 'online' : 'muted' }}">{{ ucfirst($log->action) }}</span>
                        </td>
                        <td><span class="rd-table__primary rd-mono">{{ $log->peer_id }}</span></td>
                        <td class="rd-muted">
                            {{ $log->from_name ?: $log->from_peer ?: '—' }}
                            @if ($log->conn_audit_ref)
                                <i class="ri-user-shared-line" role="img" title="Controller-attributed session (ref {{ $log->conn_audit_ref }})" aria-label="Controller-attributed session"></i>
                            @endif
                        </td>
                        <td class="rd-muted">
                            @if ($log->primaryAuthLabel())
                                <span class="rd-badge rd-badge--muted">{{ $log->primaryAuthLabel() }}</span>
                            @endif
                            @if ($log->twoFactorLabel())
                                <span class="rd-badge rd-badge--online" title="Second factor">{{ $log->twoFactorLabel() }}</span>
                            @endif
                            @unless ($log->primaryAuthLabel() || $log->twoFactorLabel())—@endunless
                        </td>
                        <td class="rd-muted rd-mono">{{ $log->ip ?: '—' }}</td>
                        <td class="rd-muted rd-mono">{{ $log->session_id ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="rd-empty"><i class="rd-empty__icon ri-link-unlink" aria-hidden="true"></i><p class="rd-empty__title">No connection logs</p><p class="rd-empty__body">Connection activity will appear here as devices report it.</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('admin.partials.pagination', ['paginator' => $logs])
    </div>
@endsection
