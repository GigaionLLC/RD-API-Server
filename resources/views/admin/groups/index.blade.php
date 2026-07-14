@extends('layouts.admin')
@section('title', 'Groups')

@php
    $typeLabels = [
        \App\Models\Group::TYPE_DEFAULT => 'Default',
        \App\Models\Group::TYPE_SHARED => 'Shared',
    ];
@endphp

@section('content')
    @include('admin.partials.flash')

    <header class="rd-page-header">
        <div class="rd-page-header__copy">
            <p class="rd-page-header__eyebrow">People &amp; Access</p>
            <h1 class="rd-page-header__title">User Groups</h1>
            <p class="rd-page-header__description">Organize users for shared access, policy assignment, and delegated administration.</p>
        </div>
        <div class="rd-page-header__actions rd-actions--wrap">
            <a href="{{ route('admin.device-groups.index') }}" class="rd-btn rd-btn--ghost"><i class="ri-device-line" aria-hidden="true"></i> Device Groups</a>
            <a href="{{ route('admin.groups.create') }}" class="rd-btn rd-btn--primary"><i class="ri-add-line" aria-hidden="true"></i> New group</a>
        </div>
    </header>

    <div class="rd-card rd-card--flush">
        <div class="rd-table-wrap" role="region" aria-label="User groups" tabindex="0">
            <table class="rd-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Members</th>
                        <th>Note</th>
                        <th class="rd-table__actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($groups as $group)
                    <tr>
                        <td><span class="rd-table__primary">{{ $group->name }}</span></td>
                        <td><span class="rd-badge rd-badge--muted">{{ $typeLabels[$group->type] ?? 'Unknown' }}</span></td>
                        <td class="rd-muted">{{ $memberCounts[$group->id] ?? 0 }}</td>
                        <td class="rd-muted">{{ $group->note ?: '—' }}</td>
                        <td class="rd-table__actions">
                            <div class="rd-actions rd-actions--end rd-actions--wrap">
                                <a href="{{ route('admin.groups.edit', $group) }}" class="rd-btn rd-btn--ghost"><i class="ri-pencil-line"></i> Edit</a>
                                <form method="POST" action="{{ route('admin.groups.destroy', $group) }}" class="m-0">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="rd-btn rd-btn--danger" data-confirm="Delete group '{{ $group->name }}'?" aria-label="Delete {{ $group->name }} user group" title="Delete group"><i class="ri-delete-bin-line" aria-hidden="true"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5"><div class="rd-empty"><i class="rd-empty__icon ri-group-line" aria-hidden="true"></i><p class="rd-empty__title">No groups yet</p><p class="rd-empty__body">Create a user group to organize access.</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('admin.partials.pagination', ['paginator' => $groups])
    </div>
@endsection
