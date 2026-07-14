@extends('layouts.admin')
@section('title', 'Console Audit')

@section('content')
    <header class="rd-page-header">
        <div class="rd-page-header__copy">
            <p class="rd-page-header__eyebrow">Activity &amp; Security</p>
            <h1 class="rd-page-header__title">Console Operations</h1>
            <p class="rd-page-header__description">Audit administrative requests by user, route, method, path, and source address.</p>
        </div>
    </header>

    <div class="rd-card rd-card--flush">
        <div class="rd-toolbar">
            <form method="GET" action="{{ route('admin.console-audit.index') }}" class="rd-toolbar__group">
                <label class="visually-hidden" for="console-audit-search">Search console operations</label>
                <input class="rd-input rd-toolbar__search" id="console-audit-search" type="search" name="q" value="{{ $q }}" placeholder="Search path / route / method / ip">
                <button class="rd-btn rd-btn--ghost" type="submit"><i class="ri-search-line" aria-hidden="true"></i> Search</button>
                @if (filled($q))
                    <a class="rd-btn rd-btn--ghost" href="{{ route('admin.console-audit.index') }}">Reset</a>
                @endif
            </form>
        </div>
        <div class="rd-table-wrap" role="region" aria-label="Console operations" tabindex="0">
            <table class="rd-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>User</th>
                        <th>Method</th>
                        <th>Route</th>
                        <th>Path</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td class="rd-muted rd-mono">{{ $log->created_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                        <td><span class="rd-table__primary">{{ $log->user?->username ?? '—' }}</span></td>
                        <td>
                            <span class="rd-badge rd-badge--muted">{{ $log->method }}</span>
                        </td>
                        <td class="rd-muted rd-mono">{{ $log->route_name ?: '—' }}</td>
                        <td class="rd-muted rd-mono">{{ $log->path }}</td>
                        <td class="rd-muted rd-mono">{{ $log->ip ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6"><div class="rd-empty"><i class="rd-empty__icon ri-terminal-box-line" aria-hidden="true"></i><p class="rd-empty__title">No console operations recorded</p><p class="rd-empty__body">Administrative requests will appear here.</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('admin.partials.pagination', ['paginator' => $logs])
    </div>
@endsection
