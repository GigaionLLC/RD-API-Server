@extends('layouts.admin')
@section('title', 'Recordings')

@php
    $canEdit = auth()->user()->hasPermission('recordings.edit');
@endphp

@section('content')
    @include('admin.partials.flash')

    <header class="rd-page-header">
        <div class="rd-page-header__copy">
            <p class="rd-page-header__eyebrow">Activity &amp; Security</p>
            <h1 class="rd-page-header__title">Recordings</h1>
            <p class="rd-page-header__description">Find, download, and manage remote-session recordings.</p>
        </div>
    </header>

    <div class="rd-card rd-card--flush">
        <div class="rd-toolbar">
            <form method="GET" action="{{ route('admin.recordings.index') }}" class="rd-toolbar__group">
                <label class="visually-hidden" for="recording-search">Search recordings</label>
                <input class="rd-input rd-toolbar__search" id="recording-search" type="search" name="q" value="{{ $q }}" placeholder="Search peer / file">
                <label class="visually-hidden" for="recording-status">Recording status</label>
                <input class="rd-input rd-toolbar__control" id="recording-status" type="search" name="status" value="{{ $status }}" placeholder="Status">
                <button class="rd-btn rd-btn--ghost" type="submit"><i class="ri-search-line" aria-hidden="true"></i> Search</button>
                @if (filled($q) || filled($status))
                    <a class="rd-btn rd-btn--ghost" href="{{ route('admin.recordings.index') }}">Reset</a>
                @endif
            </form>
        </div>
        <div class="rd-table-wrap" role="region" aria-label="Recordings" tabindex="0">
            <table class="rd-table">
                <thead>
                    <tr>
                        <th>Peer</th>
                        <th>Filename</th>
                        <th>Size</th>
                        <th>Status</th>
                        <th>Started</th>
                        <th>Finished</th>
                        <th class="rd-table__actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($recordings as $recording)
                    @php
                        $bytes = (int) $recording->size;
                        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
                        $i = 0;
                        $human = $bytes;
                        while ($human >= 1024 && $i < count($units) - 1) {
                            $human /= 1024;
                            $i++;
                        }
                        $sizeLabel = $i === 0 ? $bytes.' B' : number_format($human, 1).' '.$units[$i];
                    @endphp
                    <tr>
                        <td><span class="rd-table__primary rd-mono">{{ $recording->peer_id }}</span></td>
                        <td class="rd-muted">{{ $recording->filename }}</td>
                        <td class="rd-muted">{{ $sizeLabel }}</td>
                        <td><span class="rd-badge rd-badge--muted">{{ $recording->status }}</span></td>
                        <td class="rd-muted rd-mono">{{ $recording->started_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                        <td class="rd-muted rd-mono">{{ $recording->finished_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                        <td class="rd-table__actions">
                            <div class="rd-actions rd-actions--end rd-actions--wrap">
                                <a href="{{ route('admin.recordings.download', $recording) }}" class="rd-btn rd-btn--ghost"><i class="ri-download-2-line" aria-hidden="true"></i> Download</a>
                                @if ($canEdit)
                                    <form method="POST" action="{{ route('admin.recordings.destroy', $recording) }}" class="m-0">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="rd-btn rd-btn--danger" data-confirm="Delete this recording? The file will be removed." aria-label="Delete recording" title="Delete recording"><i class="ri-delete-bin-line" aria-hidden="true"></i></button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="rd-empty"><i class="rd-empty__icon ri-video-line" aria-hidden="true"></i><p class="rd-empty__title">No recordings</p><p class="rd-empty__body">Session recordings will appear here when available.</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('admin.partials.pagination', ['paginator' => $recordings])
    </div>
@endsection
