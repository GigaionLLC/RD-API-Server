<!DOCTYPE html>
<html lang="en" data-theme="dark" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark light">
    <meta name="referrer" content="no-referrer">
    <title>Approve device sign-in · RD-API-Server</title>
    <link href="{{ asset('assets/vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/vendor/remixicon/remixicon.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/theme-dark.css') }}" rel="stylesheet">
</head>
<body class="rd-auth">
<main class="rd-auth__shell">
    <section class="rd-auth__intro" aria-label="Device sign-in">
        <div class="rd-auth__brand">
            <span class="rd-logo rd-auth__mark"><i class="ri-remote-control-line" aria-hidden="true"></i></span>
            <span>RD-API-Server</span>
        </div>

        <div class="rd-stack rd-stack--md">
            <span class="rd-page-header__eyebrow">Device sign-in</span>
            <p class="rd-page-header__title">Only approve a sign-in you started yourself.</p>
            <p class="rd-page-header__description">Approving gives the device below access to your account, including your address books. If someone sent you this link, choose Deny.</p>
        </div>

        <p class="rd-muted">Independent open-source project — not affiliated with or endorsed by RustDesk.</p>
    </section>

    <section class="rd-auth__panel" aria-labelledby="confirm-title">
        <div class="rd-stack rd-stack--lg">
            <div>
                <h1 class="rd-page-title" id="confirm-title">Approve sign-in on this device?</h1>
                <p class="rd-muted">You signed in as <strong>{{ $confirmation['account'] }}</strong> with {{ $confirmation['provider'] }}. The RustDesk app that requested this sign-in reported:</p>
            </div>

            <div class="rd-table-wrap" role="region" aria-label="Requesting device" tabindex="0">
                <table class="rd-table">
                    <tbody>
                        <tr><th scope="row">Device name</th><td>{{ $confirmation['device_name'] !== '' ? $confirmation['device_name'] : '—' }}</td></tr>
                        <tr><th scope="row">Operating system</th><td>{{ $confirmation['device_os'] !== '' ? $confirmation['device_os'] : '—' }}</td></tr>
                        <tr><th scope="row">RustDesk ID</th><td class="rd-mono">{{ $confirmation['rustdesk_id'] !== '' ? $confirmation['rustdesk_id'] : '—' }}</td></tr>
                        <tr><th scope="row">Requested from IP</th><td class="rd-mono">{{ $confirmation['request_ip'] !== '' ? $confirmation['request_ip'] : 'unknown' }}</td></tr>
                        <tr><th scope="row">Requested at</th><td>{{ $confirmation['requested_at']?->format('Y-m-d H:i:s T') ?? '—' }}</td></tr>
                        <tr><th scope="row">This browser's IP</th><td class="rd-mono">{{ $viewerIp }}</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="rd-callout rd-callout--warning" role="note">
                <i class="ri-alert-line" aria-hidden="true"></i>
                <div>The device name is reported by the requesting app and can be chosen freely. Check that the RustDesk ID matches the one shown in your own RustDesk app.</div>
            </div>

            <form method="POST" action="{{ $action }}" class="rd-stack rd-stack--md">
                <input type="hidden" name="state" value="{{ $confirmation['state'] }}">
                <input type="hidden" name="nonce" value="{{ $confirmation['nonce'] }}">
                <button type="submit" name="decision" value="approve" class="rd-btn rd-btn--primary rd-btn--block">
                    <i class="ri-shield-check-line" aria-hidden="true"></i> Approve sign-in on this device
                </button>
                <button type="submit" name="decision" value="deny" class="rd-btn rd-btn--ghost rd-btn--block">
                    <i class="ri-close-line" aria-hidden="true"></i> Deny
                </button>
            </form>

            <footer class="rd-auth__footer">This request expires at {{ $confirmation['expires_at']?->format('H:i:s T') ?? 'soon' }}.</footer>
        </div>
    </section>
</main>
</body>
</html>
