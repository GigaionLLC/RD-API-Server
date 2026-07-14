@extends('layouts.admin')
@section('title', 'Edit OAuth Provider')

@section('content')
    <header class="rd-page-header">
        <div class="rd-page-header__copy">
            <div class="rd-page-header__eyebrow">Integrations / OAuth Providers</div>
            <h1 class="rd-page-header__title">{{ $provider->op }}</h1>
            <p class="rd-page-header__description">Maintain identity-provider credentials, discovery, and client sign-in behavior.</p>
        </div>
        <div class="rd-page-header__actions">
            <a href="{{ route('admin.oauth-providers.index') }}" class="rd-btn rd-btn--ghost"><i class="ri-arrow-left-line"></i> Back</a>
        </div>
    </header>

    <div class="rd-card rd-card--quiet rd-max-w-lg">
        <div class="rd-card__body rd-stack rd-stack--lg">
            @if ($errors->any())
                <div class="rd-callout rd-callout--danger" role="alert">
                    <i class="ri-error-warning-line" aria-hidden="true"></i>
                    <div><strong>Provider not saved.</strong> {{ $errors->first() }}</div>
                </div>
            @endif
            <form method="POST" action="{{ route('admin.oauth-providers.update', $provider) }}" class="rd-stack rd-stack--lg">
                @csrf
                @method('PUT')

                <div class="rd-form-grid rd-form-grid--2">
                    <div class="rd-field">
                        <label class="rd-label" for="op">Key (op)</label>
                        <input class="rd-input rd-input--mono" id="op" name="op" value="{{ old('op', $provider->op) }}" required aria-describedby="op-help"
                               @error('op') aria-invalid="true" aria-errormessage="op-error" @enderror>
                        <span class="rd-help" id="op-help">Unique provider key requested by the client.</span>
                        @error('op')<span class="rd-help rd-help--error" id="op-error">{{ $message }}</span>@enderror
                    </div>

                    <div class="rd-field">
                        <label class="rd-label" for="type">Type</label>
                        <select class="rd-select" id="type" name="type" aria-describedby="type-help"
                                @error('type') aria-invalid="true" aria-errormessage="type-error" @enderror>
                            @foreach ($types as $type)
                                <option value="{{ $type }}" @selected(old('type', $provider->type) === $type)>{{ $type }}</option>
                            @endforeach
                        </select>
                        <span class="rd-help" id="type-help"><code>oidc</code> requires an issuer.</span>
                        @error('type')<span class="rd-help rd-help--error" id="type-error">{{ $message }}</span>@enderror
                    </div>
                </div>

                <div class="rd-form-grid rd-form-grid--2">
                    <div class="rd-field">
                        <label class="rd-label" for="client_id">Client ID</label>
                        <input class="rd-input" id="client_id" name="client_id" value="{{ old('client_id', $provider->client_id) }}" required
                               @error('client_id') aria-invalid="true" aria-describedby="client-id-error" @enderror>
                        @error('client_id')<span class="rd-help rd-help--error" id="client-id-error">{{ $message }}</span>@enderror
                    </div>

                    <div class="rd-field">
                        <label class="rd-label" for="client_secret">Client secret</label>
                        <input class="rd-input" id="client_secret" name="client_secret" type="password" autocomplete="new-password"
                               placeholder="Leave blank to keep current secret" aria-describedby="client-secret-help"
                               @error('client_secret') aria-invalid="true" aria-errormessage="client-secret-error" @enderror>
                        <span class="rd-help" id="client-secret-help">Write-only. Leave blank to keep the stored secret.</span>
                        @error('client_secret')<span class="rd-help rd-help--error" id="client-secret-error">{{ $message }}</span>@enderror
                    </div>
                </div>

                <div class="rd-field">
                    <label class="rd-label" for="issuer">Issuer</label>
                    <input class="rd-input rd-input--mono" id="issuer" name="issuer" value="{{ old('issuer', $provider->issuer) }}" placeholder="https://accounts.example.com" aria-describedby="issuer-help"
                           @error('issuer') aria-invalid="true" aria-errormessage="issuer-error" @enderror>
                    <span class="rd-help" id="issuer-help">Required for the <code>oidc</code> type.</span>
                    @error('issuer')<span class="rd-help rd-help--error" id="issuer-error">{{ $message }}</span>@enderror
                </div>

                <div class="rd-field">
                    <label class="rd-label" for="scopes">Scopes</label>
                    <input class="rd-input rd-input--mono" id="scopes" name="scopes" value="{{ old('scopes', $provider->scopes) }}" placeholder="openid,profile,email" aria-describedby="scopes-help"
                           @error('scopes') aria-invalid="true" aria-errormessage="scopes-error" @enderror>
                    <span class="rd-help" id="scopes-help">Comma-separated. Defaults to <code>openid,profile,email</code> when blank.</span>
                    @error('scopes')<span class="rd-help rd-help--error" id="scopes-error">{{ $message }}</span>@enderror
                </div>

                <div class="rd-field">
                    <label class="rd-label" for="pkce_method">PKCE method</label>
                    <select class="rd-select" id="pkce_method" name="pkce_method" @error('pkce_method') aria-invalid="true" aria-describedby="pkce-method-error" @enderror>
                        <option value="S256" @selected(old('pkce_method', $provider->pkce_method) === 'S256')>S256</option>
                        <option value="plain" @selected(old('pkce_method', $provider->pkce_method) === 'plain')>plain</option>
                    </select>
                    @error('pkce_method')<span class="rd-help rd-help--error" id="pkce-method-error">{{ $message }}</span>@enderror
                </div>

                <div class="rd-field">
                    <div class="rd-label" id="provider-options-label">Options</div>
                    <div class="rd-stack rd-stack--sm" role="group" aria-labelledby="provider-options-label">
                        <label class="rd-check">
                            <input type="checkbox" name="auto_register" value="1" @checked(old('auto_register', $provider->auto_register))>
                            <span>Auto-register new users on first login</span>
                        </label>
                        <label class="rd-check">
                            <input type="checkbox" name="pkce_enable" value="1" @checked(old('pkce_enable', $provider->pkce_enable))>
                            <span>Enable PKCE</span>
                        </label>
                        <label class="rd-check">
                            <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $provider->enabled))>
                            <span>Enabled and offered to clients</span>
                        </label>
                    </div>
                </div>

                <div class="rd-actions">
                    <button type="submit" class="rd-btn rd-btn--primary"><i class="ri-save-line"></i> Save provider</button>
                </div>
            </form>
        </div>
    </div>
@endsection
