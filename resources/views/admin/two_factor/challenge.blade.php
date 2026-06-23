<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Two-factor · rustdesk-api</title>
    <link href="https://cdn.jsdelivr.net/npm/remixicon@4.5.0/fonts/remixicon.css" rel="stylesheet">
    <link href="{{ asset('assets/css/theme-dark.css') }}" rel="stylesheet">
    <style>
        .rd-login { min-height: 100vh; display: grid; place-items: center; padding: 24px; }
        .rd-login__card { width: 100%; max-width: 400px; }
        .rd-login__brand { display:flex; align-items:center; gap:10px; justify-content:center;
            font-weight:700; font-size:20px; color:var(--rd-text-bright); margin-bottom:22px; }
        .rd-login__brand .rd-logo { width:34px; height:34px; font-size:18px; }
    </style>
</head>
<body>
<div class="rd-login">
    <div class="rd-login__card">
        <div class="rd-login__brand">
            <span class="rd-logo"><i class="ri-shield-keyhole-line"></i></span> rustdesk-api
        </div>

        <div class="rd-card"><div class="rd-card__body">
            <h1 class="rd-page-title" style="text-align:center;">Two-factor authentication</h1>
            <p class="rd-muted" style="text-align:center;margin-top:0;margin-bottom:22px;">
                Enter the 6-digit code from your authenticator app, or a recovery code.
            </p>

            @if ($errors->any())
                <div class="rd-toast rd-toast--error" style="margin-bottom:16px;">
                    <i class="ri-error-warning-line"></i><span>{{ $errors->first() }}</span>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.2fa.challenge.verify') }}">
                @csrf
                <div class="rd-field">
                    <label class="rd-label" for="code">Authentication code</label>
                    <input class="rd-input" id="code" name="code" inputmode="numeric"
                           autocomplete="one-time-code" placeholder="000000" required autofocus>
                </div>
                <button type="submit" class="rd-btn rd-btn--primary" style="width:100%;">
                    <i class="ri-login-box-line"></i> Verify
                </button>
            </form>

            <a href="{{ route('admin.login') }}" class="rd-btn rd-btn--ghost"
               style="width:100%;margin-top:14px;">Cancel</a>
        </div></div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="{{ asset('assets/js/app.js') }}"></script>
</body>
</html>
