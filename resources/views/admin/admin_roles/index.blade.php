@extends('layouts.admin')
@section('title', 'Admin Roles')

@php
    $typeLabels = [
        \App\Models\AdminRole::TYPE_GLOBAL => 'Global',
        \App\Models\AdminRole::TYPE_INDIVIDUAL => 'Individual',
        \App\Models\AdminRole::TYPE_GROUP => 'Group-scoped',
    ];
@endphp

@section('content')
    @include('admin.partials.flash')

    <header class="rd-page-header">
        <div class="rd-page-header__copy">
            <p class="rd-page-header__eyebrow">People &amp; Access</p>
            <h1 class="rd-page-header__title">Admin Roles</h1>
            <p class="rd-page-header__description">Control administrative access with global, individual, and group-scoped roles.</p>
        </div>
        <div class="rd-page-header__actions">
            <a href="{{ route('admin.roles.create') }}" class="rd-btn rd-btn--primary"><i class="ri-add-line" aria-hidden="true"></i> New role</a>
        </div>
    </header>

    <div class="rd-card rd-card--flush">
        <div class="rd-table-wrap" role="region" aria-label="Admin roles" tabindex="0">
            <table class="rd-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Permissions</th>
                        <th>Members</th>
                        <th class="rd-table__actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($roles as $role)
                    <tr>
                        <td><span class="rd-table__primary">{{ $role->name }}</span></td>
                        <td><span class="rd-badge rd-badge--muted">{{ $typeLabels[$role->type] ?? 'Unknown' }}</span></td>
                        <td class="rd-muted">
                            @if ($role->type === \App\Models\AdminRole::TYPE_GLOBAL)
                                Full access
                            @else
                                {{ count((array) $role->perms) }} granted
                            @endif
                        </td>
                        <td class="rd-muted">{{ $role->users_count }}</td>
                        <td class="rd-table__actions">
                            <div class="rd-actions rd-actions--end rd-actions--wrap">
                                <a href="{{ route('admin.roles.edit', $role) }}" class="rd-btn rd-btn--ghost"><i class="ri-pencil-line"></i> Edit</a>
                                <form method="POST" action="{{ route('admin.roles.destroy', $role) }}" class="m-0">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="rd-btn rd-btn--danger" data-confirm="Delete role '{{ $role->name }}'? Members lose these permissions." aria-label="Delete {{ $role->name }} role" title="Delete role"><i class="ri-delete-bin-line" aria-hidden="true"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">
                            <div class="rd-empty">
                                <i class="rd-empty__icon ri-shield-user-line" aria-hidden="true"></i>
                                <p class="rd-empty__title">No admin roles yet</p>
                                <p class="rd-empty__body">Create a role to delegate administrative access.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('admin.partials.pagination', ['paginator' => $roles])
    </div>
@endsection
