@extends('layouts.admin')
@section('title', 'Two-Factor Authentication')

@section('content')
    <header class="rd-page-header">
        <div class="rd-page-header__copy">
            <div class="rd-page-header__eyebrow">Account Security</div>
            <h1 class="rd-page-header__title">Two-factor authentication</h1>
            <p class="rd-page-header__description">Protect admin-console and client sign-ins with a time-based one-time code.</p>
        </div>
        <div class="rd-page-header__actions">
            @if ($enabled)
                <span class="rd-badge rd-badge--online"><span class="dot"></span> Enabled</span>
            @else
                <span class="rd-badge rd-badge--muted"><span class="dot"></span> Disabled</span>
            @endif
        </div>
    </header>

    @include('admin.partials.flash')

    <div class="rd-card rd-card--quiet rd-max-w-lg">
        <div class="rd-card__header">
            <h2 class="rd-card__title"><i class="ri-shield-keyhole-line" aria-hidden="true"></i> Authenticator app (TOTP)</h2>
        </div>
        <div class="rd-card__body rd-stack rd-stack--lg">

            {{-- Recovery codes are shown exactly once, right after enrolling. --}}
            @if (!empty($recoveryCodes))
                <div class="rd-callout rd-callout--success" role="status">
                    <i class="ri-checkbox-circle-line" aria-hidden="true"></i>
                    <div><strong>Two-factor authentication is on.</strong> Save these recovery codes now — each works once if you lose your device.</div>
                </div>
                <div class="rd-card rd-card--quiet">
                    <div class="rd-card__body">
                        <div class="rd-stack rd-stack--sm rd-mono" aria-label="One-time recovery codes">
                            @foreach ($recoveryCodes as $code)
                                <div>{{ $code }}</div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

            @if ($enabled)
                <div class="rd-callout rd-callout--info">
                    <i class="ri-information-line" aria-hidden="true"></i>
                    <div>
                    Your account is protected by an authenticator app. You'll be asked for a code at
                    every sign-in (admin console and client login).
                    </div>
                </div>
                <form method="POST" action="{{ route('admin.2fa.disable') }}" class="rd-stack rd-stack--md">
                    @csrf
                    @method('DELETE')
                    <div class="rd-field">
                        <label class="rd-label" for="password">Confirm your password to disable</label>
                        <input class="rd-input" id="password" name="password" type="password"
                               autocomplete="current-password" maxlength="{{ \App\Support\AccountPasswordPolicy::MAX_LENGTH }}" required
                               @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
                        @error('password')<span class="rd-help rd-help--error" id="password-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="rd-actions">
                        <button type="submit" class="rd-btn rd-btn--danger" data-confirm="Disable two-factor authentication for this account?">
                            <i class="ri-shield-cross-line" aria-hidden="true"></i> Disable two-factor
                        </button>
                    </div>
                </form>

            @elseif ($setupSecret)
                <div class="rd-callout rd-callout--info">
                    <i class="ri-information-line" aria-hidden="true"></i>
                    <div>Add this account to your authenticator app, then enter the six-digit code it shows to finish.</div>
                </div>

                <div class="rd-form-grid rd-form-grid--2">
                    <div class="rd-field rd-field--mono">
                        <label class="rd-label" for="setup-key">Setup key (enter manually)</label>
                        <input class="rd-input rd-input--mono" id="setup-key" value="{{ trim(chunk_split($setupSecret, 4, ' ')) }}" readonly onclick="this.select()">
                    </div>
                    <div class="rd-field rd-field--mono">
                        <label class="rd-label" for="setup-uri">Or paste this otpauth URI</label>
                        <input class="rd-input rd-input--mono" id="setup-uri" value="{{ $setupUri }}" readonly onclick="this.select()">
                    </div>
                </div>

                <form method="POST" action="{{ route('admin.2fa.confirm') }}" class="rd-stack rd-stack--md">
                    @csrf
                    <div class="rd-field">
                        <label class="rd-label" for="code">6-digit code</label>
                        <input class="rd-input" id="code" name="code" inputmode="numeric"
                               autocomplete="one-time-code" pattern="[0-9]*" maxlength="6"
                               placeholder="000000" required autofocus
                               @error('code') aria-invalid="true" aria-describedby="code-error" @enderror>
                        @error('code')<span class="rd-help rd-help--error" id="code-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="rd-actions rd-actions--wrap">
                        <button type="submit" class="rd-btn rd-btn--primary"><i class="ri-check-line" aria-hidden="true"></i> Verify &amp; enable</button>
                        <a class="rd-btn rd-btn--ghost" href="{{ route('admin.2fa.show') }}">Cancel</a>
                    </div>
                </form>

            @else
                <div class="rd-callout rd-callout--info">
                    <i class="ri-information-line" aria-hidden="true"></i>
                    <div>
                    Protect your account with a time-based one-time code from an authenticator app, in
                    addition to your password. This also applies to your RustDesk client login.
                    </div>
                </div>
                <form method="POST" action="{{ route('admin.2fa.enable') }}">
                    @csrf
                    <button type="submit" class="rd-btn rd-btn--primary"><i class="ri-shield-keyhole-line" aria-hidden="true"></i> Enable two-factor</button>
                </form>
            @endif

        </div>
    </div>
@endsection
