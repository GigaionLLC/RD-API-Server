@extends('layouts.admin')
@section('title', 'New OAuth Provider')

@section('content')
    <header class="rd-page-header">
        <div class="rd-page-header__copy">
            <div class="rd-page-header__eyebrow">Integrations / OAuth Providers</div>
            <h1 class="rd-page-header__title">Connect an identity provider</h1>
            <p class="rd-page-header__description">Configure OAuth or OpenID Connect sign-in for clients and the admin console.</p>
        </div>
        <div class="rd-page-header__actions">
            <a href="{{ route('admin.oauth-providers.index') }}" class="rd-btn rd-btn--ghost"><i class="ri-arrow-left-line" aria-hidden="true"></i> Back</a>
        </div>
    </header>

    <div class="rd-card rd-card--quiet rd-max-w-lg">
        <div class="rd-card__body rd-stack rd-stack--lg">
            @if ($errors->any())
                <div class="rd-callout rd-callout--danger" role="alert">
                    <i class="ri-error-warning-line" aria-hidden="true"></i>
                    <div><strong>Provider not created.</strong> {{ $errors->first() }}</div>
                </div>
            @endif
            <div class="rd-stack rd-stack--lg">
                {{-- Guided setup: pick a provider to prefill type / scopes / PKCE / issuer shape. --}}
                <div class="rd-field">
                    <label class="rd-label" for="preset"><i class="ri-magic-line" aria-hidden="true"></i> Quick setup</label>
                    <select class="rd-select" id="preset" aria-describedby="presetHint">
                        <option value="">— Choose a provider to prefill —</option>
                        @foreach ($presets as $key => $p)
                            <option value="{{ $key }}">{{ $p['label'] }}</option>
                        @endforeach
                    </select>
                    <span class="rd-help" id="presetHint">Optional. You still enter the client ID, secret, and real issuer host.</span>
                </div>

                <div class="rd-callout rd-callout--info">
                    <i class="ri-links-line" aria-hidden="true"></i>
                    <div class="rd-grow">
                        <div class="rd-field rd-field--mono">
                            <label class="rd-label" for="redirectUri">Client redirect URI</label>
                            <div class="rd-actions rd-actions--wrap">
                                <input class="rd-input rd-input--mono rd-grow" id="redirectUri" value="{{ $redirectUri }}" readonly>
                                <button type="button" class="rd-btn rd-btn--ghost" data-copy="#redirectUri" aria-label="Copy client redirect URI">
                                    <i class="ri-file-copy-line" aria-hidden="true"></i> Copy
                                </button>
                            </div>
                        </div>
                        <span class="rd-help">For admin-console sign-in, also register <code>{{ rtrim(str_replace('/api/oauth/callback', '', $redirectUri), '/') }}/admin/sso/&lt;key&gt;/callback</code>, where <code>&lt;key&gt;</code> is the provider key below.</span>
                    </div>
                </div>
            </div>

            <form method="POST" action="{{ route('admin.oauth-providers.store') }}" class="rd-stack rd-stack--lg">
                @csrf

                <div class="rd-form-grid rd-form-grid--2">
                    <div class="rd-field">
                        <label class="rd-label" for="op">Key (op)</label>
                        <input class="rd-input rd-input--mono" id="op" name="op" value="{{ old('op') }}" required aria-describedby="op-help"
                               @error('op') aria-invalid="true" aria-errormessage="op-error" @enderror>
                        <span class="rd-help" id="op-help">Unique client-facing key, such as <code>github</code> or <code>my-keycloak</code>.</span>
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
                        <span class="rd-help" id="type-help"><code>oidc</code> discovers configuration from the issuer.</span>
                        @error('type')<span class="rd-help rd-help--error" id="type-error">{{ $message }}</span>@enderror
                    </div>
                </div>

                <div class="rd-form-grid rd-form-grid--2">
                    <div class="rd-field">
                        <label class="rd-label" for="client_id">Client ID</label>
                        <input class="rd-input" id="client_id" name="client_id" value="{{ old('client_id') }}" required
                               @error('client_id') aria-invalid="true" aria-describedby="client-id-error" @enderror>
                        @error('client_id')<span class="rd-help rd-help--error" id="client-id-error">{{ $message }}</span>@enderror
                    </div>

                    <div class="rd-field">
                        <label class="rd-label" for="client_secret">Client secret</label>
                        <input class="rd-input" id="client_secret" name="client_secret" type="password" autocomplete="new-password" required
                               @error('client_secret') aria-invalid="true" aria-describedby="client-secret-error" @enderror>
                        @error('client_secret')<span class="rd-help rd-help--error" id="client-secret-error">{{ $message }}</span>@enderror
                    </div>
                </div>

                <div class="rd-field">
                    <label class="rd-label" for="issuer">Issuer</label>
                    <input class="rd-input rd-input--mono" id="issuer" name="issuer" value="{{ old('issuer') }}" placeholder="https://accounts.example.com" aria-describedby="issuer-help"
                           @error('issuer') aria-invalid="true" aria-errormessage="issuer-error" @enderror>
                    <span class="rd-help" id="issuer-help">Required for the <code>oidc</code> type; ignored for GitHub.</span>
                    @error('issuer')<span class="rd-help rd-help--error" id="issuer-error">{{ $message }}</span>@enderror
                </div>

                <div class="rd-field">
                    <label class="rd-label" for="scopes">Scopes</label>
                    <input class="rd-input rd-input--mono" id="scopes" name="scopes" value="{{ old('scopes') }}" placeholder="openid,profile,email" aria-describedby="scopes-help"
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
                    <button type="submit" class="rd-btn rd-btn--primary"><i class="ri-save-line" aria-hidden="true"></i> Create provider</button>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    $(function () {
        var presets = @json($presets);
        $('#preset').on('change', function () {
            var p = presets[this.value];
            if (!p) { $('#presetHint').text('Optional. You still enter the client ID + secret (and the real issuer host).'); return; }
            // Prefill the key with the preset id only if the field is still empty.
            if (!$('#op').val()) { $('#op').val(this.value); }
            $('#type').val(p.type);
            $('#scopes').val(p.scopes);
            $('#issuer').attr('placeholder', p.issuer_placeholder || 'https://accounts.example.com');
            if (p.issuer_placeholder) { $('#issuer').val(p.issuer_placeholder); }
            $('#pkce_method').val(p.pkce_method);
            $('input[name="pkce_enable"]').prop('checked', !!p.pkce_enable);
            $('#presetHint').text(p.hint);
        });
    });
</script>
@endpush
