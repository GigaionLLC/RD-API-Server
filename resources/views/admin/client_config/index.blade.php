@extends('layouts.admin')
@section('title', 'Client Config')

@section('content')
    <div class="rd-breadcrumb">Management / Client Config</div>

    <style>
        .rd-cc { display:grid; grid-template-columns:1fr 300px; gap:18px; align-items:start; }
        .rd-cc__qr { background:#fff; border-radius:10px; padding:14px; display:flex; flex-direction:column; align-items:center; gap:8px; }
        .rd-cc__qr svg { width:240px; height:240px; }
        .rd-out { display:flex; gap:8px; align-items:flex-start; }
        .rd-out textarea { width:100%; font-family:monospace; font-size:12px; resize:vertical; min-height:62px;
            background:var(--rd-surface-2); color:var(--rd-text); border:1px solid var(--rd-border); border-radius:8px; padding:9px 11px; }
        @media (max-width: 900px) { .rd-cc { grid-template-columns:1fr; } }
    </style>

    <div class="rd-card" style="margin-bottom:18px;">
        <div class="rd-card__header"><h3 class="rd-card__title"><i class="ri-qr-code-line"></i> Client Config generator</h3></div>
        <div class="rd-card__body">
            <p class="rd-help" style="margin-top:0;">
                Pre-configure RustDesk clients so users don't enter server details by hand. Fill in your
                servers, then share the QR (mobile), the config string (desktop → <em>Import Server Config</em>),
                the command line, or rename the installer.
            </p>
            <p class="rd-help" style="margin-top:0;">
                Fields are pre-filled from this server's config (<code>RUSTDESK_ID_SERVER</code>,
                <code>RUSTDESK_RELAY_SERVER</code>, <code>RUSTDESK_KEY</code> / <code>RUSTDESK_KEY_FILE</code>,
                <code>RUSTDESK_API_SERVER</code>). Override any of them below.
            </p>
            <form method="GET" action="{{ route('admin.client-config.index') }}">
                <div class="rd-grid rd-grid--2" style="align-items:start;">
                    <div class="rd-field">
                        <label class="rd-label" for="host">ID / Rendezvous server (host)</label>
                        <input class="rd-input" id="host" name="host" value="{{ $host }}" placeholder="rustdesk.example.com" required>
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="key">Public key</label>
                        <input class="rd-input" id="key" name="key" value="{{ $key }}" placeholder="hbbs key (…=)">
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="relay">Relay server</label>
                        <input class="rd-input" id="relay" name="relay" value="{{ $relay }}" placeholder="rustdesk.example.com (optional)">
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="api">API server</label>
                        <input class="rd-input" id="api" name="api" value="{{ $api }}" placeholder="https://api.example.com">
                    </div>
                </div>
                <button type="submit" class="rd-btn rd-btn--primary"><i class="ri-magic-line"></i> Generate</button>
            </form>
        </div>
    </div>

    @if ($configString)
        <div class="rd-cc">
            <div>
                <div class="rd-card" style="margin-bottom:18px;">
                    <div class="rd-card__header"><h3 class="rd-card__title">Config string</h3></div>
                    <div class="rd-card__body">
                        <p class="rd-help" style="margin-top:0;">Desktop client → Settings → Network → <strong>ID/Relay server</strong> → <strong>Import Server Config</strong> (paste), or <code>rustdesk --config &lt;string&gt;</code>.</p>
                        <div class="rd-out">
                            <textarea readonly id="cfgString">{{ $configString }}</textarea>
                            <button type="button" class="rd-btn rd-btn--ghost rd-copy" data-copy="#cfgString"><i class="ri-file-copy-line"></i></button>
                        </div>
                    </div>
                </div>

                <div class="rd-card" style="margin-bottom:18px;">
                    <div class="rd-card__header"><h3 class="rd-card__title">Command line (<code>--config</code>)</h3></div>
                    <div class="rd-card__body">
                        <p class="rd-help" style="margin-top:0;">Run once on an already-installed client to apply the server config.</p>
                        @php
                            $cli = [
                                'Windows' => 'ri-windows-fill',
                                'macOS' => 'ri-apple-fill',
                                'Linux' => 'ri-ubuntu-fill',
                            ];
                            $cmds = [
                                'Windows' => '"%ProgramFiles%\\RustDesk\\rustdesk.exe" --config '.$configString,
                                'macOS' => '/Applications/RustDesk.app/Contents/MacOS/rustdesk --config '.$configString,
                                'Linux' => 'rustdesk --config '.$configString,
                            ];
                        @endphp
                        @foreach ($cmds as $os => $cmd)
                            <label class="rd-label" style="margin-top:6px;"><i class="{{ $cli[$os] }}"></i> {{ $os }}</label>
                            <div class="rd-out">
                                <textarea readonly id="cli{{ $os }}">{{ $cmd }}</textarea>
                                <button type="button" class="rd-btn rd-btn--ghost rd-copy" data-copy="#cli{{ $os }}"><i class="ri-file-copy-line"></i></button>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="rd-card">
                    <div class="rd-card__header"><h3 class="rd-card__title">Renamed Windows installer</h3></div>
                    <div class="rd-card__body">
                        <p class="rd-help" style="margin-top:0;">Rename the downloaded <code>rustdesk-*.exe</code> to this filename; on first run it auto-applies the config.</p>
                        <div class="rd-out">
                            <textarea readonly id="cfgInstaller">{{ $installer }}</textarea>
                            <button type="button" class="rd-btn rd-btn--ghost rd-copy" data-copy="#cfgInstaller"><i class="ri-file-copy-line"></i></button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="rd-card">
                <div class="rd-card__header"><h3 class="rd-card__title">Mobile QR</h3></div>
                <div class="rd-card__body">
                    <div class="rd-cc__qr">{!! $qrSvg !!}</div>
                    <p class="rd-help" style="text-align:center;margin-bottom:0;">Mobile app → <strong>＋</strong> → scan to import the server config.</p>
                </div>
            </div>
        </div>
    @endif
@endsection

@push('scripts')
<script>
    $(function () {
        $('.rd-copy').on('click', function () {
            var text = $($(this).data('copy')).val();
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function () { RD.toast('Copied', 'success'); });
            } else {
                var el = $($(this).data('copy'))[0]; el.select(); document.execCommand('copy');
                RD.toast('Copied', 'success');
            }
        });
    });
</script>
@endpush
