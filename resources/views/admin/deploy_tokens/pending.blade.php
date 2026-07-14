@extends('layouts.admin')
@section('title', 'Pending Devices')
@php($canEdit = auth()->user()->hasPermission('deploy.edit'))

@section('content')
    @include('admin.partials.flash')

    <header class="rd-page-header">
        <div class="rd-page-header__copy">
            <p class="rd-page-header__eyebrow">Fleet</p>
            <h1 class="rd-page-header__title">Pending Devices</h1>
            <p class="rd-page-header__description">{{ $canEdit ? 'Approve or reject devices that registered through the deployment workflow.' : 'Review devices awaiting deployment approval.' }}</p>
        </div>
        <div class="rd-page-header__actions">
            <a href="{{ route('admin.deploy-tokens.index') }}" class="rd-btn rd-btn--ghost"><i class="ri-key-2-line" aria-hidden="true"></i> Deploy Tokens</a>
        </div>
    </header>

    <div class="rd-card rd-card--flush">
        <div class="rd-table-wrap" role="region" aria-label="Pending devices" tabindex="0">
            <table class="rd-table">
                <thead>
                    <tr>
                        <th>Device</th>
                        <th>OS</th>
                        <th>Owner</th>
                        <th>Registered</th>
                        <th class="rd-table__actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($devices as $device)
                    <tr>
                        <td>
                            <div class="rd-table__primary">{{ $device->hostname ?: $device->alias ?: $device->rustdesk_id }}</div>
                            <div class="rd-table__meta rd-mono">{{ $device->rustdesk_id }}</div>
                        </td>
                        <td class="rd-muted">{{ $device->os ?: '—' }}</td>
                        <td class="rd-muted">{{ $device->user->username ?? '—' }}</td>
                        <td class="rd-muted">{{ $device->created_at?->diffForHumans() ?? '—' }}</td>
                        <td class="rd-table__actions">
                            @if ($canEdit)
                            <div class="rd-actions rd-actions--end rd-actions--wrap">
                                <form method="POST" action="{{ route('admin.devices.approve', $device) }}" class="m-0">
                                    @csrf
                                    @method('PUT')
                                    <button type="submit" class="rd-btn rd-btn--primary"><i class="ri-check-line" aria-hidden="true"></i> Approve</button>
                                </form>
                                <form method="POST" action="{{ route('admin.devices.reject', $device) }}" class="m-0">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="rd-btn rd-btn--danger" data-confirm="Reject and delete this device?"><i class="ri-close-line" aria-hidden="true"></i> Reject</button>
                                </form>
                            </div>
                            @else
                                <span class="rd-muted">View only</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5"><div class="rd-empty"><i class="rd-empty__icon ri-shield-check-line" aria-hidden="true"></i><p class="rd-empty__title">No devices awaiting approval</p><p class="rd-empty__body">New registrations that require review will appear here.</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('admin.partials.pagination', ['paginator' => $devices])
    </div>
@endsection
