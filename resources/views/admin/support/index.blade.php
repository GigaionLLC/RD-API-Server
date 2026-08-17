@extends('layouts.admin')

@section('title', 'Support report')

@push('styles')
    <style>
        #rd-report {
            width: 100%; min-height: 460px; resize: vertical;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px;
            line-height: 1.5; white-space: pre; overflow-wrap: normal; overflow-x: auto;
        }
    </style>
@endpush

@section('content')
    <header class="rd-page-header">
        <div class="rd-page-header__copy">
            <h1 class="rd-page-title">Support report</h1>
            <p class="rd-page-subtitle">
                Everything a maintainer usually has to ask for, in one paste — version, environment,
                configuration, remote-desktop diagnostics and the recent log.
            </p>
        </div>
        <div class="rd-page-header__actions">
            <a href="{{ route('admin.support.download') }}" class="rd-btn rd-btn--primary">
                <i class="ri-download-2-line" aria-hidden="true"></i> Download
            </a>
            <button type="button" class="rd-btn rd-btn--ghost" id="rd-report-copy">
                <i class="ri-file-copy-line" aria-hidden="true"></i> Copy
            </button>
        </div>
    </header>

    <div class="rd-card">
        <div class="rd-card__body">
            {{-- Shown before it can be sent, and deliberately in that order: this is going to
                 a public issue tracker, so the operator has to see exactly what they are about
                 to publish. Redaction reduces what escapes; it does not promise anything. --}}
            <p class="rd-help">
                <strong>Read this before you post it.</strong> Addresses, hostnames, keys and
                account names have been replaced with placeholders like <code>&lt;host-1&gt;</code>,
                and the same value always becomes the same placeholder so the report is still
                readable. It is a large reduction in what leaves your deployment, not a guarantee —
                you know your own environment, so give it a look.
            </p>

            <label class="visually-hidden" for="rd-report">Support report</label>
            <textarea class="rd-input" id="rd-report" readonly spellcheck="false">{{ $report }}</textarea>

            <p class="rd-help rd-mt-2">
                Attach it to an issue at
                <a href="https://github.com/GigaionLLC/RD-API-Server/issues" target="_blank" rel="noopener noreferrer">
                    github.com/GigaionLLC/RD-API-Server/issues</a>, with what you did and what you
                expected instead. Running <strong>v{{ $version }}</strong>.
            </p>
        </div>
    </div>
@endsection

@push('scripts')
<script>
(function () {
    var button = document.getElementById('rd-report-copy');
    var field = document.getElementById('rd-report');
    if (!button || !field) return;

    button.addEventListener('click', function () {
        // Clipboard access needs a secure context, so fall back to selecting the text and
        // letting the operator copy it themselves rather than failing silently.
        var done = function (ok) {
            button.innerHTML = ok
                ? '<i class="ri-check-line" aria-hidden="true"></i> Copied'
                : '<i class="ri-information-line" aria-hidden="true"></i> Selected — press Ctrl+C';
            window.setTimeout(function () {
                button.innerHTML = '<i class="ri-file-copy-line" aria-hidden="true"></i> Copy';
            }, 4000);
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(field.value).then(function () { done(true); }, function () {
                field.select();
                done(false);
            });
            return;
        }

        field.select();
        done(false);
    });
})();
</script>
@endpush
