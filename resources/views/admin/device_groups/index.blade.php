@extends('layouts.admin')
@section('title', 'Device Groups')
@php
    $canEdit = auth()->user()->hasPermission('device_groups.edit');
    $canViewUserGroups = auth()->user()->hasPermission('groups.view');
@endphp

@section('content')
    @include('admin.partials.flash')

    <header class="rd-page-header">
        <div class="rd-page-header__copy">
            <p class="rd-page-header__eyebrow">Fleet</p>
            <h1 class="rd-page-header__title">Device Groups</h1>
            <p class="rd-page-header__description">Organize fleet ownership and choose where new or ungrouped devices are placed.</p>
        </div>
        <div class="rd-page-header__actions rd-actions--wrap">
            @if ($canViewUserGroups)
            <a href="{{ route('admin.groups.index') }}" class="rd-btn rd-btn--ghost"><i class="ri-group-line" aria-hidden="true"></i> User Groups</a>
            @endif
            @if ($canEdit)
            <a href="{{ route('admin.device-groups.create') }}" class="rd-btn rd-btn--primary"><i class="ri-add-line" aria-hidden="true"></i> New device group</a>
            @endif
        </div>
    </header>

    <div class="rd-stack rd-stack--lg">
        <div class="rd-callout rd-callout--info rd-actions rd-align-start">
            <i class="ri-information-line rd-info" aria-hidden="true"></i>
            <span>
                <strong>One</strong> group is the <strong>Default</strong> — new and ungrouped devices are placed here.
                @if ($defaultGroup)
                    Currently <strong>{{ $defaultGroup->name }}</strong>.
                @else
                    None is set yet.
                @endif
                Setting another group as default <strong>replaces</strong> it; the previous default reverts to a normal group (its devices stay put).
            </span>
        </div>

        <div class="rd-card rd-card--flush">
        <div class="rd-table-wrap" role="region" aria-label="Device groups" tabindex="0">
            <table class="rd-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Devices</th>
                        <th>Note</th>
                        <th class="rd-table__actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($deviceGroups as $group)
                    <tr>
                        <td>
                            <span class="rd-table__primary">{{ $group->name }}</span>
                            @if ($group->is_default)
                                <span class="rd-badge rd-badge--online" title="New and ungrouped devices are placed in this group"><i class="ri-star-fill" aria-hidden="true"></i> Default — new devices land here</span>
                            @endif
                        </td>
                        <td class="rd-muted">{{ $group->devices_count }}</td>
                        <td class="rd-muted">{{ $group->note ?: '—' }}</td>
                        <td class="rd-table__actions">
                            <div class="rd-actions rd-actions--end rd-actions--wrap">
                                @if ($canEdit)
                                <form method="POST" action="{{ route('admin.device-groups.default', $group) }}" class="m-0">
                                    @csrf
                                    @if ($group->is_default)
                                        <button type="submit" class="rd-btn rd-btn--ghost" data-confirm="Clear “{{ $group->name }}” as the default? New and ungrouped devices will no longer be auto-placed into any group until you set another default." title="Stop placing new devices here"><i class="ri-star-fill" aria-hidden="true"></i> Unset default</button>
                                    @elseif ($defaultGroup)
                                        <button type="submit" class="rd-btn rd-btn--ghost" data-confirm="Make “{{ $group->name }}” the default group? This replaces the current default “{{ $defaultGroup->name }}”, which reverts to a normal group (its devices stay put). New and ungrouped devices will then land in “{{ $group->name }}”." title="Make this the one default group (replaces the current default)"><i class="ri-star-line" aria-hidden="true"></i> Set as default</button>
                                    @else
                                        <button type="submit" class="rd-btn rd-btn--ghost" data-confirm="Make “{{ $group->name }}” the default group? New and ungrouped devices will be placed here." title="Place new/ungrouped devices in this group"><i class="ri-star-line" aria-hidden="true"></i> Set as default</button>
                                    @endif
                                </form>
                                @endif
                                <a href="{{ route('admin.device-groups.edit', $group) }}" class="rd-btn rd-btn--ghost"><i class="{{ $canEdit ? 'ri-pencil-line' : 'ri-eye-line' }}" aria-hidden="true"></i> {{ $canEdit ? 'Edit' : 'View' }}</a>
                                @if ($canEdit)
                                <form method="POST" action="{{ route('admin.device-groups.destroy', $group) }}" class="m-0">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="rd-btn rd-btn--danger" data-confirm="Delete device group '{{ $group->name }}'?" aria-label="Delete {{ $group->name }} device group" title="Delete device group"><i class="ri-delete-bin-line" aria-hidden="true"></i></button>
                                </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4"><div class="rd-empty"><i class="rd-empty__icon ri-folder-chart-line" aria-hidden="true"></i><p class="rd-empty__title">No device groups yet</p><p class="rd-empty__body">Create a group to organize your fleet.</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('admin.partials.pagination', ['paginator' => $deviceGroups])
        </div>
    </div>
@endsection
