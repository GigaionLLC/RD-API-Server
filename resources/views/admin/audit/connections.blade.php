@extends('layouts.admin')
@section('title', 'Connection Logs')

@section('content')
    <div class="rd-breadcrumb">Audit / Connection Logs</div>

    <div class="rd-card">
        <div class="rd-card__header">
            <h3 class="rd-card__title">Connection Logs</h3>
            <form method="GET" action="{{ route('admin.audit.connections') }}" class="rd-row">
                <input class="rd-input" type="search" name="q" value="{{ $q }}" placeholder="Search peer / name / ip" style="width:220px;">
                <select class="rd-select" name="action" style="width:130px;" onchange="this.form.submit()">
                    <option value="">All actions</option>
                    <option value="new"   @selected($action === 'new')>New</option>
                    <option value="close" @selected($action === 'close')>Close</option>
                </select>
                <button class="rd-btn rd-btn--ghost" type="submit"><i class="ri-search-line"></i></button>
                <a class="rd-btn rd-btn--ghost" href="{{ route('admin.audit.connections.export', request()->query()) }}"><i class="ri-download-2-line"></i> Export CSV</a>
            </form>
        </div>
        <div class="rd-card__body" style="padding:0;">
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
                        <td class="rd-muted">{{ $log->created_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                        <td>
                            <span class="rd-badge rd-badge--{{ $log->action === 'new' ? 'online' : 'muted' }}">{{ ucfirst($log->action) }}</span>
                        </td>
                        <td style="color:var(--rd-text-bright);">{{ $log->peer_id }}</td>
                        <td class="rd-muted">
                            {{ $log->from_name ?: $log->from_peer ?: '—' }}
                            @if ($log->conn_audit_ref)
                                <i class="ri-user-shared-line" title="Controller-attributed session (ref {{ $log->conn_audit_ref }})" style="color:var(--rd-accent);"></i>
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
                        <td class="rd-muted">{{ $log->ip ?: '—' }}</td>
                        <td class="rd-muted">{{ $log->session_id ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="rd-muted" style="text-align:center;padding:28px;">No connection logs.</td></tr>
                @endforelse
                </tbody>
            </table>
            @include('admin.partials.pagination', ['paginator' => $logs])
        </div>
    </div>
@endsection
