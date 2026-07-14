@extends('layouts.admin')
@section('title', 'Client Config')

@section('content')
    <div class="rd-stack rd-stack--lg">
        <header class="rd-page-header">
            <div class="rd-page-header__copy">
                <div class="rd-breadcrumb" aria-label="Breadcrumb">Policies &amp; Rollout / Client Config</div>
                <p class="rd-page-header__eyebrow">Deployment assistant</p>
                <h1 class="rd-page-header__title">Client Config</h1>
                <p class="rd-page-header__description">Configure once, generate deployment material, and distribute the right format to each RustDesk client.</p>
            </div>
        </header>

        <ol class="rd-config-steps" aria-label="Client configuration workflow">
            <li class="rd-config-steps__item {{ $configString ? 'is-complete' : 'is-current' }}"><div><strong>Configure</strong><small>Server endpoints and key</small></div></li>
            <li class="rd-config-steps__item {{ $configString ? 'is-complete' : '' }}"><div><strong>Generate</strong><small>Build client-ready outputs</small></div></li>
            <li class="rd-config-steps__item {{ $configString ? 'is-current' : '' }}"><div><strong>Distribute</strong><small>Copy, scan, or deploy</small></div></li>
        </ol>

        <section class="rd-card" aria-labelledby="client-config-generator-title">
            <div class="rd-card__header">
                <div>
                    <p class="rd-card__eyebrow">Step 1</p>
                    <h2 class="rd-card__title" id="client-config-generator-title">Configure deployment</h2>
                </div>
            </div>
            <div class="rd-card__body rd-stack rd-stack--md">
                <div class="rd-callout rd-callout--info">
                    <i class="ri-information-line" aria-hidden="true"></i>
                    <p>Values are prefilled from this server's RustDesk configuration. Review them here without changing the stored server settings, then generate formats for mobile, desktop, command-line, or managed installation.</p>
                </div>

                <form method="GET" action="{{ route('admin.client-config.index') }}" class="rd-stack rd-stack--md">
                    <div class="rd-form-grid rd-form-grid--2">
                        <div class="rd-field">
                            <label class="rd-label" for="host">ID / Rendezvous server</label>
                            <input class="rd-input" id="host" name="host" value="{{ $host }}" placeholder="rustdesk.example.com" required>
                            <span class="rd-help">Host name used by clients to find the ID server.</span>
                        </div>
                        <div class="rd-field">
                            <label class="rd-label" for="key">Public key</label>
                            <input class="rd-input rd-mono" id="key" name="key" value="{{ $key }}" placeholder="hbbs public key (…=)">
                        </div>
                        <div class="rd-field">
                            <label class="rd-label" for="relay">Relay server</label>
                            <input class="rd-input" id="relay" name="relay" value="{{ $relay }}" placeholder="rustdesk.example.com (optional)">
                        </div>
                        <div class="rd-field">
                            <label class="rd-label" for="api">API server</label>
                            <input class="rd-input" id="api" name="api" value="{{ $api }}" placeholder="https://api.example.com">
                        </div>
                        <div class="rd-field">
                            <label class="rd-label" for="unlock_pin">Default unlock PIN <span class="rd-muted">(optional)</span></label>
                            <input class="rd-input rd-mono" id="unlock_pin" name="unlock_pin" value="{{ $unlockPin }}" placeholder="e.g. 1234" inputmode="numeric" autocomplete="off">
                            <span class="rd-help">Protects local client settings. Set at install time through the CLI; a strategy cannot push it.</span>
                        </div>
                        <div class="rd-field">
                            <label class="rd-label" for="strategy">Install script from Strategy <span class="rd-muted">(optional)</span></label>
                            <select class="rd-select" id="strategy" name="strategy">
                                <option value="">— None —</option>
                                @foreach ($strategies as $strategy)
                                    <option value="{{ $strategy->id }}" @selected($strategyId === $strategy->id)>{{ $strategy->name }}</option>
                                @endforeach
                            </select>
                            <span class="rd-help">Turns strategy options into <code>rustdesk --option …</code> commands for a deployment script.</span>
                        </div>
                    </div>
                    <div class="rd-actions rd-actions--end">
                        <button type="submit" class="rd-btn rd-btn--primary"><i class="ri-magic-line" aria-hidden="true"></i> Generate client config</button>
                    </div>
                </form>
            </div>
        </section>

        @if ($installScript)
            <section class="rd-card" aria-labelledby="install-script-title">
                <div class="rd-card__header">
                    <div>
                        <p class="rd-card__eyebrow">Managed deployment</p>
                        <h2 class="rd-card__title" id="install-script-title">Install script — “{{ $selectedStrategy->name }}”</h2>
                    </div>
                </div>
                <div class="rd-card__body rd-stack rd-stack--md">
                    <div class="rd-callout rd-callout--warning">
                        <i class="ri-shield-keyhole-line" aria-hidden="true"></i>
                        <p>Run these commands as administrator or root on an installed client. They apply the selected strategy at deploy time. @if ($unlockPin !== '') The unlock PIN is included first. @endif</p>
                    </div>
                    @php
                        $scriptIcons = [
                            'Linux' => 'ri-ubuntu-fill',
                            'macOS' => 'ri-apple-fill',
                            'Windows' => 'ri-windows-fill',
                        ];
                    @endphp
                    @foreach ($installScript as $os => $script)
                        @if ($script !== '')
                            @php
                                $scriptId = 'script-'.Str::slug($os);
                            @endphp
                            <div class="rd-config-output">
                                <div class="rd-config-output__header">
                                    <label class="rd-label" for="{{ $scriptId }}"><i class="{{ $scriptIcons[$os] }}" aria-hidden="true"></i> {{ $os }}</label>
                                    <button type="button" class="rd-icon-btn rd-copy" data-copy="#{{ $scriptId }}" aria-label="Copy {{ $os }} install script" title="Copy"><i class="ri-file-copy-line" aria-hidden="true"></i></button>
                                </div>
                                <textarea class="rd-code-output rd-code-output--tall rd-mono" readonly id="{{ $scriptId }}">{{ $script }}</textarea>
                            </div>
                        @endif
                    @endforeach
                </div>
            </section>
        @endif

        @if ($unlockPin !== '' && ! $selectedStrategy)
            <section class="rd-card rd-card--quiet" aria-labelledby="unlock-pin-title">
                <div class="rd-card__header">
                    <div>
                        <p class="rd-card__eyebrow">One-time client command</p>
                        <h2 class="rd-card__title" id="unlock-pin-title">Default unlock PIN</h2>
                    </div>
                    <code>--set-unlock-pin</code>
                </div>
                <div class="rd-card__body rd-stack rd-stack--md">
                    <p class="rd-help">Run once as administrator or root. The PIN is encrypted per device and cannot be pushed by a strategy.</p>
                    @php
                        $pinIcons = ['Windows' => 'ri-windows-fill', 'macOS' => 'ri-apple-fill', 'Linux' => 'ri-ubuntu-fill'];
                        $pinCommands = [
                            'Windows' => '"%ProgramFiles%\\RustDesk\\rustdesk.exe" --set-unlock-pin '.$unlockPin,
                            'macOS' => 'sudo /Applications/RustDesk.app/Contents/MacOS/rustdesk --set-unlock-pin '.$unlockPin,
                            'Linux' => 'sudo rustdesk --set-unlock-pin '.$unlockPin,
                        ];
                    @endphp
                    @foreach ($pinCommands as $os => $command)
                        @php
                            $pinId = 'pin-'.Str::slug($os);
                        @endphp
                        <div class="rd-config-output">
                            <div class="rd-config-output__header">
                                <label class="rd-label" for="{{ $pinId }}"><i class="{{ $pinIcons[$os] }}" aria-hidden="true"></i> {{ $os }}</label>
                                <button type="button" class="rd-icon-btn rd-copy" data-copy="#{{ $pinId }}" aria-label="Copy {{ $os }} unlock PIN command" title="Copy"><i class="ri-file-copy-line" aria-hidden="true"></i></button>
                            </div>
                            <textarea class="rd-code-output rd-mono" readonly id="{{ $pinId }}">{{ $command }}</textarea>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($configString)
            <section class="rd-stack rd-stack--md" aria-labelledby="distribution-title">
                <div class="rd-page-header rd-page-header--section">
                    <div class="rd-page-header__copy">
                        <p class="rd-page-header__eyebrow">Steps 2 &amp; 3</p>
                        <h2 class="rd-page-header__title" id="distribution-title">Generated deployment formats</h2>
                        <p class="rd-page-header__description">Choose the format that matches each device and rollout path.</p>
                    </div>
                    <div class="rd-page-header__actions"><span class="rd-badge rd-badge--online"><span class="dot" aria-hidden="true"></span> Ready to distribute</span></div>
                </div>

                <div class="rd-config-layout">
                    <div class="rd-stack rd-stack--md">
                        <article class="rd-card rd-card--quiet">
                            <div class="rd-card__header"><div><p class="rd-card__eyebrow">Desktop import</p><h3 class="rd-card__title">Config string</h3></div></div>
                            <div class="rd-card__body rd-stack rd-stack--sm">
                                <p class="rd-help">Paste into <strong>Settings → Network → ID/Relay server → Import Server Config</strong>, or pass it to <code>rustdesk --config</code>.</p>
                                <div class="rd-config-output">
                                    <div class="rd-config-output__header"><label class="visually-hidden" for="cfgString">Config string</label><button type="button" class="rd-icon-btn rd-copy" data-copy="#cfgString" aria-label="Copy config string" title="Copy"><i class="ri-file-copy-line" aria-hidden="true"></i></button></div>
                                    <textarea class="rd-code-output rd-mono" readonly id="cfgString">{{ $configString }}</textarea>
                                </div>
                            </div>
                        </article>

                        <article class="rd-card rd-card--quiet">
                            <div class="rd-card__header"><div><p class="rd-card__eyebrow">Existing installation</p><h3 class="rd-card__title">Command line</h3></div><code>--config</code></div>
                            <div class="rd-card__body rd-stack rd-stack--md">
                                @php
                                    $commandIcons = ['Windows' => 'ri-windows-fill', 'macOS' => 'ri-apple-fill', 'Linux' => 'ri-ubuntu-fill'];
                                    $commands = [
                                        'Windows' => '"%ProgramFiles%\\RustDesk\\rustdesk.exe" --config '.$configString,
                                        'macOS' => '/Applications/RustDesk.app/Contents/MacOS/rustdesk --config '.$configString,
                                        'Linux' => 'rustdesk --config '.$configString,
                                    ];
                                @endphp
                                @foreach ($commands as $os => $command)
                                    @php
                                        $commandId = 'command-'.Str::slug($os);
                                    @endphp
                                    <div class="rd-config-output">
                                        <div class="rd-config-output__header">
                                            <label class="rd-label" for="{{ $commandId }}"><i class="{{ $commandIcons[$os] }}" aria-hidden="true"></i> {{ $os }}</label>
                                            <button type="button" class="rd-icon-btn rd-copy" data-copy="#{{ $commandId }}" aria-label="Copy {{ $os }} config command" title="Copy"><i class="ri-file-copy-line" aria-hidden="true"></i></button>
                                        </div>
                                        <textarea class="rd-code-output rd-mono" readonly id="{{ $commandId }}">{{ $command }}</textarea>
                                    </div>
                                @endforeach
                            </div>
                        </article>

                        <article class="rd-card rd-card--quiet">
                            <div class="rd-card__header"><div><p class="rd-card__eyebrow">Windows first run</p><h3 class="rd-card__title">Renamed installer</h3></div></div>
                            <div class="rd-card__body rd-stack rd-stack--sm">
                                <p class="rd-help">Rename the downloaded <code>rustdesk-*.exe</code> to this filename so the config is applied on first run.</p>
                                <div class="rd-config-output">
                                    <div class="rd-config-output__header"><label class="visually-hidden" for="cfgInstaller">Installer filename</label><button type="button" class="rd-icon-btn rd-copy" data-copy="#cfgInstaller" aria-label="Copy installer filename" title="Copy"><i class="ri-file-copy-line" aria-hidden="true"></i></button></div>
                                    <textarea class="rd-code-output rd-mono" readonly id="cfgInstaller">{{ $installer }}</textarea>
                                </div>
                            </div>
                        </article>
                    </div>

                    <aside class="rd-card rd-config-qr" aria-labelledby="mobile-qr-title">
                        <div class="rd-card__header"><div><p class="rd-card__eyebrow">Mobile import</p><h3 class="rd-card__title" id="mobile-qr-title">Scan QR code</h3></div></div>
                        <div class="rd-card__body">
                            <div class="rd-config-qr__frame">{!! $qrSvg !!}</div>
                            <p class="rd-help">In the mobile app, choose <strong>＋</strong> and scan to import this server configuration.</p>
                        </div>
                    </aside>
                </div>
            </section>
        @else
            <div class="rd-empty">
                <i class="ri-qr-code-line rd-empty__icon" aria-hidden="true"></i>
                <p class="rd-empty__title">Ready when your server details are</p>
                <p class="rd-empty__body">Enter an ID server or public key above to generate client-ready deployment formats.</p>
            </div>
        @endif
    </div>
@endsection

@push('scripts')
<script>
    $(function () {
        function fallbackCopy(element) {
            element.focus();
            element.select();
            return document.execCommand('copy');
        }

        $('.rd-copy').on('click', function () {
            var element = $($(this).data('copy'))[0];
            var text = element.value;
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text)
                    .then(function () { RD.toast('Copied to clipboard', 'success'); })
                    .catch(function () {
                        fallbackCopy(element);
                        RD.toast('Copied to clipboard', 'success');
                    });
            } else {
                fallbackCopy(element);
                RD.toast('Copied to clipboard', 'success');
            }
        });
    });
</script>
@endpush
