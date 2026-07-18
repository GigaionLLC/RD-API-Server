<!DOCTYPE html>
<html lang="en" data-theme="dark" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="dark light">
    <title>Sign in · RD-API-Server</title>
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
    <section class="rd-auth__intro" aria-label="About RD-API-Server">
        <div class="rd-auth__brand">
            <span class="rd-logo rd-auth__mark"><i class="ri-remote-control-line" aria-hidden="true"></i></span>
            <span>RD-API-Server</span>
        </div>

        <div class="rd-stack rd-stack--md">
            <span class="rd-page-header__eyebrow">Calm remote operations</span>
            <p class="rd-page-header__title">Your fleet, access, and policies in one self-hosted console.</p>
            <p class="rd-page-header__description">Monitor devices and manage the API services used by compatible RustDesk clients.</p>
        </div>

        <p class="rd-muted">Independent open-source project — not affiliated with or endorsed by RustDesk.</p>
    </section>

    <section class="rd-auth__panel" aria-labelledby="sign-in-title">
        <div class="rd-stack rd-stack--lg">
            <div>
                <div class="rd-auth__brand">
                    <span class="rd-logo rd-auth__mark"><i class="ri-remote-control-line" aria-hidden="true"></i></span>
                    <span>RD-API-Server</span>
                </div>
            </div>

            <div>
                <h1 class="rd-page-title" id="sign-in-title">Welcome back</h1>
                <p class="rd-muted">Sign in to the admin console.</p>
            </div>

            @if ($errors->any())
                <div class="rd-callout rd-callout--danger" role="alert">
                    <i class="ri-error-warning-line" aria-hidden="true"></i>
                    <div><strong>Sign-in failed.</strong> {{ $errors->first() }}</div>
                </div>
            @endif

            <form method="POST" action="/admin/login" class="rd-stack rd-stack--md">
                @csrf
                <div class="rd-field">
                    <label class="rd-label" for="username">Username</label>
                    <input class="rd-input" id="username" name="username" autocomplete="username"
                           value="{{ old('username') }}" required autofocus
                           @error('username') aria-invalid="true" aria-describedby="username-error" @enderror>
                    @error('username')<span class="rd-help rd-help--error" id="username-error">{{ $message }}</span>@enderror
                </div>
                <div class="rd-field">
                    <label class="rd-label" for="password">Password</label>
                    <input class="rd-input" id="password" name="password" type="password"
                           autocomplete="current-password" maxlength="{{ \App\Support\AccountPasswordPolicy::MAX_LENGTH }}" required
                           @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
                    @error('password')<span class="rd-help rd-help--error" id="password-error">{{ $message }}</span>@enderror
                </div>
                <button type="submit" class="rd-btn rd-btn--primary rd-btn--block">
                    <i class="ri-login-box-line" aria-hidden="true"></i> Sign in
                </button>
            </form>

            @if (!empty($ssoProviders) && count($ssoProviders))
                <div class="rd-auth__divider">or</div>
                <div class="rd-auth__providers">
                    @foreach ($ssoProviders as $p)
                        <a class="rd-btn rd-btn--ghost rd-btn--block" href="{{ route('admin.sso.redirect', ['op' => $p->op]) }}">
                            <i class="ri-shield-keyhole-line" aria-hidden="true"></i> Sign in with {{ ucfirst($p->op) }}
                        </a>
                    @endforeach
                </div>
            @endif

            <footer class="rd-auth__footer">
                Self-hosted API and admin console for compatible RustDesk clients.<br>
                Independent open-source project — not affiliated with or endorsed by RustDesk.
            </footer>
        </div>
    </section>
</main>
<script src="{{ asset('assets/vendor/jquery/jquery.min.js') }}"></script>
<script src="{{ asset('assets/js/app.js') }}"></script>
</body>
</html>
