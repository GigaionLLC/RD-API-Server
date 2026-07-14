@extends('layouts.admin')
@section('title', 'Settings')

@php
    // Build a plain {key: value} map in PHP (never inline arrays inside @json()).
    $settingsMap = [];
    foreach ($settings as $row) {
        $settingsMap[$row->key] = $row->value;
    }
@endphp

@section('content')
    <header class="rd-page-header">
        <div class="rd-page-header__copy">
            <div class="rd-page-header__eyebrow">System</div>
            <h1 class="rd-page-header__title">Settings</h1>
            <p class="rd-page-header__description">Manage server key-value configuration and outbound email delivery.</p>
        </div>
    </header>

    <div class="rd-form-grid rd-form-grid--2 rd-align-start">
        {{-- Generic system settings (key/value) --}}
        <section class="rd-card rd-card--quiet" aria-labelledby="system-settings-title">
            <div class="rd-card__header">
                <h2 class="rd-card__title" id="system-settings-title">System settings</h2>
            </div>
            <div class="rd-card__body">
                <form class="rd-liveform rd-stack rd-stack--lg" id="settingsForm" data-url="{{ route('admin.settings.update') }}" data-method="PUT">
                    <div class="rd-stack rd-stack--sm">
                        <div class="rd-label">Key / value pairs</div>
                        <div class="rd-stack rd-stack--sm" id="settingRows"></div>
                    </div>
                    <div class="rd-actions rd-actions--wrap">
                        <button type="button" class="rd-btn rd-btn--ghost" id="addSetting"><i class="ri-add-line"></i> Add setting</button>
                        <button type="submit" class="rd-btn rd-btn--primary rd-btn--save" data-state="idle">Save</button>
                    </div>
                    <span class="rd-help">Removing a row and saving deletes that setting.</span>
                </form>
            </div>
        </section>

        {{-- SMTP settings --}}
        <section class="rd-card rd-card--quiet" aria-labelledby="smtp-settings-title">
            <div class="rd-card__header">
                <h2 class="rd-card__title" id="smtp-settings-title">SMTP / Email</h2>
            </div>
            <div class="rd-card__body">
                <form class="rd-liveform rd-stack rd-stack--lg" data-url="{{ route('admin.settings.smtp') }}" data-method="PUT">
                    <div class="rd-form-grid rd-form-grid--2">
                    <div class="rd-field">
                        <label class="rd-label" for="host">Host</label>
                        <input class="rd-input" id="host" name="host" value="{{ $smtp['smtp.host'] }}" placeholder="smtp.example.com">
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="port">Port</label>
                        <input class="rd-input" id="port" name="port" type="number" value="{{ $smtp['smtp.port'] }}" placeholder="587">
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="username">Username</label>
                        <input class="rd-input" id="username" name="username" value="{{ $smtp['smtp.username'] }}" autocomplete="off">
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="password">Password</label>
                        <input class="rd-input" id="password" name="password" type="password" autocomplete="new-password" placeholder="{{ $smtp['smtp.password'] !== '' ? '•••••••• (unchanged)' : '' }}" aria-describedby="smtp-password-help">
                        <span class="rd-help" id="smtp-password-help">Leave blank to keep the existing password.</span>
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="from">From address</label>
                        <input class="rd-input" id="from" name="from" type="email" value="{{ $smtp['smtp.from'] }}" placeholder="noreply@example.com">
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="encryption">Encryption</label>
                        <select class="rd-select" id="encryption" name="encryption">
                            <option value=""     @selected($smtp['smtp.encryption'] === '')>Default</option>
                            <option value="tls"  @selected($smtp['smtp.encryption'] === 'tls')>TLS</option>
                            <option value="ssl"  @selected($smtp['smtp.encryption'] === 'ssl')>SSL</option>
                            <option value="none" @selected($smtp['smtp.encryption'] === 'none')>None</option>
                        </select>
                    </div>
                    </div>
                    <div class="rd-actions">
                        <button type="submit" class="rd-btn rd-btn--primary rd-btn--save" data-state="idle">Save SMTP</button>
                    </div>
                </form>
            </div>
        </section>
    </div>
@endsection

@push('scripts')
<script>
    $(function () {
        var settings = @json($settingsMap, JSON_UNESCAPED_SLASHES);

        function settingRow(key, value) {
            var $row = $(
                '<div class="rd-card rd-card--quiet" data-setting-row>' +
                '<div class="rd-card__body rd-stack rd-stack--sm">' +
                '<div class="rd-form-grid rd-form-grid--2">' +
                '<input class="rd-input rd-input--mono" name="setting_keys[]" placeholder="key" aria-label="Setting key">' +
                '<input class="rd-input rd-input--mono" name="setting_values[]" placeholder="value" aria-label="Setting value">' +
                '</div>' +
                '<div class="rd-actions rd-actions--end">' +
                '<button type="button" class="rd-btn rd-btn--ghost rd-set-remove" aria-label="Remove setting"><i class="ri-close-line" aria-hidden="true"></i> Remove</button>' +
                '</div></div></div>'
            );
            $row.find('input[name="setting_keys[]"]').val(key || '');
            $row.find('input[name="setting_values[]"]').val(value == null ? '' : String(value));
            return $row;
        }

        var $rows = $('#settingRows');
        var keys = Object.keys(settings);
        if (keys.length === 0) {
            $rows.append(settingRow('', ''));
        } else {
            keys.forEach(function (k) { $rows.append(settingRow(k, settings[k])); });
        }

        $('#addSetting').on('click', function () {
            $rows.append(settingRow('', ''));
            $('#settingsForm').trigger('change');
        });

        $rows.on('click', '.rd-set-remove', function () {
            $(this).closest('[data-setting-row]').remove();
            $('#settingsForm').trigger('change');
        });
    });
</script>
@endpush
