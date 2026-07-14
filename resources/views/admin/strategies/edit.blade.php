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
    <div class="rd-stack rd-stack--lg">
        <header class="rd-page-header">
            <div class="rd-page-header__copy">
                <div class="rd-breadcrumb" aria-label="Breadcrumb">Policies &amp; Rollout / Strategies / {{ $strategy->name }}</div>
                <p class="rd-page-header__eyebrow">Client policy</p>
                <h1 class="rd-page-header__title">{{ $strategy->name }}</h1>
                <p class="rd-page-header__description">Set client defaults, make intentional overrides, and control which devices or people receive this strategy.</p>
            </div>
            <div class="rd-page-header__actions">
                <span class="rd-badge rd-badge--{{ $strategy->enabled ? 'online' : 'muted' }}">
                    <span class="dot" aria-hidden="true"></span>{{ $strategy->enabled ? 'Enabled' : 'Disabled' }}
                </span>
                <a href="{{ route('admin.strategies.index') }}" class="rd-btn rd-btn--ghost"><i class="ri-arrow-left-line" aria-hidden="true"></i> Back to strategies</a>
            </div>
        </header>

        <section class="rd-card" aria-labelledby="strategy-settings-title">
            <div class="rd-card__header">
                <div>
                    <p class="rd-card__eyebrow">Configuration</p>
                    <h2 class="rd-card__title" id="strategy-settings-title">Client settings</h2>
                </div>
            </div>
            <div class="rd-card__body">
                <form class="rd-liveform rd-stack rd-stack--md" id="strategyForm" data-url="{{ route('admin.strategies.update', $strategy) }}" data-method="PUT">
                    <div class="rd-form-grid rd-form-grid--3">
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

                    <div class="rd-callout rd-callout--info">
                        <i class="ri-information-line" aria-hidden="true"></i>
                        <div>
                            <strong>Default means the client stays in control.</strong>
                            <p>Only changed values are pushed. These values remain user-editable client defaults; locked override settings require a signed custom client.</p>
                            <p>To effectively lock a setting here, set it, then use the matching <strong>Hide…</strong> option under <strong>Client UI</strong> and turn off <strong>Enable remote configuration modification</strong>.</p>
                        </div>
                    </div>

                    <div class="rd-strategy-editor">
                        <div class="rd-strategy-editor__nav" role="tablist" aria-label="Strategy setting categories" aria-orientation="vertical">
                            @foreach ($tabs as $i => $tab)
                                <button type="button"
                                        class="rd-strategy-tab @if($i === 0) is-active @endif"
                                        id="strategy-tab-{{ $tab['key'] }}"
                                        role="tab"
                                        aria-selected="{{ $i === 0 ? 'true' : 'false' }}"
                                        aria-controls="strategy-pane-{{ $tab['key'] }}"
                                        tabindex="{{ $i === 0 ? '0' : '-1' }}"
                                        data-tab="{{ $tab['key'] }}">
                                    <i class="{{ $tab['icon'] ?? 'ri-settings-3-line' }}" aria-hidden="true"></i>
                                    <span>{{ $tab['label'] }}</span>
                                </button>
                            @endforeach
                            <button type="button"
                                    class="rd-strategy-tab"
                                    id="strategy-tab-custom"
                                    role="tab"
                                    aria-selected="false"
                                    aria-controls="strategy-pane-custom"
                                    tabindex="-1"
                                    data-tab="custom">
                                <i class="ri-terminal-box-line" aria-hidden="true"></i><span>Custom</span>
                            </button>
                        </div>

                        <div class="rd-strategy-editor__body">
                            <div class="rd-toolbar rd-strategy-toolbar" aria-label="Bulk controls for the current category">
                                <div class="rd-toolbar__group">
                                    <span class="rd-muted">Apply to this category:</span>
                                    <button type="button" class="rd-btn rd-btn--ghost" data-setall="Y"><i class="ri-toggle-line" aria-hidden="true"></i> All on</button>
                                    <button type="button" class="rd-btn rd-btn--ghost" data-setall="N"><i class="ri-toggle-line" aria-hidden="true"></i> All off</button>
                                    <button type="button" class="rd-btn rd-btn--ghost" data-setall="D"><i class="ri-restart-line" aria-hidden="true"></i> All default</button>
                                </div>
                            </div>

                            @foreach ($tabs as $i => $tab)
                                <div class="rd-strategy-pane @if($i === 0) is-active @endif"
                                     id="strategy-pane-{{ $tab['key'] }}"
                                     role="tabpanel"
                                     aria-labelledby="strategy-tab-{{ $tab['key'] }}"
                                     data-pane="{{ $tab['key'] }}"
                                     @if($i !== 0) hidden @endif>
                                    <div class="rd-stack rd-stack--md">
                                        @foreach ($tab['sections'] as $section)
                                            <section class="rd-strategy-section">
                                                <header class="rd-strategy-section__header">
                                                    <div>
                                                        <h3 class="rd-strategy-section__title">{{ $section['label'] }}</h3>
                                                        @if (!empty($section['help']))
                                                            <p class="rd-strategy-section__help">{{ $section['help'] }}</p>
                                                        @endif
                                                    </div>
                                                </header>
                                                <div class="rd-strategy-section__options">
                                                    @foreach ($section['options'] as $opt)
                                                        @php
                                                            $val = (string) ($current[$opt['key']] ?? '');
                                                            $inputId = 'strategy-opt-'.$opt['key'];
                                                        @endphp
                                                        <div class="rd-strategy-option">
                                                            <label class="rd-strategy-option__label" for="{{ $inputId }}">
                                                                <span>{{ $opt['label'] }}</span>
                                                                <code class="rd-strategy-option__key">{{ $opt['key'] }}</code>
                                                            </label>
                                                            <div class="rd-strategy-option__control">
                                                                @if ($opt['type'] === 'toggle')
                                                                    <select class="rd-select @if($val !== '') rd-opt-set @endif" id="{{ $inputId }}" name="opt[{{ $opt['key'] }}]">
                                                                        <option value="" @selected($val === '')>Default</option>
                                                                        <option value="Y" @selected($val === 'Y')>On</option>
                                                                        <option value="N" @selected($val === 'N')>Off</option>
                                                                    </select>
                                                                @elseif ($opt['type'] === 'select')
                                                                    <select class="rd-select @if($val !== '') rd-opt-set @endif" id="{{ $inputId }}" name="opt[{{ $opt['key'] }}]">
                                                                        @foreach ($opt['choices'] as $choiceValue => $choiceLabel)
                                                                            <option value="{{ $choiceValue }}" @selected($val === (string) $choiceValue)>{{ $choiceLabel }}</option>
                                                                        @endforeach
                                                                    </select>
                                                                @elseif ($opt['type'] === 'number')
                                                                    <input class="rd-input @if($val !== '') rd-opt-set @endif" id="{{ $inputId }}" type="number" min="0" name="opt[{{ $opt['key'] }}]" value="{{ $val }}" placeholder="Default">
                                                                @else
                                                                    <input class="rd-input @if($val !== '') rd-opt-set @endif" id="{{ $inputId }}" type="text" name="opt[{{ $opt['key'] }}]" value="{{ $val }}" placeholder="Default">
                                                                @endif
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </section>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach

                            <div class="rd-strategy-pane" id="strategy-pane-custom" role="tabpanel" aria-labelledby="strategy-tab-custom" data-pane="custom" hidden>
                                <section class="rd-strategy-section">
                                    <header class="rd-strategy-section__header">
                                        <div>
                                            <h3 class="rd-strategy-section__title">Custom options</h3>
                                            <p class="rd-strategy-section__help">Add any other <code>config_options</code> key supported by the client. Values are pushed verbatim.</p>
                                        </div>
                                    </header>
                                    <div id="optionRows" class="rd-stack rd-stack--sm"></div>
                                    <div class="rd-actions rd-actions--end">
                                        <button type="button" class="rd-btn rd-btn--ghost" id="addOption"><i class="ri-add-line" aria-hidden="true"></i> Add custom option</button>
                                    </div>
                                </section>
                            </div>
                        </div>
                    </div>

                    <div class="rd-strategy-savebar">
                        <p class="rd-help">Saving updates the modified timestamp; clients pull changes within one heartbeat.</p>
                        <button type="submit" class="rd-btn rd-btn--primary rd-btn--save" data-state="idle">Save</button>
                    </div>
                </form>
            </div>
        </section>

        <section class="rd-card rd-card--flush" aria-labelledby="strategy-assignments-title">
            <div class="rd-card__header">
                <div>
                    <p class="rd-card__eyebrow">Rollout scope</p>
                    <h2 class="rd-card__title" id="strategy-assignments-title">Assignments</h2>
                </div>
                <span class="rd-badge rd-badge--muted">{{ $strategy->assignments->count() }} {{ Str::plural('target', $strategy->assignments->count()) }}</span>
            </div>

            <form method="POST" action="{{ route('admin.strategies.assignments.store', $strategy) }}" class="rd-toolbar rd-strategy-assignment-form">
                @csrf
                <div class="rd-toolbar__group rd-grow">
                    <label class="visually-hidden" for="targetType">Assignment type</label>
                    <select class="rd-select rd-toolbar__control" name="target_type" id="targetType">
                        <option value="device">Device</option>
                        <option value="user">User</option>
                        <option value="device_group">Device Group</option>
                    </select>

                    <div class="rd-combo rd-max-w-lg" data-target="device" data-url="{{ route('admin.devices.search') }}">
                        <input type="hidden" name="target_id">
                        <input type="text" class="rd-input rd-combo__input" aria-label="Device" placeholder="Search device by ID, host, or alias…" autocomplete="off">
                        <div class="rd-combo__menu"></div>
                    </div>
                    <div class="rd-combo rd-max-w-md" data-target="user" data-url="{{ route('admin.users.search') }}" hidden>
                        <input type="hidden" name="target_id" disabled>
                        <input type="text" class="rd-input rd-combo__input" aria-label="User" placeholder="Search user…" autocomplete="off">
                        <div class="rd-combo__menu"></div>
                    </div>
                    <select class="rd-select rd-max-w-md" name="target_id" data-target="device_group" aria-label="Device group" hidden disabled>
                        @foreach ($deviceGroups as $group)
                            <option value="{{ $group->id }}">{{ $group->name }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="rd-btn rd-btn--primary"><i class="ri-add-line" aria-hidden="true"></i> Assign</button>
                </div>
            </form>

            <div class="rd-table-wrap" role="region" tabindex="0" aria-label="Strategy assignments table">
                <table class="rd-table rd-table--compact">
                    <thead><tr><th>Type</th><th>Target</th><th><span class="visually-hidden">Action</span></th></tr></thead>
                    <tbody>
                    @forelse ($strategy->assignments as $assignment)
                        @php
                            $label = match ($assignment->target_type) {
                                'device' => optional($deviceMap->get($assignment->target_id))->rustdesk_id ?? ('#'.$assignment->target_id),
                                'user' => optional($userMap->get($assignment->target_id))->username ?? ('#'.$assignment->target_id),
                                'device_group' => optional($deviceGroupMap->get($assignment->target_id))->name ?? ('#'.$assignment->target_id),
                                default => '#'.$assignment->target_id,
                            };
                        @endphp
                        <tr>
                            <td><span class="rd-badge rd-badge--muted">{{ $targetLabels[$assignment->target_type] ?? $assignment->target_type }}</span></td>
                            <td class="rd-table__primary">{{ $label }}</td>
                            <td>
                                <div class="rd-table__actions">
                                    <form method="POST" action="{{ route('admin.strategies.assignments.destroy', $assignment) }}" class="m-0">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="rd-icon-btn rd-icon-btn--danger" aria-label="Remove assignment for {{ $label }}" title="Remove assignment" data-confirm="Remove this assignment?"><i class="ri-delete-bin-line" aria-hidden="true"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3">
                                <div class="rd-empty">
                                    <i class="ri-node-tree rd-empty__icon" aria-hidden="true"></i>
                                    <p class="rd-empty__title">No assignments yet</p>
                                    <p class="rd-empty__body">Assign this strategy to a device, user, or device group to begin rollout.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection

@push('scripts')
<script>
    $(function () {
        var customOptions = @json((object) $customOptions, JSON_UNESCAPED_SLASHES);

        function optionRow(key, value) {
            var $row = $(
                '<div class="rd-strategy-custom-row">' +
                '<input class="rd-input rd-mono" name="option_keys[]" aria-label="Custom option key" placeholder="Option key">' +
                '<input class="rd-input rd-mono" name="option_values[]" aria-label="Custom option value" placeholder="Value">' +
                '<button type="button" class="rd-icon-btn rd-opt-remove" aria-label="Remove custom option" title="Remove"><i class="ri-close-line" aria-hidden="true"></i></button>' +
                '</div>'
            );
            $row.find('input[name="option_keys[]"]').val(key || '');
            $row.find('input[name="option_values[]"]').val(value == null ? '' : String(value));
            return $row;
        }

        var $rows = $('#optionRows');
        Object.keys(customOptions).forEach(function (key) { $rows.append(optionRow(key, customOptions[key])); });

        $('#addOption').on('click', function () {
            $rows.append(optionRow('', ''));
            $('#strategyForm').trigger('change');
            $rows.find('input[name="option_keys[]"]').last().trigger('focus');
        });

        $rows.on('click', '.rd-opt-remove', function () {
            $(this).closest('.rd-strategy-custom-row').remove();
            $('#strategyForm').trigger('change');
        });

        function activateTab($tab, focus) {
            var tab = String($tab.data('tab'));
            $('.rd-strategy-tab').removeClass('is-active').attr('aria-selected', 'false').attr('tabindex', '-1');
            $tab.addClass('is-active').attr('aria-selected', 'true').attr('tabindex', '0');
            $('.rd-strategy-pane').removeClass('is-active').prop('hidden', true)
                .filter('[data-pane="' + tab + '"]').addClass('is-active').prop('hidden', false);
            $('.rd-strategy-toolbar').prop('hidden', tab === 'custom');
            if (focus) { $tab.trigger('focus'); }
        }

        $('.rd-strategy-tab').on('click', function () { activateTab($(this), false); });
        $('.rd-strategy-editor__nav').on('keydown', '.rd-strategy-tab', function (event) {
            var $tabs = $('.rd-strategy-tab');
            var index = $tabs.index(this);
            var next = null;
            if (event.key === 'ArrowDown' || event.key === 'ArrowRight') { next = (index + 1) % $tabs.length; }
            if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') { next = (index - 1 + $tabs.length) % $tabs.length; }
            if (event.key === 'Home') { next = 0; }
            if (event.key === 'End') { next = $tabs.length - 1; }
            if (next !== null) {
                event.preventDefault();
                activateTab($tabs.eq(next), true);
            }
        });

        $('#strategyForm').on('change', 'select[name^="opt["], input[name^="opt["]', function () {
            $(this).toggleClass('rd-opt-set', $(this).val() !== '');
        });

        $('.rd-strategy-toolbar [data-setall]').on('click', function () {
            var mode = String($(this).data('setall'));
            var $pane = $('.rd-strategy-pane.is-active');
            $pane.find('select[name^="opt["]').each(function () {
                var $select = $(this);
                if (mode === 'D') { $select.val(''); }
                else if ($select.find('option[value="' + mode + '"]').length) { $select.val(mode); }
                $select.trigger('change');
            });
            if (mode === 'D') {
                $pane.find('input[name^="opt["]').each(function () { $(this).val('').trigger('change'); });
            }
            $('#strategyForm').trigger('change');
        });

        function syncTarget() {
            var type = $('#targetType').val();
            $('[data-target]').each(function () {
                var $el = $(this), match = $el.data('target') === type;
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

        $('#targetType').on('change', syncTarget);
        syncTarget();
    });
</script>
@endpush
