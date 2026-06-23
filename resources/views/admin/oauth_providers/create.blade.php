@extends('layouts.admin')
@section('title', 'New OAuth Provider')

@section('content')
    <div class="rd-breadcrumb">Access / OAuth Providers / New</div>

    <div class="rd-card" style="max-width:640px;">
        <div class="rd-card__header">
            <h3 class="rd-card__title">New OAuth / OIDC provider</h3>
            <a href="{{ route('admin.oauth-providers.index') }}" class="rd-btn rd-btn--ghost"><i class="ri-arrow-left-line"></i> Back</a>
        </div>
        <div class="rd-card__body">
            @if ($errors->any())
                <div class="rd-toast rd-toast--error" style="margin-bottom:16px;">
                    <i class="ri-error-warning-line"></i><span>{{ $errors->first() }}</span>
                </div>
            @endif
            {{-- Guided setup: pick a provider to prefill type / scopes / PKCE / issuer shape. --}}
            <div class="rd-field">
                <label class="rd-label" for="preset"><i class="ri-magic-line"></i> Quick setup</label>
                <select class="rd-select" id="preset">
                    <option value="">— Choose a provider to prefill —</option>
                    @foreach ($presets as $key => $p)
                        <option value="{{ $key }}">{{ $p['label'] }}</option>
                    @endforeach
                </select>
                <span class="rd-help" id="presetHint">Optional. You still enter the client ID + secret (and the real issuer host).</span>
            </div>

            <div class="rd-field">
                <label class="rd-label">Redirect URI <span class="rd-muted">(register this with the provider)</span></label>
                <div class="rd-row" style="gap:8px;">
                    <input class="rd-input" id="redirectUri" value="{{ $redirectUri }}" readonly style="font-family:monospace;">
                    <button type="button" class="rd-btn rd-btn--ghost" onclick="navigator.clipboard.writeText(document.getElementById('redirectUri').value);RD.toast('Copied','success');"><i class="ri-file-copy-line"></i></button>
                </div>
            </div>

            <form method="POST" action="{{ route('admin.oauth-providers.store') }}">
                @csrf

                <div class="rd-field">
                    <label class="rd-label" for="op">Key (op)</label>
                    <input class="rd-input" id="op" name="op" value="{{ old('op') }}" required>
                    <span class="rd-help">Unique provider key the client requests, e.g. <code>github</code>, <code>google</code>, <code>my-keycloak</code>.</span>
                </div>

                <div class="rd-field">
                    <label class="rd-label" for="type">Type</label>
                    <select class="rd-select" id="type" name="type">
                        @foreach ($types as $type)
                            <option value="{{ $type }}" @selected(old('type', $provider->type) === $type)>{{ $type }}</option>
                        @endforeach
                    </select>
                    <span class="rd-help"><code>oidc</code> requires an issuer (its <code>/.well-known/openid-configuration</code> is discovered).</span>
                </div>

                <div class="rd-field">
                    <label class="rd-label" for="client_id">Client ID</label>
                    <input class="rd-input" id="client_id" name="client_id" value="{{ old('client_id') }}" required>
                </div>

                <div class="rd-field">
                    <label class="rd-label" for="client_secret">Client Secret</label>
                    <input class="rd-input" id="client_secret" name="client_secret" type="password" autocomplete="new-password" required>
                </div>

                <div class="rd-field">
                    <label class="rd-label" for="issuer">Issuer</label>
                    <input class="rd-input" id="issuer" name="issuer" value="{{ old('issuer') }}" placeholder="https://accounts.example.com">
                    <span class="rd-help">Required for the <code>oidc</code> type; ignored for github.</span>
                </div>

                <div class="rd-field">
                    <label class="rd-label" for="scopes">Scopes</label>
                    <input class="rd-input" id="scopes" name="scopes" value="{{ old('scopes') }}" placeholder="openid,profile,email">
                    <span class="rd-help">Comma-separated. Defaults to <code>openid,profile,email</code> when blank.</span>
                </div>

                <div class="rd-field">
                    <label class="rd-label" for="pkce_method">PKCE method</label>
                    <select class="rd-select" id="pkce_method" name="pkce_method">
                        <option value="S256" @selected(old('pkce_method', $provider->pkce_method) === 'S256')>S256</option>
                        <option value="plain" @selected(old('pkce_method', $provider->pkce_method) === 'plain')>plain</option>
                    </select>
                </div>

                <div class="rd-field">
                    <label class="rd-label">Options</label>
                    <label class="rd-row" style="gap:8px;align-items:center;margin-bottom:6px;">
                        <input type="checkbox" name="auto_register" value="1" @checked(old('auto_register', $provider->auto_register))>
                        <span class="rd-muted">Auto-register new users on first login</span>
                    </label>
                    <label class="rd-row" style="gap:8px;align-items:center;margin-bottom:6px;">
                        <input type="checkbox" name="pkce_enable" value="1" @checked(old('pkce_enable', $provider->pkce_enable))>
                        <span class="rd-muted">Enable PKCE</span>
                    </label>
                    <label class="rd-row" style="gap:8px;align-items:center;">
                        <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $provider->enabled))>
                        <span class="rd-muted">Enabled (offered to clients)</span>
                    </label>
                </div>

                <div class="rd-row" style="margin-top:8px;">
                    <button type="submit" class="rd-btn rd-btn--primary"><i class="ri-save-line"></i> Create provider</button>
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
