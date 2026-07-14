@extends('layouts.admin')
@section('title', 'File Transfers')

@section('content')
    <header class="rd-page-header">
        <div class="rd-page-header__copy">
            <p class="rd-page-header__eyebrow">Activity &amp; Security</p>
            <h1 class="rd-page-header__title">File Transfers</h1>
            <p class="rd-page-header__description">Inspect files and directories transferred during remote sessions.</p>
        </div>
    </header>

    <div class="rd-card rd-card--flush">
        <div class="rd-toolbar">
            <form method="GET" action="{{ route('admin.audit.files') }}" class="rd-toolbar__group">
                <label class="visually-hidden" for="file-log-search">Search file transfer logs</label>
                <input class="rd-input rd-toolbar__search" id="file-log-search" type="search" name="q" value="{{ $q }}" placeholder="Search peer / path / ip">
                <button class="rd-btn rd-btn--ghost" type="submit"><i class="ri-search-line" aria-hidden="true"></i> Search</button>
                <a class="rd-btn rd-btn--ghost" href="{{ route('admin.audit.files.export', request()->query()) }}"><i class="ri-download-2-line" aria-hidden="true"></i> Export CSV</a>
                @if (filled($q))
                    <a class="rd-btn rd-btn--ghost" href="{{ route('admin.audit.files') }}">Reset</a>
                @endif
            </form>
        </div>
        <div class="rd-table-wrap" role="region" aria-label="File transfer logs" tabindex="0">
            <table class="rd-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Kind</th>
                        <th>Peer</th>
                        <th>From</th>
                        <th>Path</th>
                        <th>Files</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td class="rd-muted rd-mono">{{ $log->created_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                        <td><span class="rd-badge rd-badge--muted">{{ $log->is_file ? 'File' : 'Dir' }}</span></td>
                        <td><span class="rd-table__primary rd-mono">{{ $log->peer_id }}</span></td>
                        <td class="rd-muted">{{ $log->from_name ?: $log->from_peer ?: '—' }}</td>
                        <td><span class="rd-table__meta rd-mono" title="{{ $log->path }}">{{ $log->path ?: '—' }}</span></td>
                        <td class="rd-muted">{{ $log->num }}</td>
                        <td class="rd-muted rd-mono">{{ $log->ip ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="rd-empty"><i class="rd-empty__icon ri-file-transfer-line" aria-hidden="true"></i><p class="rd-empty__title">No file transfer logs</p><p class="rd-empty__body">Transferred files and directories will appear here.</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('admin.partials.pagination', ['paginator' => $logs])
    </div>
@endsection
