@extends('layouts.admin')
@section('title', 'Strategies')
@php($canEdit = auth()->user()->hasPermission('strategies.edit'))

@section('content')
    @include('admin.partials.flash')

    <header class="rd-page-header">
        <div class="rd-page-header__copy">
            <p class="rd-page-header__eyebrow">Policies &amp; Rollout</p>
            <h1 class="rd-page-header__title">Strategies</h1>
            <p class="rd-page-header__description">Define client policy options and assign them across the managed fleet.</p>
        </div>
        @if ($canEdit)
        <div class="rd-page-header__actions">
            <a href="{{ route('admin.strategies.create') }}" class="rd-btn rd-btn--primary"><i class="ri-add-line" aria-hidden="true"></i> New strategy</a>
        </div>
        @endif
    </header>

    <div class="rd-card rd-card--flush">
        <div class="rd-table-wrap" role="region" aria-label="Strategies" tabindex="0">
            <table class="rd-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Options</th>
                        <th>Assignments</th>
                        <th>Note</th>
                        <th class="rd-table__actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($strategies as $strategy)
                    <tr>
                        <td>
                            <div class="rd-actions rd-actions--wrap">
                                <span class="rd-table__primary">{{ $strategy->name }}</span>
                                @if ($strategy->is_default)<span class="rd-badge rd-badge--online"><i class="ri-star-fill" aria-hidden="true"></i> Default</span>@endif
                            </div>
                        </td>
                        <td>
                            <span class="rd-badge rd-badge--{{ $strategy->enabled ? 'online' : 'offline' }}">
                                <span class="dot"></span>{{ $strategy->enabled ? 'Enabled' : 'Disabled' }}
                            </span>
                        </td>
                        <td class="rd-muted">{{ count($strategy->options ?? []) }}</td>
                        <td class="rd-muted">{{ $strategy->assignments_count }}</td>
                        <td class="rd-muted">{{ $strategy->note ?: '—' }}</td>
                        <td class="rd-table__actions">
                            <div class="rd-actions rd-actions--end rd-actions--wrap">
                                @if ($canEdit)
                                <form method="POST" action="{{ route('admin.strategies.default', $strategy) }}" class="m-0">
                                    @csrf
                                    <button type="submit" class="rd-icon-btn" title="{{ $strategy->is_default ? 'Unset as default' : 'Set as default (fallback for unassigned devices)' }}" aria-label="{{ $strategy->is_default ? 'Unset '.$strategy->name.' as default' : 'Set '.$strategy->name.' as default' }}">
                                        <i class="{{ $strategy->is_default ? 'ri-star-fill' : 'ri-star-line' }}" aria-hidden="true"></i>
                                    </button>
                                </form>
                                @endif
                                <a href="{{ route('admin.strategies.edit', $strategy) }}" class="rd-btn rd-btn--ghost"><i class="{{ $canEdit ? 'ri-pencil-line' : 'ri-eye-line' }}" aria-hidden="true"></i> {{ $canEdit ? 'Edit' : 'View' }}</a>
                                @if ($canEdit)
                                <form method="POST" action="{{ route('admin.strategies.destroy', $strategy) }}" class="m-0">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="rd-btn rd-btn--danger" data-confirm="Delete strategy '{{ $strategy->name }}'?" aria-label="Delete {{ $strategy->name }} strategy" title="Delete strategy"><i class="ri-delete-bin-line" aria-hidden="true"></i></button>
                                </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6"><div class="rd-empty"><i class="rd-empty__icon ri-list-settings-line" aria-hidden="true"></i><p class="rd-empty__title">No strategies yet</p><p class="rd-empty__body">Create a strategy to define and assign client policy.</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('admin.partials.pagination', ['paginator' => $strategies])
    </div>
@endsection
