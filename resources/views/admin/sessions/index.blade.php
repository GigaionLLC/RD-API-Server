@extends('layouts.admin')
@section('title', 'Live Sessions')

@php
    $connTypes = [0 => 'Remote Desktop', 1 => 'File Transfer', 2 => 'Port Transfer', 3 => 'View Camera', 4 => 'Terminal'];
@endphp

@section('content')
    @include('admin.partials.flash')

    <header class="rd-page-header">
        <div class="rd-page-header__copy">
            <p class="rd-page-header__eyebrow">Fleet</p>
            <h1 class="rd-page-header__title">Live Sessions</h1>
            <p class="rd-page-header__description">{{ $sessions->total() }} open {{ $sessions->total() === 1 ? 'connection' : 'connections' }} currently reported by the audit stream.</p>
        </div>
    </header>

    <div class="rd-card rd-card--flush">
        <div class="rd-table-wrap" role="region" aria-label="Live sessions" tabindex="0">
            <table class="rd-table">
                <thead>
                    <tr><th>Device</th><th>Controller</th><th>IP</th><th>Type</th><th>Started</th><th class="rd-table__actions">Action</th></tr>
                </thead>
                <tbody>
                @forelse ($sessions as $s)
                    <tr>
                        <td><span class="rd-table__primary">{{ $hostnames[$s->peer_id] ?? $s->peer_id }}</span></td>
                        <td>{{ $s->from_name ?: ($s->from_peer ?: '—') }}</td>
                        <td class="rd-muted rd-mono">{{ $s->ip ?: '—' }}</td>
                        <td>{{ $connTypes[$s->type] ?? ('Type '.$s->type) }}</td>
                        <td class="rd-muted">{{ $s->created_at?->diffForHumans() ?? '—' }}</td>
                        <td class="rd-table__actions">
                            @if (auth()->user()->hasPermission('sessions.edit'))
                                <form method="POST" action="{{ route('admin.sessions.disconnect') }}" class="m-0">
                                    @csrf
                                    <input type="hidden" name="peer_id" value="{{ $s->peer_id }}">
                                    <input type="hidden" name="conn_id" value="{{ $s->conn_id }}">
                                    <button type="submit" class="rd-btn rd-btn--danger"
                                            data-confirm="Force-disconnect this session? It will drop on the device's next heartbeat.">
                                        <i class="ri-shut-down-line" aria-hidden="true"></i> Disconnect
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6"><div class="rd-empty"><i class="rd-empty__icon ri-remote-control-line" aria-hidden="true"></i><p class="rd-empty__title">No active sessions</p><p class="rd-empty__body">Open connections appear here from the audit stream.</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $sessions->links('admin.partials.pagination') }}
    </div>
@endsection
