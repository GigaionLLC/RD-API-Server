@php
    $selectedKind = old('provider_kind', $mapping->provider_kind ?? \App\Models\SsoRoleMapping::KIND_OIDC);
    $selectedKey = old('provider_key', $mapping->provider_key ?? '');
    $selectedRole = (int) old('admin_role_id', $mapping->admin_role_id ?? 0);
@endphp

<div class="rd-field">
    <label class="rd-label" for="provider_kind">Provider type</label>
    <select class="rd-input" id="provider_kind" name="provider_kind" required @unless($canEdit) disabled @endunless>
        <option value="{{ \App\Models\SsoRoleMapping::KIND_OIDC }}" @selected($selectedKind === \App\Models\SsoRoleMapping::KIND_OIDC)>OIDC</option>
        <option value="{{ \App\Models\SsoRoleMapping::KIND_LDAP }}" @selected($selectedKind === \App\Models\SsoRoleMapping::KIND_LDAP)>LDAP / FreeIPA</option>
    </select>
    <span class="rd-help">Mappings never match across providers, so the same group name in two directories stays distinct.</span>
</div>

<div class="rd-field">
    <label class="rd-label" for="provider_key">Provider</label>
    <input class="rd-input" id="provider_key" name="provider_key" value="{{ $selectedKey }}"
           maxlength="191" required @unless($canEdit) readonly @endunless
           list="sso-provider-keys" placeholder="e.g. authentik">
    <datalist id="sso-provider-keys">
        @foreach ($oidcProviders as $provider)
            <option value="{{ $provider->op }}">OIDC · {{ $provider->op }}</option>
        @endforeach
        @if ($ldapProviderKey !== '')
            <option value="{{ $ldapProviderKey }}">LDAP · current directory</option>
        @endif
    </datalist>
    <span class="rd-help">
        For OIDC this is the provider key (<code>op</code>) from the OAuth providers screen.
        @if ($ldapProviderKey !== '')
            For LDAP use <code>{{ $ldapProviderKey }}</code>, which identifies the currently configured directory.
        @else
            LDAP is not configured, so it has no provider identifier yet.
        @endif
    </span>
</div>

<div class="rd-field">
    <label class="rd-label" for="group_value">Group</label>
    <input class="rd-input" id="group_value" name="group_value" value="{{ old('group_value', $mapping->group_value ?? '') }}"
           maxlength="512" required @unless($canEdit) readonly @endunless
           placeholder="cn=rustdesk-admins,cn=groups,cn=accounts,dc=example,dc=com">
    <span class="rd-help">
        Matched exactly, with no wildcards. LDAP directories report full distinguished names, so paste
        the group's DN; whitespace around the <code>,</code> and <code>=</code> separators is ignored.
        OIDC providers report whatever string they are configured to emit.
    </span>
</div>

<div class="rd-field">
    <label class="rd-label" for="admin_role_id">Grants role</label>
    <select class="rd-input" id="admin_role_id" name="admin_role_id" required @unless($canEdit) disabled @endunless>
        <option value="">Select a role…</option>
        @foreach ($roles as $role)
            @php $isGlobal = $role->type === \App\Models\AdminRole::TYPE_GLOBAL; @endphp
            <option value="{{ $role->id }}" @selected($selectedRole === (int) $role->id) @disabled($isGlobal && ! $allowGlobal)>
                {{ $role->name }}@if ($isGlobal) — global{{ $allowGlobal ? '' : ' (not permitted)' }}@endif
            </option>
        @endforeach
    </select>
    <span class="rd-help">
        A mapping never grants the legacy full-administrator flag, only a console role.
        @unless ($allowGlobal)
            Roles of the <strong>global</strong> type cannot be selected: a global role grants every
            permission, so conferring one from a group claim needs
            <code>SSO_ROLE_MAPPING_ALLOW_GLOBAL=true</code> on the server. Note that the role form
            creates a global role unless you change its type, so a role made for mapping should be
            created as <strong>individual</strong> or <strong>group</strong>.
        @endunless
    </span>
</div>

@if ($allowGlobal)
    <div class="rd-callout rd-callout--danger" role="alert">
        <i class="ri-error-warning-line" aria-hidden="true"></i>
        <div>
            <strong>Global role mapping is enabled on this server.</strong>
            A global role grants every permission, including changing SMTP settings, which is the
            delivery channel for email sign-in codes. Anyone your identity provider places in a mapped
            group gains that authority at their next sign-in.
        </div>
    </div>
@endif

<div class="rd-field">
    <label class="rd-checkbox">
        <input type="hidden" name="enabled" value="0">
        <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $mapping->enabled ?? true)) @unless($canEdit) disabled @endunless>
        <span>Enabled</span>
    </label>
    <span class="rd-help">A disabled mapping grants nothing, and its existing grants are revoked at the next sign-in.</span>
</div>
