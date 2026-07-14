@extends('layouts.admin')
@section('title', 'Users')

@php
    $statusLabels = [
        \App\Models\User::STATUS_NORMAL => ['Active', 'online'],
        \App\Models\User::STATUS_DISABLED => ['Disabled', 'offline'],
        \App\Models\User::STATUS_UNVERIFIED => ['Unverified', 'muted'],
    ];
@endphp

@section('content')
    @include('admin.partials.flash')

    <header class="rd-page-header">
        <div class="rd-page-header__copy">
            <p class="rd-page-header__eyebrow">People &amp; Access</p>
            <h1 class="rd-page-header__title">Users</h1>
            <p class="rd-page-header__description">Manage identities, administrative access, account state, and group membership.</p>
        </div>
        @if ($canEdit)
        <div class="rd-page-header__actions">
            <a href="{{ route('admin.users.create') }}" class="rd-btn rd-btn--primary"><i class="ri-add-line" aria-hidden="true"></i> New user</a>
        </div>
        @endif
    </header>

    <div class="rd-card rd-card--flush">
        <div class="rd-toolbar">
            <form method="GET" action="{{ route('admin.users.index') }}" class="rd-toolbar__group">
                <label class="visually-hidden" for="user-search">Search users</label>
                <input class="rd-input rd-toolbar__search" id="user-search" type="search" name="q" value="{{ $q }}" placeholder="Search users">
                <button class="rd-btn rd-btn--ghost" type="submit"><i class="ri-search-line" aria-hidden="true"></i> Search</button>
                @if (filled($q))
                    <a class="rd-btn rd-btn--ghost" href="{{ route('admin.users.index') }}">Reset</a>
                @endif
            </form>
        </div>

        {{-- Bulk-action bar (shown when ≥1 user is selected) --}}
        @if ($canEdit)
        <form method="POST" id="bulkForm" action="{{ route('admin.users.bulk') }}" class="rd-bulkbar rd-actions--wrap">
            @csrf
            <span id="bulkCount" class="rd-bulkbar__count" aria-live="polite"></span>
            <div class="rd-bulkbar__actions rd-actions rd-actions--wrap">
                <label class="visually-hidden" for="bulkAction">Bulk action</label>
                <select class="rd-select rd-toolbar__control" id="bulkAction" name="action">
                    <option value="enable">Enable</option>
                    <option value="disable">Disable</option>
                    <option value="group">Set group</option>
                    <option value="delete">Delete</option>
                </select>
                <label class="visually-hidden" for="bulkGroup">User group</label>
                <select class="rd-select rd-toolbar__control rd-hidden" name="value" id="bulkGroup" disabled>
                    <option value="">— No group —</option>
                    @foreach ($groups as $g)<option value="{{ $g->id }}">{{ $g->name }}</option>@endforeach
                </select>
                <button type="submit" class="rd-btn rd-btn--primary"><i class="ri-check-line" aria-hidden="true"></i> Apply</button>
                <button type="button" class="rd-btn rd-btn--ghost" id="bulkClear">Clear</button>
            </div>
            <span id="bulkIds"></span>
        </form>
        @endif

        <div class="rd-table-wrap" role="region" aria-label="Users" tabindex="0">
            <table class="rd-table">
                <thead>
                    <tr>
                        <th>@if ($canEdit)<input type="checkbox" id="checkAll" title="Select all on this page" aria-label="Select all manageable users on this page">@endif</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Display name</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th class="rd-table__actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($users as $user)
                    @php
                        $s = $statusLabels[$user->status] ?? ['Unknown', 'muted'];
                        $isPrivileged = $user->is_admin || $user->admin_roles_exists;
                        $canManageTarget = $canEdit && ($canManageAdminAccess || ! $isPrivileged);
                        $canViewTarget = $canManageAdminAccess || ! $isPrivileged;
                    @endphp
                    <tr>
                        <td>
                            @if ($canManageTarget)
                                <input type="checkbox" class="usr-check" value="{{ $user->id }}" aria-label="Select {{ $user->username }}">
                            @else
                                <span class="rd-muted" aria-hidden="true">&mdash;</span>
                            @endif
                        </td>
                        <td><span class="rd-table__primary">{{ $user->username }}</span></td>
                        <td class="rd-muted">{{ $user->email ?: '—' }}</td>
                        <td class="rd-muted">{{ $user->display_name ?: '—' }}</td>
                        <td>
                            @if ($user->is_admin)
                                <span class="rd-badge rd-badge--online"><span class="dot"></span>Admin</span>
                            @elseif ($user->admin_roles_exists)
                                <span class="rd-badge rd-badge--info">Delegated admin</span>
                            @else
                                <span class="rd-badge rd-badge--muted">User</span>
                            @endif
                        </td>
                        <td><span class="rd-badge rd-badge--{{ $s[1] }}"><span class="dot"></span>{{ $s[0] }}</span></td>
                        <td class="rd-table__actions">
                            <div class="rd-actions rd-actions--end rd-actions--wrap">
                                @if ($canViewTarget)
                                <a href="{{ route('admin.users.edit', $user) }}" class="rd-btn rd-btn--ghost"><i class="{{ $canManageTarget ? 'ri-pencil-line' : 'ri-eye-line' }}" aria-hidden="true"></i> {{ $canManageTarget ? 'Edit' : 'View' }}</a>
                                @endif
                                @if ($canManageTarget)
                                <form method="POST" action="{{ route('admin.users.destroy', $user) }}" class="m-0">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="rd-btn rd-btn--danger" data-confirm="Delete user '{{ $user->username }}'? This cannot be undone." aria-label="Delete {{ $user->username }}" title="Delete user"><i class="ri-delete-bin-line" aria-hidden="true"></i></button>
                                </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7"><div class="rd-empty"><i class="rd-empty__icon ri-user-search-line" aria-hidden="true"></i><p class="rd-empty__title">No users found</p><p class="rd-empty__body">Try another search or create a user.</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('admin.partials.pagination', ['paginator' => $users])
    </div>
@endsection

@if ($canEdit)
@push('scripts')
<script>
    $(function () {
        function selectedIds() {
            return $('.usr-check:checked').map(function () { return this.value; }).get();
        }
        function refreshBulk() {
            var $all = $('.usr-check');
            var n = selectedIds().length;
            $('#bulkCount').text(n + ' selected');
            $('#bulkForm').toggleClass('is-visible', n > 0);
            $('#checkAll')
                .prop('checked', $all.length > 0 && n === $all.length)
                .prop('indeterminate', n > 0 && n < $all.length);
        }
        $('#checkAll').on('change', function () {
            $('.usr-check').prop('checked', this.checked);
            refreshBulk();
        });
        $(document).on('change', '.usr-check', function () {
            refreshBulk();
        });
        $('#bulkClear').on('click', function () {
            $('.usr-check, #checkAll').prop('checked', false);
            refreshBulk();
        });

        // The group select only applies to the "Set group" action.
        function syncAction() {
            var isGroup = $('#bulkAction').val() === 'group';
            $('#bulkGroup').toggleClass('rd-hidden', !isGroup).prop('disabled', !isGroup);
        }
        $('#bulkAction').on('change', syncAction);
        syncAction();

        $('#bulkForm').on('submit', function (e) {
            e.preventDefault();
            var form = this;
            var ids = selectedIds(), action = $('#bulkAction').val();
            if (!ids.length) { return; }
            var verb = { enable: 'enable', disable: 'disable', delete: 'DELETE', group: 'update' }[action] || 'update';
            RD.confirm(verb + ' ' + ids.length + ' selected user(s)?', {
                title: action === 'delete' ? 'Delete selected users' : 'Apply bulk user change',
                action: action === 'delete' ? 'Delete users' : 'Apply change',
                danger: action === 'delete'
            }).done(function (confirmed) {
                if (!confirmed) { return; }
                var $box = $('#bulkIds').empty();
                ids.forEach(function (id) {
                    $('<input type="hidden" name="ids[]">').val(id).appendTo($box);
                });
                window.HTMLFormElement.prototype.submit.call(form);
            });
        });
    });
</script>
@endpush
@endif
