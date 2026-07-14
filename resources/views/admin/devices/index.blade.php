@extends('layouts.admin')
@section('title', 'Devices')

@php
    $statusFilter = in_array($status, ['online', 'offline'], true) ? $status : '';
    $filtersActive = $q !== '' || $statusFilter !== '';
    $pageOnline = $devices->getCollection()->where('is_online', true)->count();
    $canEdit = auth()->user()->hasPermission('devices.edit');
@endphp

@section('content')
    @include('admin.partials.flash')
    <div class="rd-stack rd-stack--lg">
        <header class="rd-page-header">
            <div class="rd-page-header__copy">
                <div class="rd-breadcrumb" aria-label="Breadcrumb">Fleet / Devices</div>
                <p class="rd-page-header__eyebrow">Fleet inventory</p>
                <h1 class="rd-page-header__title">Devices</h1>
                <p class="rd-page-header__description">Find endpoints, assess their current state, and update ownership or rollout policy in bulk.</p>
            </div>
            <div class="rd-page-header__actions">
                <a class="rd-btn rd-btn--ghost" href="{{ route('admin.devices.export', request()->query()) }}">
                    <i class="ri-download-2-line" aria-hidden="true"></i> Export CSV
                </a>
            </div>
        </header>

        <section class="rd-summary" aria-label="Device results summary">
            <div class="rd-summary__item rd-summary__item--primary">
                <span class="rd-summary__icon" aria-hidden="true"><i class="ri-computer-line"></i></span>
                <div><p class="rd-summary__value">{{ number_format($devices->total()) }}</p><p class="rd-summary__label">Matching devices</p></div>
            </div>
            <div class="rd-summary__item rd-summary__item--info">
                <span class="rd-summary__icon" aria-hidden="true"><i class="ri-pages-line"></i></span>
                <div><p class="rd-summary__value">{{ number_format($devices->count()) }}</p><p class="rd-summary__label">Shown on this page</p></div>
            </div>
            <div class="rd-summary__item rd-summary__item--success">
                <span class="rd-summary__icon" aria-hidden="true"><i class="ri-base-station-line"></i></span>
                <div><p class="rd-summary__value">{{ number_format($pageOnline) }}</p><p class="rd-summary__label">Online on this page</p></div>
            </div>
            <div class="rd-summary__item rd-summary__item--warning">
                <span class="rd-summary__icon" aria-hidden="true"><i class="ri-filter-3-line"></i></span>
                <div><p class="rd-summary__value">{{ $statusFilter ? ucfirst($statusFilter) : 'All' }}</p><p class="rd-summary__label">Status filter</p></div>
            </div>
        </section>

        <section class="rd-card rd-card--flush" aria-labelledby="device-results-title">
            <h2 class="visually-hidden" id="device-results-title">Device results</h2>
            <form method="GET" action="{{ route('admin.devices.index') }}" class="rd-toolbar">
                <div class="rd-toolbar__group rd-grow">
                    <label class="visually-hidden" for="deviceSearch">Search devices</label>
                    <input class="rd-input rd-toolbar__search" id="deviceSearch" type="search" name="q" value="{{ $q }}" placeholder="Search ID, hostname, or alias">
                    <label class="visually-hidden" for="deviceStatus">Filter by status</label>
                    <select class="rd-select rd-toolbar__control" id="deviceStatus" name="status" onchange="this.form.submit()">
                        <option value="">All statuses</option>
                        <option value="online" @selected($statusFilter === 'online')>Online</option>
                        <option value="offline" @selected($statusFilter === 'offline')>Offline</option>
                    </select>
                    <button class="rd-btn rd-btn--primary" type="submit"><i class="ri-search-line" aria-hidden="true"></i> Search</button>
                    @if ($filtersActive)
                        <a class="rd-btn rd-btn--ghost" href="{{ route('admin.devices.index') }}">Reset filters</a>
                    @endif
                </div>
                <p class="rd-muted rd-nowrap">{{ number_format($devices->total()) }} {{ Str::plural('result', $devices->total()) }}</p>
            </form>

            {{-- Bulk-assign bar (shown when at least one device is selected). --}}
            @if ($canEdit)
            <form method="POST" id="bulkForm" action="{{ route('admin.devices.bulk') }}" class="rd-bulkbar rd-actions--wrap" hidden>
                @csrf
                <div class="rd-bulkbar__count" aria-live="polite">
                    <strong id="bulkCount"></strong>
                    <span id="bulkAllWrap" class="rd-muted" hidden>
                        <span aria-hidden="true">·</span>
                        <a href="#" id="bulkSelectAll">Select all {{ $devices->total() }} matching this filter</a>
                        <span id="bulkAllOn" hidden>All {{ $devices->total() }} matching devices selected · <a href="#" id="bulkSelectAllClear">Clear</a></span>
                    </span>
                </div>
                <div class="rd-bulkbar__actions rd-actions--wrap">
                    <label class="visually-hidden" for="bulkField">Field to update</label>
                    <select class="rd-select rd-max-w-sm" id="bulkField" name="field">
                        <option value="user_id">Set owner</option>
                        <option value="device_group_id">Set device group</option>
                        <option value="strategy_id">Set strategy</option>
                    </select>
                    {{-- User: searchable combobox; group/strategy: plain selects. Blank clears the value. --}}
                    <div class="rd-combo rd-max-w-md" data-field="user_id" data-url="{{ route('admin.users.search') }}">
                        <input type="hidden" name="value">
                        <input type="text" class="rd-input rd-combo__input" aria-label="Owner" placeholder="Search user… (blank = none)" autocomplete="off">
                        <div class="rd-combo__menu"></div>
                    </div>
                    <select class="rd-select rd-max-w-md" name="value" data-field="device_group_id" aria-label="Device group" hidden disabled>
                        <option value="">— None —</option>
                        @foreach ($deviceGroups as $g)<option value="{{ $g->id }}">{{ $g->name }}</option>@endforeach
                    </select>
                    <select class="rd-select rd-max-w-md" name="value" data-field="strategy_id" aria-label="Strategy" hidden disabled>
                        <option value="">— None —</option>
                        @foreach ($strategies as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                    </select>
                    <button type="submit" class="rd-btn rd-btn--primary"><i class="ri-check-line" aria-hidden="true"></i> Apply</button>
                    <button type="button" class="rd-btn rd-btn--ghost" id="bulkClear">Clear selection</button>
                    <span id="bulkIds"></span>
                </div>
            </form>
            @endif

            <div class="rd-table-wrap" role="region" tabindex="0" aria-label="Devices table">
                <table class="rd-table">
                    <thead>
                        <tr>
                            @if ($canEdit)
                                <th><label class="rd-check"><input type="checkbox" id="checkAll"><span class="visually-hidden">Select all devices on this page</span></label></th>
                            @endif
                            <th>Device</th>
                            <th>OS</th>
                            <th>Owner</th>
                            <th>Status</th>
                            <th>Last seen</th>
                            <th><span class="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse ($devices as $device)
                        <tr>
                            @if ($canEdit)
                                <td><label class="rd-check"><input class="dev-check" type="checkbox" value="{{ $device->id }}"><span class="visually-hidden">Select {{ $device->hostname ?: $device->alias ?: $device->rustdesk_id }}</span></label></td>
                            @endif
                            <td>
                                <span class="rd-table__primary">{{ $device->hostname ?: $device->alias ?: $device->rustdesk_id }}</span>
                                <span class="rd-table__meta rd-mono">{{ $device->rustdesk_id }}</span>
                            </td>
                            <td class="rd-muted">{{ $device->os ?: '—' }}</td>
                            <td class="rd-muted">{{ $device->user->username ?? '—' }}</td>
                            <td>
                                <span class="rd-badge rd-badge--{{ $device->is_online ? 'online' : 'offline' }}">
                                    <span class="dot" aria-hidden="true"></span>{{ $device->is_online ? 'Online' : 'Offline' }}
                                </span>
                            </td>
                            <td class="rd-muted">{{ $device->last_online_at?->diffForHumans() ?? '—' }}</td>
                            <td>
                                <div class="rd-table__actions">
                                    <a href="{{ route('admin.devices.edit', $device) }}" class="rd-btn rd-btn--ghost"><i class="{{ $canEdit ? 'ri-pencil-line' : 'ri-eye-line' }}" aria-hidden="true"></i> {{ $canEdit ? 'Edit' : 'View' }}</a>
                                    @if ($canEdit)
                                    <form method="POST" action="{{ route('admin.devices.destroy', $device) }}" class="m-0">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="rd-icon-btn rd-icon-btn--danger" aria-label="Delete {{ $device->hostname ?: $device->alias ?: $device->rustdesk_id }}" title="Delete device" data-confirm="Delete this device? This cannot be undone."><i class="ri-delete-bin-line" aria-hidden="true"></i></button>
                                    </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canEdit ? 7 : 6 }}">
                                <div class="rd-empty">
                                    <i class="ri-computer-line rd-empty__icon" aria-hidden="true"></i>
                                    <p class="rd-empty__title">{{ $filtersActive ? 'No devices match these filters' : 'No devices have checked in yet' }}</p>
                                    <p class="rd-empty__body">{{ $filtersActive ? 'Try a broader search or reset the current filters.' : 'Devices appear here after their first heartbeat.' }}</p>
                                    @if ($filtersActive)
                                        <div class="rd-empty__actions"><a class="rd-btn rd-btn--ghost" href="{{ route('admin.devices.index') }}">Reset filters</a></div>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @include('admin.partials.pagination', ['paginator' => $devices])
        </section>
    </div>
@endsection

@if ($canEdit)
@push('scripts')
<script>
    $(function () {
        function selectedIds() {
            return $('.dev-check:checked').map(function () { return this.value; }).get();
        }

        var applyAll = false;
        var pageCount = {{ $devices->count() }};
        var total = {{ $devices->total() }};

        function resetApplyAll() {
            applyAll = false;
            $('#bulkAllOn').prop('hidden', true);
            $('#bulkSelectAll').prop('hidden', false);
        }

        function refreshBulk() {
            var n = selectedIds().length;
            var visible = n > 0;
            $('#bulkCount').text(applyAll ? '' : (n + (n === 1 ? ' device selected' : ' devices selected')));
            $('#bulkForm').prop('hidden', !visible).toggleClass('is-visible', visible);
            $('#bulkAllWrap').prop('hidden', !(visible && n === pageCount && total > pageCount));
            if (!visible) { resetApplyAll(); }
        }

        $('#checkAll').on('change', function () {
            $('.dev-check').prop('checked', this.checked);
            $(this).prop('indeterminate', false);
            resetApplyAll();
            refreshBulk();
        });

        $(document).on('change', '.dev-check', function () {
            var all = $('.dev-check'), checked = $('.dev-check:checked');
            $('#checkAll')
                .prop('checked', all.length > 0 && checked.length === all.length)
                .prop('indeterminate', checked.length > 0 && checked.length < all.length);
            resetApplyAll();
            refreshBulk();
        });

        $('#bulkClear').on('click', function () {
            $('.dev-check, #checkAll').prop('checked', false);
            $('#checkAll').prop('indeterminate', false);
            resetApplyAll();
            refreshBulk();
        });

        $('#bulkSelectAll').on('click', function (e) {
            e.preventDefault();
            applyAll = true;
            $(this).prop('hidden', true);
            $('#bulkAllOn').prop('hidden', false);
            $('#bulkCount').text('');
        });

        $('#bulkSelectAllClear').on('click', function (e) {
            e.preventDefault();
            resetApplyAll();
            refreshBulk();
        });

        // Swap the value control to match the chosen field (combo for user, select otherwise).
        function syncBulkField() {
            var field = $('#bulkField').val();
            $('#bulkForm [data-field]').each(function () {
                var $el = $(this), match = $el.data('field') === field;
                $el.prop('hidden', !match);
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

        // Submit the checked IDs, or the all-matching flag and current filter.
        $('#bulkForm').on('submit', function (e) {
            e.preventDefault();
            var form = this;
            var ids = selectedIds();
            if (!applyAll && !ids.length) {
                return;
            }
            var message = applyAll
                ? 'Apply this change to all ' + total + ' devices matching the current filter?'
                : 'Apply this change to ' + ids.length + ' selected device(s)?';

            RD.confirm(message, {
                title: 'Apply bulk device change',
                action: 'Apply change',
                danger: false
            }).done(function (confirmed) {
                if (!confirmed) { return; }
                var $box = $('#bulkIds').empty();
                if (applyAll) {
                    $('<input type="hidden" name="all" value="1">').appendTo($box);
                    $('<input type="hidden" name="q">').val(@json($q)).appendTo($box);
                $('<input type="hidden" name="status">').val(@json($statusFilter)).appendTo($box);
                } else {
                    ids.forEach(function (id) {
                        $('<input type="hidden" name="ids[]">').val(id).appendTo($box);
                    });
                }
                window.HTMLFormElement.prototype.submit.call(form);
            });
        });
    });
</script>
@endpush
@endif
