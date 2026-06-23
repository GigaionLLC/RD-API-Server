@extends('layouts.admin')
@section('title', 'Edit Strategy')

@php
    use App\Models\StrategyAssignment;

    $current = $strategy->options ?? [];
    $customOptions = $customOptions ?? [];
    $targetLabels = [
        StrategyAssignment::TARGET_DEVICE => 'Device',
        StrategyAssignment::TARGET_USER => 'User',
        StrategyAssignment::TARGET_DEVICE_GROUP => 'Device Group',
    ];
@endphp

@section('content')
    @include('admin.partials.flash')
    <div class="rd-breadcrumb">Control / Strategies / {{ $strategy->name }}</div>

    <style>
        .rd-settings { display:grid; grid-template-columns:190px 1fr; gap:0; border:1px solid var(--rd-border); border-radius:10px; overflow:hidden; }
        .rd-settings__nav { background:var(--rd-surface-2); border-right:1px solid var(--rd-border); padding:10px 8px; }
        .rd-snav { display:flex; align-items:center; gap:10px; width:100%; text-align:left; padding:10px 12px; border:0; border-radius:8px;
            background:none; color:var(--rd-text-muted); font-weight:600; font-size:14px; cursor:pointer; }
        .rd-snav:hover { background:var(--rd-surface-3); color:var(--rd-text-bright); }
        .rd-snav.active { background:var(--rd-primary); color:#fff; }
        .rd-settings__body { padding:18px 20px; max-height:none; }
        .rd-spane { display:none; }
        .rd-spane.active { display:block; }
        .rd-sec { background:var(--rd-surface-2); border:1px solid var(--rd-border); border-radius:9px; padding:6px 16px 12px; margin-bottom:14px; }
        .rd-sec__title { font-weight:700; color:var(--rd-text-bright); font-size:15px; padding:10px 0 4px; }
        .rd-sec__help { font-size:12px; color:var(--rd-text-muted); margin:0 0 4px; }
        .rd-optrow { display:flex; align-items:center; gap:14px; padding:8px 0; border-top:1px solid var(--rd-border); }
        .rd-optrow:first-of-type { border-top:none; }
        .rd-optrow__label { flex:1; font-size:13px; min-width:0; color:var(--rd-text-bright); }
        .rd-optrow__key { font-size:11px; color:var(--rd-text-muted); font-family:monospace; }
        .rd-optrow__ctrl { width:240px; flex:none; }
        .rd-optrow__ctrl .rd-select, .rd-optrow__ctrl .rd-input { width:100%; }
        .rd-opt-set { box-shadow:inset 3px 0 0 var(--rd-primary); }
        .rd-stoolbar { display:flex; align-items:center; gap:8px; margin-bottom:12px; flex-wrap:wrap; }
    </style>

    {{-- Meta + options (live-save) --}}
    <div class="rd-card" style="margin-bottom:18px;">
        <div class="rd-card__header">
            <h3 class="rd-card__title">{{ $strategy->name }}</h3>
            <a href="{{ route('admin.strategies.index') }}" class="rd-btn rd-btn--ghost"><i class="ri-arrow-left-line"></i> Back</a>
        </div>
        <div class="rd-card__body">
            <form class="rd-liveform" id="strategyForm" data-url="{{ route('admin.strategies.update', $strategy) }}" data-method="PUT">
                <div class="rd-grid rd-grid--3" style="align-items:start;margin-bottom:14px;">
                    <div class="rd-field">
                        <label class="rd-label" for="name">Name</label>
                        <input class="rd-input" id="name" name="name" value="{{ $strategy->name }}" required>
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="note">Note</label>
                        <input class="rd-input" id="note" name="note" value="{{ $strategy->note }}">
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="enabled">Status</label>
                        <select class="rd-select" id="enabled" name="enabled">
                            <option value="1" @selected($strategy->enabled)>Enabled</option>
                            <option value="0" @selected(! $strategy->enabled)>Disabled</option>
                        </select>
                    </div>
                </div>

                <p class="rd-help" style="margin:0 0 12px;">
                    Laid out like the RustDesk client's Settings. Leave a control on <strong>Default</strong>
                    to keep the client's own value — only changed options are pushed.
                </p>

                <div class="rd-settings">
                    {{-- Left sub-nav (mirrors the client Settings sidebar) --}}
                    <div class="rd-settings__nav">
                        @foreach ($tabs as $i => $tab)
                            <button type="button" class="rd-snav @if($i === 0) active @endif" data-tab="{{ $tab['key'] }}">
                                <i class="{{ $tab['icon'] ?? 'ri-settings-3-line' }}"></i> {{ $tab['label'] }}
                            </button>
                        @endforeach
                        <button type="button" class="rd-snav" data-tab="custom"><i class="ri-terminal-box-line"></i> Custom</button>
                    </div>

                    {{-- Panes --}}
                    <div class="rd-settings__body">
                        <div class="rd-stoolbar">
                            <span class="rd-muted" style="font-size:12px;">Apply to this tab:</span>
                            <button type="button" class="rd-btn rd-btn--ghost" data-setall="Y"><i class="ri-toggle-line"></i> All on</button>
                            <button type="button" class="rd-btn rd-btn--ghost" data-setall="N"><i class="ri-toggle-line"></i> All off</button>
                            <button type="button" class="rd-btn rd-btn--ghost" data-setall="D"><i class="ri-restart-line"></i> All default</button>
                        </div>
                        @foreach ($tabs as $i => $tab)
                            <div class="rd-spane @if($i === 0) active @endif" data-pane="{{ $tab['key'] }}">
                                @foreach ($tab['sections'] as $section)
                                    <div class="rd-sec">
                                        <div class="rd-sec__title">{{ $section['label'] }}</div>
                                        @if (!empty($section['help']))
                                            <p class="rd-sec__help">{{ $section['help'] }}</p>
                                        @endif
                                        @foreach ($section['options'] as $opt)
                                            @php $val = (string) ($current[$opt['key']] ?? ''); @endphp
                                            <div class="rd-optrow">
                                                <div class="rd-optrow__label">
                                                    {{ $opt['label'] }}
                                                    <div class="rd-optrow__key">{{ $opt['key'] }}</div>
                                                </div>
                                                <div class="rd-optrow__ctrl">
                                                    @if ($opt['type'] === 'toggle')
                                                        <select class="rd-select @if($val!=='') rd-opt-set @endif" name="opt[{{ $opt['key'] }}]">
                                                            <option value="" @selected($val==='')>Default</option>
                                                            <option value="Y" @selected($val==='Y')>On</option>
                                                            <option value="N" @selected($val==='N')>Off</option>
                                                        </select>
                                                    @elseif ($opt['type'] === 'select')
                                                        <select class="rd-select @if($val!=='') rd-opt-set @endif" name="opt[{{ $opt['key'] }}]">
                                                            @foreach ($opt['choices'] as $cv => $cl)
                                                                <option value="{{ $cv }}" @selected($val===(string)$cv)>{{ $cl }}</option>
                                                            @endforeach
                                                        </select>
                                                    @elseif ($opt['type'] === 'number')
                                                        <input class="rd-input @if($val!=='') rd-opt-set @endif" type="number" min="0"
                                                               name="opt[{{ $opt['key'] }}]" value="{{ $val }}" placeholder="Default">
                                                    @else
                                                        <input class="rd-input @if($val!=='') rd-opt-set @endif" type="text"
                                                               name="opt[{{ $opt['key'] }}]" value="{{ $val }}" placeholder="Default">
                                                    @endif
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>
                        @endforeach

                        {{-- Custom (non-catalog) options --}}
                        <div class="rd-spane" data-pane="custom">
                            <div class="rd-sec">
                                <div class="rd-sec__title">Custom options</div>
                                <p class="rd-sec__help">Any other <code>config_options</code> key the client supports. Pushed verbatim.</p>
                                <div id="optionRows"></div>
                                <button type="button" class="rd-btn rd-btn--ghost" id="addOption" style="margin:6px 0 0;"><i class="ri-add-line"></i> Add custom option</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="rd-row" style="margin-top:16px;">
                    <button type="submit" class="rd-btn rd-btn--primary rd-btn--save" data-state="idle">Save</button>
                    <span class="rd-help" style="margin-left:12px;">Saving bumps the modified timestamp; clients pull within one heartbeat.</span>
                </div>
            </form>
        </div>
    </div>

    {{-- Assignments --}}
    <div class="rd-card">
        <div class="rd-card__header">
            <h3 class="rd-card__title">Assignments</h3>
        </div>
        <div class="rd-card__body">
            <form method="POST" action="{{ route('admin.strategies.assignments.store', $strategy) }}" class="rd-row" style="margin-bottom:16px;flex-wrap:wrap;">
                @csrf
                <select class="rd-select" name="target_type" id="targetType" style="width:150px;">
                    <option value="device">Device</option>
                    <option value="user">User</option>
                    <option value="device_group">Device Group</option>
                </select>

                {{-- Device: searchable combobox — scales to thousands of clients. --}}
                <div class="rd-combo" data-target="device" data-url="{{ route('admin.devices.search') }}" style="width:280px;">
                    <input type="hidden" name="target_id">
                    <input type="text" class="rd-input rd-combo__input" placeholder="Search device by id / host / alias…" autocomplete="off">
                    <div class="rd-combo__menu"></div>
                </div>
                {{-- User: searchable combobox. --}}
                <div class="rd-combo" data-target="user" data-url="{{ route('admin.users.search') }}" style="width:240px;display:none;">
                    <input type="hidden" name="target_id" disabled>
                    <input type="text" class="rd-input rd-combo__input" placeholder="Search user…" autocomplete="off">
                    <div class="rd-combo__menu"></div>
                </div>
                <select class="rd-select" name="target_id" data-target="device_group" style="width:240px;display:none;" disabled>
                    @foreach ($deviceGroups as $g)
                        <option value="{{ $g->id }}">{{ $g->name }}</option>
                    @endforeach
                </select>

                <button type="submit" class="rd-btn rd-btn--primary"><i class="ri-add-line"></i> Assign</button>
            </form>

            <table class="rd-table">
                <thead><tr><th>Type</th><th>Target</th><th style="text-align:right;">Action</th></tr></thead>
                <tbody>
                @forelse ($strategy->assignments as $a)
                    @php
                        $label = match ($a->target_type) {
                            'device' => optional($deviceMap->get($a->target_id))->rustdesk_id ?? ('#'.$a->target_id),
                            'user' => optional($userMap->get($a->target_id))->username ?? ('#'.$a->target_id),
                            'device_group' => optional($deviceGroupMap->get($a->target_id))->name ?? ('#'.$a->target_id),
                            default => '#'.$a->target_id,
                        };
                    @endphp
                    <tr>
                        <td><span class="rd-badge rd-badge--muted">{{ $targetLabels[$a->target_type] ?? $a->target_type }}</span></td>
                        <td class="rd-muted">{{ $label }}</td>
                        <td style="text-align:right;">
                            <form method="POST" action="{{ route('admin.strategies.assignments.destroy', $a) }}" class="m-0">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="rd-btn rd-btn--danger" data-confirm="Remove this assignment?"><i class="ri-delete-bin-line"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="rd-muted" style="text-align:center;padding:20px;">No assignments yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    $(function () {
        // Custom (non-catalog) options only — catalog options render as native controls.
        var customOptions = @json((object) $customOptions, JSON_UNESCAPED_SLASHES);

        function optionRow(key, value) {
            var $row = $(
                '<div class="rd-row" style="margin-bottom:8px;">' +
                '<input class="rd-input" name="option_keys[]" placeholder="key" style="flex:1;">' +
                '<input class="rd-input" name="option_values[]" placeholder="value" style="flex:1;">' +
                '<button type="button" class="rd-btn rd-btn--ghost rd-opt-remove" title="Remove"><i class="ri-close-line"></i></button>' +
                '</div>'
            );
            $row.find('input[name="option_keys[]"]').val(key || '');
            $row.find('input[name="option_values[]"]').val(value == null ? '' : String(value));
            return $row;
        }

        var $rows = $('#optionRows');
        Object.keys(customOptions).forEach(function (k) { $rows.append(optionRow(k, customOptions[k])); });

        $('#addOption').on('click', function () {
            $rows.append(optionRow('', ''));
            $('#strategyForm').trigger('change');
        });

        $rows.on('click', '.rd-opt-remove', function () {
            $(this).closest('.rd-row').remove();
            $('#strategyForm').trigger('change');
        });

        // Settings sub-nav switching.
        $('.rd-snav').on('click', function () {
            var tab = $(this).data('tab');
            $('.rd-snav').removeClass('active');
            $(this).addClass('active');
            $('.rd-spane').removeClass('active').filter('[data-pane="' + tab + '"]').addClass('active');
        });

        // Highlight controls that override the client default.
        $('#strategyForm').on('change', 'select[name^="opt["], input[name^="opt["]', function () {
            $(this).toggleClass('rd-opt-set', $(this).val() !== '');
        });

        // Bulk set every option in the ACTIVE tab: All on (Y) / All off (N) / All default ("").
        $('.rd-stoolbar [data-setall]').on('click', function () {
            var mode = String($(this).data('setall'));      // 'Y' | 'N' | 'D'
            var $pane = $('.rd-spane.active');
            $pane.find('select[name^="opt["]').each(function () {
                var $s = $(this);
                if (mode === 'D') { $s.val(''); }
                else if ($s.find('option[value="' + mode + '"]').length) { $s.val(mode); } // toggles only
                $s.trigger('change');
            });
            if (mode === 'D') {
                $pane.find('input[name^="opt["]').each(function () { $(this).val('').trigger('change'); });
            }
            $('#strategyForm').trigger('change');
        });

        // Assignment target switcher: show + enable only the relevant control (combo or select).
        function syncTarget() {
            var type = $('#targetType').val();
            $('[data-target]').each(function () {
                var $el = $(this), match = $el.data('target') === type;
                $el.toggle(match);
                if ($el.is('select')) {
                    $el.prop('disabled', !match);
                } else { // combobox: its hidden input is the submitted field
                    var $hidden = $el.find('input[type="hidden"]');
                    $hidden.prop('disabled', !match);
                    if (!match) { $hidden.val(''); $el.find('.rd-combo__input').val(''); }
                }
            });
        }
        $('#targetType').on('change', syncTarget);
        syncTarget();
    });
</script>
@endpush
