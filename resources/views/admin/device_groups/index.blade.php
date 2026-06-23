@extends('layouts.admin')
@section('title', 'Device Groups')

@section('content')
    @include('admin.partials.flash')
    <div class="rd-breadcrumb">Management / Device Groups</div>

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
                                <span class="rd-badge rd-badge--online" title="New devices are placed in this group">Default</span>
                            @endif
                        </td>
                        <td class="rd-muted">{{ $group->devices_count }}</td>
                        <td class="rd-muted">{{ $group->note ?: '—' }}</td>
                        <td style="text-align:right;">
                            <div class="rd-row" style="justify-content:flex-end;">
                                <form method="POST" action="{{ route('admin.device-groups.default', $group) }}" class="m-0">
                                    @csrf
                                    @if ($group->is_default)
                                        <button type="submit" class="rd-btn rd-btn--ghost" title="Stop placing new devices here"><i class="ri-star-fill"></i> Unset default</button>
                                    @else
                                        <button type="submit" class="rd-btn rd-btn--ghost" title="Place new/ungrouped devices in this group"><i class="ri-star-line"></i> Set default</button>
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
