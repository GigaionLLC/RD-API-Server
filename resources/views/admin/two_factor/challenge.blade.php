<!DOCTYPE html>
<html lang="en" data-theme="dark" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="dark light">
    <title>Two-factor authentication · RD-API-Server</title>
    <script>
        (function () {
            try {
                var saved = window.localStorage.getItem('rd_theme');
                var theme = saved === 'light' || saved === 'dark'
                    ? saved
                    : 'dark';
                document.documentElement.setAttribute('data-theme', theme);
                document.documentElement.setAttribute('data-bs-theme', theme);
            } catch (error) {
                document.documentElement.setAttribute('data-theme', 'dark');
                document.documentElement.setAttribute('data-bs-theme', 'dark');
            }
        }());
    </script>
    <link href="{{ asset('assets/vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/vendor/remixicon/remixicon.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/theme-dark.css') }}" rel="stylesheet">
</head>
<body class="rd-auth">
<button type="button" class="rd-auth__theme rd-icon-btn" data-theme-toggle aria-label="Switch color theme" title="Switch color theme">
    <i class="ri-sun-line" aria-hidden="true"></i>
</button>
<main class="rd-auth__shell">
    <section class="rd-auth__intro" aria-label="Security verification">
        <div class="rd-auth__brand">
            <span class="rd-logo rd-auth__mark"><i class="ri-remote-control-line" aria-hidden="true"></i></span>
            <span>RD-API-Server</span>
        </div>

        <div class="rd-stack rd-stack--md">
            <span class="rd-page-header__eyebrow">Account protection</span>
            <p class="rd-page-header__title">One more check keeps remote operations in trusted hands.</p>
            <p class="rd-page-header__description">Use your authenticator code or one of the recovery codes saved during enrollment.</p>
        </div>

        <p class="rd-muted">This verification completes your admin-console sign-in.<br>Independent open-source project — not affiliated with or endorsed by RustDesk.</p>
    </section>

    <section class="rd-auth__panel" aria-labelledby="challenge-title">
        <div class="rd-stack rd-stack--lg">
            <div class="rd-auth__brand">
                <span class="rd-logo rd-auth__mark"><i class="ri-shield-keyhole-line" aria-hidden="true"></i></span>
                <span>RD-API-Server</span>
            </div>

            <div>
                <h1 class="rd-page-title" id="challenge-title">Two-factor authentication</h1>
                <p class="rd-muted">Enter the six-digit code from your authenticator app, or a recovery code.</p>
            </div>

            @if ($errors->any())
                <div class="rd-callout rd-callout--danger" role="alert">
                    <i class="ri-error-warning-line" aria-hidden="true"></i>
                    <div><strong>Verification failed.</strong> {{ $errors->first() }}</div>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.2fa.challenge.verify') }}" class="rd-stack rd-stack--md">
                @csrf
                <div class="rd-field">
                    <label class="rd-label" for="code">Authentication code</label>
                    <input class="rd-input" id="code" name="code" inputmode="numeric"
                           autocomplete="one-time-code" placeholder="000000" required autofocus
                           @error('code') aria-invalid="true" aria-describedby="code-error" @enderror>
                    @error('code')<span class="rd-help rd-help--error" id="code-error">{{ $message }}</span>@enderror
                </div>
                <button type="submit" class="rd-btn rd-btn--primary rd-btn--block">
                    <i class="ri-login-box-line" aria-hidden="true"></i> Verify
                </button>
            </form>

            <a href="{{ route('admin.login') }}" class="rd-btn rd-btn--ghost rd-btn--block">Cancel</a>

            <footer class="rd-auth__footer">If you no longer have access to your authenticator, use an unused recovery code.</footer>
        </div>
    </section>
</main>
<script src="{{ asset('assets/vendor/jquery/jquery.min.js') }}"></script>
<script src="{{ asset('assets/js/app.js') }}"></script>
</body>
</html>
