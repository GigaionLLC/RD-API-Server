@extends('layouts.admin')
@section('title', 'Devices')

@section('content')
    @include('admin.partials.flash')
    <div class="rd-breadcrumb">Management / Devices</div>

    <style>
        .rd-bulkbar { display:flex; align-items:center; gap:8px; flex-wrap:wrap; padding:11px 16px;
            background:var(--rd-surface-2); border-bottom:1px solid var(--rd-border); }
    </style>

    <div class="rd-card">
        <div class="rd-card__header">
            <h3 class="rd-card__title">Devices</h3>
            <form method="GET" action="{{ route('admin.devices.index') }}" class="rd-row">
                <input class="rd-input" type="search" name="q" value="{{ $q }}" placeholder="Search id / host / alias" style="width:220px;">
                <select class="rd-select" name="status" style="width:130px;" onchange="this.form.submit()">
                    <option value="">All status</option>
                    <option value="online"  @selected($status === 'online')>Online</option>
                    <option value="offline" @selected($status === 'offline')>Offline</option>
                </select>
                <button class="rd-btn rd-btn--ghost" type="submit"><i class="ri-search-line"></i> Search</button>
                <a class="rd-btn rd-btn--ghost" href="{{ route('admin.devices.export', request()->query()) }}"><i class="ri-download-2-line"></i> Export CSV</a>
            </form>
        </div>

        {{-- Bulk-assign bar (shown when ≥1 device is selected) --}}
        <form method="POST" id="bulkForm" action="{{ route('admin.devices.bulk') }}" class="rd-bulkbar" style="display:none;">
            @csrf
            <span id="bulkCount" class="rd-muted" style="font-size:13px;"></span>
            <span id="bulkAllWrap" class="rd-muted" style="font-size:13px;display:none;">
                · <a href="#" id="bulkSelectAll">Select all {{ $devices->total() }} matching this filter</a>
                <span id="bulkAllOn" style="display:none;color:var(--rd-primary);font-weight:600;">All {{ $devices->total() }} matching selected · <a href="#" id="bulkSelectAllClear">clear</a></span>
            </span>
            <select class="rd-select" id="bulkField" name="field" style="width:170px;">
                <option value="user_id">Set owner</option>
                <option value="device_group_id">Set device group</option>
                <option value="strategy_id">Set strategy</option>
            </select>
            {{-- user: searchable combobox; group/strategy: plain selects. Blank = clear. --}}
            <div class="rd-combo" data-field="user_id" data-url="{{ route('admin.users.search') }}" style="width:240px;">
                <input type="hidden" name="value">
                <input type="text" class="rd-input rd-combo__input" placeholder="Search user… (blank = none)" autocomplete="off">
                <div class="rd-combo__menu"></div>
            </div>
            <select class="rd-select" name="value" data-field="device_group_id" style="width:220px;display:none;" disabled>
                <option value="">— None —</option>
                @foreach ($deviceGroups as $g)<option value="{{ $g->id }}">{{ $g->name }}</option>@endforeach
            </select>
            <select class="rd-select" name="value" data-field="strategy_id" style="width:220px;display:none;" disabled>
                <option value="">— None —</option>
                @foreach ($strategies as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
            </select>
            <button type="submit" class="rd-btn rd-btn--primary"><i class="ri-check-line"></i> Apply</button>
            <button type="button" class="rd-btn rd-btn--ghost" id="bulkClear">Clear</button>
            <span id="bulkIds"></span>
        </form>

        <div class="rd-card__body" style="padding:0;">
            <table class="rd-table">
                <thead>
                    <tr>
                        <th style="width:34px;"><input type="checkbox" id="checkAll" title="Select all on this page"></th>
                        <th>Device</th>
                        <th>OS</th>
                        <th>Owner</th>
                        <th>Status</th>
                        <th>Last seen</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($devices as $device)
                    <tr>
                        <td><input type="checkbox" class="dev-check" value="{{ $device->id }}"></td>
                        <td>
                            <div style="color:var(--rd-text-bright);font-weight:600;">{{ $device->hostname ?: $device->alias ?: $device->rustdesk_id }}</div>
                            <div class="rd-muted" style="font-size:12px;">{{ $device->rustdesk_id }}</div>
                        </td>
                        <td class="rd-muted">{{ $device->os ?: '—' }}</td>
                        <td class="rd-muted">{{ $device->user->username ?? '—' }}</td>
                        <td>
                            <span class="rd-badge rd-badge--{{ $device->is_online ? 'online' : 'offline' }}">
                                <span class="dot"></span>{{ $device->is_online ? 'Online' : 'Offline' }}
                            </span>
                        </td>
                        <td class="rd-muted">{{ $device->last_online_at?->diffForHumans() ?? '—' }}</td>
                        <td style="text-align:right;">
                            <div class="rd-row" style="justify-content:flex-end;">
                                <a href="{{ route('admin.devices.edit', $device) }}" class="rd-btn rd-btn--ghost"><i class="ri-pencil-line"></i> Edit</a>
                                <form method="POST" action="{{ route('admin.devices.destroy', $device) }}" class="m-0">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="rd-btn rd-btn--danger" data-confirm="Delete this device? This cannot be undone."><i class="ri-delete-bin-line"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="rd-muted" style="text-align:center;padding:28px;">No devices found.</td></tr>
                @endforelse
                </tbody>
            </table>
            @include('admin.partials.pagination', ['paginator' => $devices])
        </div>
    </div>
@endsection

@push('scripts')
<script>
    $(function () {
        function selectedIds() {
            return $('.dev-check:checked').map(function () { return this.value; }).get();
        }
        var applyAll = false;        // apply to the whole filtered set, not just checked rows
        var pageCount = {{ $devices->count() }};
        var total = {{ $devices->total() }};

        function resetApplyAll() {
            applyAll = false;
            $('#bulkAllOn').hide();
            $('#bulkSelectAll').show();
        }
        function refreshBulk() {
            var n = selectedIds().length;
            $('#bulkCount').text(applyAll ? '' : (n + ' selected'));
            $('#bulkForm').toggle(n > 0);
            // Offer "select all matching" once the whole page is checked and more rows exist.
            $('#bulkAllWrap').toggle(n > 0 && n === pageCount && total > pageCount);
            if (n === 0) { resetApplyAll(); }
        }

        $('#checkAll').on('change', function () {
            $('.dev-check').prop('checked', this.checked);
            resetApplyAll();
            refreshBulk();
        });
        $(document).on('change', '.dev-check', function () {
            var all = $('.dev-check'), checked = $('.dev-check:checked');
            $('#checkAll').prop('checked', all.length > 0 && checked.length === all.length);
            resetApplyAll();
            refreshBulk();
        });
        $('#bulkClear').on('click', function () {
            $('.dev-check, #checkAll').prop('checked', false);
            resetApplyAll();
            refreshBulk();
        });
        $('#bulkSelectAll').on('click', function (e) {
            e.preventDefault();
            applyAll = true;
            $(this).hide();
            $('#bulkAllOn').show();
            $('#bulkCount').text('');
        });
        $('#bulkSelectAllClear').on('click', function (e) {
            e.preventDefault();
            resetApplyAll();
            refreshBulk();
        });

        // Swap the value control to match the chosen field (combo for user, select otherwise).
        function syncBulkField() {
            var f = $('#bulkField').val();
            $('#bulkForm [data-field]').each(function () {
                var $el = $(this), match = $el.data('field') === f;
                $el.toggle(match);
                if ($el.is('select')) {
                    $el.prop('disabled', !match);
                } else {
                    var $hidden = $el.find('input[type="hidden"]');
                    $hidden.prop('disabled', !match);
                    if (!match) { $hidden.val(''); $el.find('.rd-combo__input').val(''); }
                }
            });
        }
        $('#bulkField').on('change', syncBulkField);
        syncBulkField();

        // Inject the checked ids (or the all-matching flag + filter) and confirm before applying.
        $('#bulkForm').on('submit', function (e) {
            var ids = selectedIds();
            var $box = $('#bulkIds').empty();
            if (applyAll) {
                if (!window.confirm('Apply this change to ALL ' + total + ' device(s) matching the current filter?')) { e.preventDefault(); return; }
                $('<input type="hidden" name="all" value="1">').appendTo($box);
                $('<input type="hidden" name="q">').val(@json($q)).appendTo($box);
                $('<input type="hidden" name="status">').val(@json($status)).appendTo($box);
                return;
            }
            if (!ids.length) { e.preventDefault(); return; }
            if (!window.confirm('Apply this change to ' + ids.length + ' device(s)?')) { e.preventDefault(); return; }
            ids.forEach(function (id) { $('<input type="hidden" name="ids[]">').val(id).appendTo($box); });
        });
    });
</script>
@endpush
