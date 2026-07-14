@extends('layouts.admin')
@section('title', 'Alarms')

@php
    $canEdit = auth()->user()->hasPermission('alarms.edit');
@endphp

@section('content')
    @include('admin.partials.flash')

    <header class="rd-page-header">
        <div class="rd-page-header__copy">
            <p class="rd-page-header__eyebrow">Activity &amp; Security</p>
            <h1 class="rd-page-header__title">Alarms</h1>
            <p class="rd-page-header__description">Review security and operational alerts reported by managed devices.</p>
        </div>
    </header>

    <div class="rd-card rd-card--flush">
        <div class="rd-toolbar">
            <form method="GET" action="{{ route('admin.alarms.index') }}" class="rd-toolbar__group">
                <label class="visually-hidden" for="alarm-type">Alarm type</label>
                <select class="rd-select rd-toolbar__control" id="alarm-type" name="type" onchange="this.form.submit()">
                    <option value="">All types</option>
                    @foreach ($types as $t)
                        <option value="{{ $t }}" @selected($type === $t)>{{ $t }}</option>
                    @endforeach
                </select>
                <button class="rd-btn rd-btn--ghost" type="submit"><i class="ri-filter-3-line" aria-hidden="true"></i> Filter</button>
                @if (filled($type))
                    <a class="rd-btn rd-btn--ghost" href="{{ route('admin.alarms.index') }}">Reset</a>
                @endif
            </form>
        </div>
        <div class="rd-table-wrap" role="region" aria-label="Alarms" tabindex="0">
            <table class="rd-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Device</th>
                        <th>Peer</th>
                        <th>Type</th>
                        <th>Message</th>
                        <th>IP</th>
                        <th>Emailed</th>
                        @if ($canEdit)
                            <th class="rd-table__actions">Actions</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                @forelse ($alarms as $alarm)
                    <tr>
                        <td class="rd-muted rd-mono">{{ $alarm->created_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                        <td class="rd-muted">{{ $alarm->device?->hostname ?: $alarm->device?->alias ?: $alarm->device?->rustdesk_id ?: '—' }}</td>
                        <td><span class="rd-table__primary rd-mono">{{ $alarm->peer_id }}</span></td>
                        <td><span class="rd-badge rd-badge--muted">{{ $alarm->type }}</span></td>
                        <td class="rd-muted">{{ $alarm->message }}</td>
                        <td class="rd-muted rd-mono">{{ $alarm->ip ?: '—' }}</td>
                        <td>
                            <span class="rd-badge rd-badge--{{ $alarm->emailed ? 'online' : 'muted' }}">
                                <span class="dot"></span>{{ $alarm->emailed ? 'Yes' : 'No' }}
                            </span>
                        </td>
                        @if ($canEdit)
                            <td class="rd-table__actions">
                                <div class="rd-actions rd-actions--end">
                                    <form method="POST" action="{{ route('admin.alarms.destroy', $alarm) }}" class="m-0">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="rd-btn rd-btn--danger" data-confirm="Delete this alarm?" aria-label="Delete alarm" title="Delete alarm"><i class="ri-delete-bin-line" aria-hidden="true"></i></button>
                                    </form>
                                </div>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $canEdit ? 8 : 7 }}">
                            <div class="rd-empty">
                                <i class="rd-empty__icon ri-alarm-warning-line" aria-hidden="true"></i>
                                <p class="rd-empty__title">No alarms</p>
                                <p class="rd-empty__body">New device alerts will appear here.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('admin.partials.pagination', ['paginator' => $alarms])
    </div>
@endsection
