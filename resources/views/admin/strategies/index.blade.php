@extends('layouts.admin')
@section('title', 'Strategies')

@section('content')
    @include('admin.partials.flash')
    <div class="rd-breadcrumb">Control / Strategies</div>

    <div class="rd-card">
        <div class="rd-card__header">
            <h3 class="rd-card__title">Strategies</h3>
            <a href="{{ route('admin.strategies.create') }}" class="rd-btn rd-btn--primary"><i class="ri-add-line"></i> New strategy</a>
        </div>
        <div class="rd-card__body" style="padding:0;">
            <table class="rd-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Options</th>
                        <th>Assignments</th>
                        <th>Note</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($strategies as $strategy)
                    <tr>
                        <td style="color:var(--rd-text-bright);font-weight:600;">
                            {{ $strategy->name }}
                            @if ($strategy->is_default)<span class="rd-badge rd-badge--online" style="margin-left:6px;"><i class="ri-star-fill"></i> Default</span>@endif
                        </td>
                        <td>
                            <span class="rd-badge rd-badge--{{ $strategy->enabled ? 'online' : 'offline' }}">
                                <span class="dot"></span>{{ $strategy->enabled ? 'Enabled' : 'Disabled' }}
                            </span>
                        </td>
                        <td class="rd-muted">{{ count($strategy->options ?? []) }}</td>
                        <td class="rd-muted">{{ $strategy->assignments_count }}</td>
                        <td class="rd-muted">{{ $strategy->note ?: '—' }}</td>
                        <td style="text-align:right;">
                            <div class="rd-row" style="justify-content:flex-end;">
                                <form method="POST" action="{{ route('admin.strategies.default', $strategy) }}" class="m-0">
                                    @csrf
                                    <button type="submit" class="rd-btn rd-btn--ghost" title="{{ $strategy->is_default ? 'Unset as default' : 'Set as default (fallback for unassigned devices)' }}">
                                        <i class="{{ $strategy->is_default ? 'ri-star-fill' : 'ri-star-line' }}"></i>
                                    </button>
                                </form>
                                <a href="{{ route('admin.strategies.edit', $strategy) }}" class="rd-btn rd-btn--ghost"><i class="ri-pencil-line"></i> Edit</a>
                                <form method="POST" action="{{ route('admin.strategies.destroy', $strategy) }}" class="m-0">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="rd-btn rd-btn--danger" data-confirm="Delete strategy '{{ $strategy->name }}'?"><i class="ri-delete-bin-line"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="rd-muted" style="text-align:center;padding:28px;">No strategies yet.</td></tr>
                @endforelse
                </tbody>
            </table>
            @include('admin.partials.pagination', ['paginator' => $strategies])
        </div>
    </div>
@endsection
