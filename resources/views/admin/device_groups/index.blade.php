@extends('layouts.admin')
@section('title', 'Device Groups')

@section('content')
    @include('admin.partials.flash')
    <div class="rd-breadcrumb">Management / Device Groups</div>

    <div class="rd-card" style="margin-bottom:16px;">
        <div class="rd-card__body" style="display:flex;align-items:center;gap:10px;">
            <i class="ri-information-line" style="color:var(--rd-primary);font-size:18px;"></i>
            <span class="rd-muted" style="font-size:13px;">
                <strong style="color:var(--rd-text-bright);">One</strong> group is the <strong style="color:var(--rd-text-bright);">Default</strong> — new and ungrouped devices are placed here.
                @if ($defaultGroup)
                    Currently <strong style="color:var(--rd-text-bright);">{{ $defaultGroup->name }}</strong>.
                @else
                    None is set yet.
                @endif
                Setting another group as default <strong style="color:var(--rd-text-bright);">replaces</strong> it; the previous default reverts to a normal group (its devices stay put).
            </span>
        </div>
    </div>

    <div class="rd-card">
        <div class="rd-card__header">
            <h3 class="rd-card__title">Device Groups</h3>
            <div class="rd-row">
                <a href="{{ route('admin.groups.index') }}" class="rd-btn rd-btn--ghost"><i class="ri-group-line"></i> User Groups</a>
                <a href="{{ route('admin.device-groups.create') }}" class="rd-btn rd-btn--primary"><i class="ri-add-line"></i> New device group</a>
            </div>
        </div>
        <div class="rd-card__body" style="padding:0;">
            <table class="rd-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Devices</th>
                        <th>Note</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($deviceGroups as $group)
                    <tr>
                        <td style="color:var(--rd-text-bright);font-weight:600;">
                            {{ $group->name }}
                            @if ($group->is_default)
                                <span class="rd-badge rd-badge--online" title="New and ungrouped devices are placed in this group"><i class="ri-star-fill"></i> Default — new devices land here</span>
                            @endif
                        </td>
                        <td class="rd-muted">{{ $group->devices_count }}</td>
                        <td class="rd-muted">{{ $group->note ?: '—' }}</td>
                        <td style="text-align:right;">
                            <div class="rd-row" style="justify-content:flex-end;">
                                <form method="POST" action="{{ route('admin.device-groups.default', $group) }}" class="m-0">
                                    @csrf
                                    @if ($group->is_default)
                                        <button type="submit" class="rd-btn rd-btn--ghost" data-confirm="Clear “{{ $group->name }}” as the default? New and ungrouped devices will no longer be auto-placed into any group until you set another default." title="Stop placing new devices here"><i class="ri-star-fill"></i> Unset default</button>
                                    @elseif ($defaultGroup)
                                        <button type="submit" class="rd-btn rd-btn--ghost" data-confirm="Make “{{ $group->name }}” the default group? This replaces the current default “{{ $defaultGroup->name }}”, which reverts to a normal group (its devices stay put). New and ungrouped devices will then land in “{{ $group->name }}”." title="Make this the one default group (replaces the current default)"><i class="ri-star-line"></i> Set as default</button>
                                    @else
                                        <button type="submit" class="rd-btn rd-btn--ghost" data-confirm="Make “{{ $group->name }}” the default group? New and ungrouped devices will be placed here." title="Place new/ungrouped devices in this group"><i class="ri-star-line"></i> Set as default</button>
                                    @endif
                                </form>
                                <a href="{{ route('admin.device-groups.edit', $group) }}" class="rd-btn rd-btn--ghost"><i class="ri-pencil-line"></i> Edit</a>
                                <form method="POST" action="{{ route('admin.device-groups.destroy', $group) }}" class="m-0">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="rd-btn rd-btn--danger" data-confirm="Delete device group '{{ $group->name }}'?"><i class="ri-delete-bin-line"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="rd-muted" style="text-align:center;padding:28px;">No device groups yet.</td></tr>
                @endforelse
                </tbody>
            </table>
            @include('admin.partials.pagination', ['paginator' => $deviceGroups])
        </div>
    </div>
@endsection
