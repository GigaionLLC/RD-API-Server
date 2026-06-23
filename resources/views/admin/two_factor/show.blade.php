@extends('layouts.admin')
@section('title', 'Two-Factor Authentication')

@section('content')
    <div class="rd-breadcrumb">Account / Two-Factor Authentication</div>

    @include('admin.partials.flash')

    <div class="rd-card" style="max-width:640px;">
        <div class="rd-card__header">
            <h3 class="rd-card__title">
                <i class="ri-shield-keyhole-line"></i> Two-Factor Authentication (TOTP)
            </h3>
            @if ($enabled)
                <span class="rd-badge rd-badge--online">Enabled</span>
            @else
                <span class="rd-badge rd-badge--muted">Disabled</span>
            @endif
        </div>
        <div class="rd-card__body">

            {{-- Recovery codes are shown exactly once, right after enrolling. --}}
            @if (!empty($recoveryCodes))
                <div class="rd-toast rd-toast--success" style="margin-bottom:16px;">
                    <i class="ri-checkbox-circle-line"></i>
                    <span>Two-factor authentication is on. Save these recovery codes now — each works once if you lose your device.</span>
                </div>
                <div class="rd-card" style="background:var(--rd-surface-2);margin-bottom:18px;">
                    <div class="rd-card__body">
                        <div style="font-family:monospace;line-height:2;letter-spacing:1px;">
                            @foreach ($recoveryCodes as $code)
                                <div>{{ $code }}</div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

            @if ($enabled)
                <p class="rd-muted">
                    Your account is protected by an authenticator app. You'll be asked for a code at
                    every sign-in (admin console and client login).
                </p>
                <form method="POST" action="{{ route('admin.2fa.disable') }}" class="rd-row" style="gap:10px;align-items:flex-end;margin-top:8px;">
                    @csrf
                    @method('DELETE')
                    <div class="rd-field" style="flex:1;margin:0;">
                        <label class="rd-label" for="password">Confirm your password to disable</label>
                        <input class="rd-input" id="password" name="password" type="password"
                               autocomplete="current-password" required>
                        @error('password')<span class="rd-help rd-help--error">{{ $message }}</span>@enderror
                    </div>
                    <button type="submit" class="rd-btn rd-btn--danger"><i class="ri-shield-cross-line"></i> Disable</button>
                </form>

            @elseif ($setupSecret)
                <p class="rd-muted" style="margin-top:0;">
                    Add this account to your authenticator app (Google Authenticator, Aegis, 1Password…),
                    then enter the 6-digit code it shows to finish.
                </p>

                <label class="rd-label">Setup key (enter manually)</label>
                <div class="rd-input" style="font-family:monospace;letter-spacing:3px;user-select:all;margin-bottom:12px;">
                    {{ trim(chunk_split($setupSecret, 4, ' ')) }}
                </div>

                <label class="rd-label">Or paste this otpauth URI</label>
                <input class="rd-input" style="font-family:monospace;font-size:12px;margin-bottom:16px;"
                       value="{{ $setupUri }}" readonly onclick="this.select()">

                <form method="POST" action="{{ route('admin.2fa.confirm') }}">
                    @csrf
                    <div class="rd-field">
                        <label class="rd-label" for="code">6-digit code</label>
                        <input class="rd-input" id="code" name="code" inputmode="numeric"
                               autocomplete="one-time-code" pattern="[0-9]*" maxlength="6"
                               placeholder="000000" required autofocus>
                        @error('code')<span class="rd-help rd-help--error">{{ $message }}</span>@enderror
                    </div>
                    <div class="rd-row" style="gap:10px;">
                        <button type="submit" class="rd-btn rd-btn--primary"><i class="ri-check-line"></i> Verify &amp; enable</button>
                        <a class="rd-btn rd-btn--ghost" href="{{ route('admin.2fa.show') }}">Cancel</a>
                    </div>
                </form>

            @else
                <p class="rd-muted" style="margin-top:0;">
                    Protect your account with a time-based one-time code from an authenticator app, in
                    addition to your password. This also applies to your RustDesk client login.
                </p>
                <form method="POST" action="{{ route('admin.2fa.enable') }}">
                    @csrf
                    <button type="submit" class="rd-btn rd-btn--primary"><i class="ri-shield-keyhole-line"></i> Enable two-factor</button>
                </form>
            @endif

        </div>
    </div>
@endsection
