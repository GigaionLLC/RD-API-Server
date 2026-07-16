@extends('layouts.admin')
@section('title', 'Two-Factor Authentication')

@section('content')
    <header class="rd-page-header">
        <div class="rd-page-header__copy">
            <div class="rd-page-header__eyebrow">Account Security</div>
            <h1 class="rd-page-header__title">Two-factor authentication</h1>
            <p class="rd-page-header__description">Protect password-based admin-console and RustDesk client sign-ins with a time-based one-time code. SSO sign-ins follow your identity provider's policy.</p>
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

            @if (! $recentlyAuthenticated && empty($recoveryCodes))
                <div class="rd-callout rd-callout--warning" role="status">
                    <i class="ri-lock-line" aria-hidden="true"></i>
                    <div>
                        <strong>Sign in again to make changes.</strong> For your security, two-factor
                        setup and removal are available only for {{ $recentAuthenticationWindow }}
                        after a completed sign-in.
                        Complete your normal local, LDAP, or SSO sign-in to continue.
                    </div>
                </div>
                <form method="POST" action="{{ route('admin.2fa.reauthenticate') }}">
                    @csrf
                    <button type="submit" class="rd-btn rd-btn--primary">
                        <i class="ri-login-box-line" aria-hidden="true"></i> Sign out and sign in again
                    </button>
                </form>
            @endif

            @if ($enabled)
                <div class="rd-callout rd-callout--info">
                    <i class="ri-information-line" aria-hidden="true"></i>
                    <div>
                    Password-based sign-ins for this account require a code from your authenticator
                    app. SSO sign-ins follow your identity provider's policy.
                    @if ($factorRecentlyVerified)
                        You already verified this authenticator during the current sign-in, so no
                        additional code is required to disable it.
                    @endif
                    </div>
                </div>
                @if ($recentlyAuthenticated)
                <form method="POST" action="{{ route('admin.2fa.disable') }}" class="rd-stack rd-stack--md">
                    @csrf
                    @method('DELETE')
                    @if (! $factorRecentlyVerified)
                    <div class="rd-field">
                        <label class="rd-label" for="disable-code">Authenticator or recovery code</label>
                        <input class="rd-input" id="disable-code" name="code" inputmode="text"
                               autocomplete="one-time-code" maxlength="32" spellcheck="false" required
                               aria-describedby="disable-code-help @error('code') disable-code-error @enderror"
                               @error('code') aria-invalid="true" @enderror>
                        <span class="rd-help" id="disable-code-help">Enter a current 6-digit code from your authenticator app or one unused recovery code.</span>
                        @error('code')<span class="rd-help rd-help--error" id="disable-code-error">{{ $message }}</span>@enderror
                    </div>
                    @endif
                    <div class="rd-actions">
                        <button type="submit" class="rd-btn rd-btn--danger" data-confirm="Disable two-factor authentication? Password-based sign-ins will no longer require an authenticator code, and all recovery codes will be removed.">
                            <i class="ri-shield-cross-line" aria-hidden="true"></i> Disable two-factor
                        </button>
                    </div>
                </form>
                @endif

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
                    <div class="rd-actions">
                        <button type="submit" class="rd-btn rd-btn--primary"><i class="ri-check-line" aria-hidden="true"></i> Verify &amp; enable</button>
                    </div>
                </form>
                <form method="POST" action="{{ route('admin.2fa.cancel') }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="rd-btn rd-btn--ghost">Cancel</button>
                </form>

            @else
                <div class="rd-callout rd-callout--info">
                    <i class="ri-information-line" aria-hidden="true"></i>
                    <div>
                    Add a time-based one-time code to password-based admin-console and RustDesk client
                    sign-ins. SSO sign-ins continue to follow your identity provider's policy.
                    </div>
                </div>
                @if ($recentlyAuthenticated)
                <form method="POST" action="{{ route('admin.2fa.enable') }}">
                    @csrf
                    <button type="submit" class="rd-btn rd-btn--primary"><i class="ri-shield-keyhole-line" aria-hidden="true"></i> Set up authenticator</button>
                </form>
                @endif
            @endif

        </div>
    </div>
@endsection
