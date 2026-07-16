@extends('layouts.admin')
@section('title', 'Edit User')

@section('content')
    <header class="rd-page-header">
        <div class="rd-page-header__copy">
            <div class="rd-page-header__eyebrow">People &amp; Access / Users</div>
            <h1 class="rd-page-header__title">{{ $user->username }}</h1>
            <p class="rd-page-header__description">{{ $canEdit ? 'Manage identity, account state, and sign-in policy.' : 'Review identity, account state, and sign-in policy.' }}</p>
        </div>
        <div class="rd-page-header__actions">
            <a href="{{ route('admin.users.index') }}" class="rd-btn rd-btn--ghost"><i class="ri-arrow-left-line" aria-hidden="true"></i> Back</a>
        </div>
    </header>

    <div class="rd-form-grid rd-form-grid--2 rd-align-start">
        <div class="rd-card rd-card--quiet">
            <div class="rd-card__header">
                <h2 class="rd-card__title">Account details</h2>
            </div>
            <div class="rd-card__body">
                <form class="rd-liveform rd-stack rd-stack--lg" data-url="{{ route('admin.users.update', $user) }}" data-method="PUT">
                    <div class="rd-form-grid rd-form-grid--2">
                    <div class="rd-field">
                        <label class="rd-label" for="username">Username</label>
                        <input class="rd-input rd-input--mono" id="username" value="{{ $user->username }}" disabled aria-describedby="username-help">
                        <span class="rd-help" id="username-help">Username cannot be changed.</span>
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="email">Email</label>
                        <input class="rd-input" id="email" name="email" type="email" value="{{ $user->email }}" aria-describedby="email-policy-help" @disabled(! $canEdit)>
                        <span class="rd-help" id="email-policy-help">Required when login verification uses an email code.</span>
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="display_name">Display name</label>
                        <input class="rd-input" id="display_name" name="display_name" value="{{ $user->display_name }}" @disabled(! $canEdit)>
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="group_id">Group</label>
                        <select class="rd-select" id="group_id" name="group_id" @disabled(! $canEdit)>
                            <option value="">— None —</option>
                            @foreach ($groups as $g)
                                <option value="{{ $g->id }}" @selected($user->group_id == $g->id)>{{ $g->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if ($canManageAdminAccess)
                    <div class="rd-field">
                        <label class="rd-label" for="is_admin">Role</label>
                        <select class="rd-select" id="is_admin" name="is_admin" aria-describedby="role-help">
                            <option value="0" @selected(! $user->is_admin)>User</option>
                            <option value="1" @selected($user->is_admin)>Administrator — Full access (global)</option>
                        </select>
                        <span class="rd-help" id="role-help">Full access (global) overrides any scoped admin roles below.</span>
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="admin_roles">Admin roles</label>
                        <select class="rd-select" id="admin_roles" multiple size="5" data-roles-multiselect data-target="#admin_role_ids" aria-describedby="admin-roles-help" @disabled($adminRoles->isEmpty())>
                            @foreach ($adminRoles as $r)
                                <option value="{{ $r->id }}" @selected(in_array((int) $r->id, $assignedRoleIds, true))>{{ $r->name }}</option>
                            @endforeach
                        </select>
                        <input type="hidden" id="admin_role_ids" name="admin_role_ids" value="{{ implode(',', $assignedRoleIds) }}">
                        <span class="rd-help" id="admin-roles-help">
                            @if ($adminRoles->isEmpty())
                                No admin roles defined yet. Create them under People &amp; Access / Admin Roles.
                            @else
                                Scoped, delegated console permissions. Ignored when Full access (global) is set.
                            @endif
                        </span>
                    </div>
                    @endif
                    <div class="rd-field">
                        <label class="rd-label" for="status">Status</label>
                        <select class="rd-select" id="status" name="status" @disabled(! $canEdit)>
                            <option value="{{ \App\Models\User::STATUS_NORMAL }}" @selected($user->status === \App\Models\User::STATUS_NORMAL)>Active</option>
                            <option value="{{ \App\Models\User::STATUS_DISABLED }}" @selected($user->status === \App\Models\User::STATUS_DISABLED)>Disabled</option>
                            <option value="{{ \App\Models\User::STATUS_UNVERIFIED }}" @selected($user->status === \App\Models\User::STATUS_UNVERIFIED)>Unverified</option>
                        </select>
                    </div>
                    <div class="rd-field">
                        <div class="rd-label" id="login-policy-label">Login policy</div>
                        <label class="rd-check">
                            <input type="hidden" name="force_sso" value="0">
                            <input type="checkbox" id="force_sso" name="force_sso" value="1" @checked($user->force_sso) @disabled(! $canEdit)>
                            <span>Require SSO login (block local password; LDAP/OIDC still allowed)</span>
                        </label>
                    </div>
                    <div class="rd-field">
                        @if ($hasActiveTotp)
                            <div class="rd-label">Login verification</div>
                            <div class="rd-callout rd-callout--info" role="status">
                                <i class="ri-shield-check-line" aria-hidden="true"></i>
                                <p>Authenticator app enabled. This factor is read-only here; accounts with console access manage it from their personal two-factor settings.</p>
                            </div>
                        @else
                            <label class="rd-label" for="login_verify">Login verification</label>
                            <select class="rd-select" id="login_verify" name="login_verify" aria-describedby="login-verify-help" @disabled(! $canEdit)>
                                <option value="off" @selected($user->login_verify === 'off')>Off</option>
                                <option value="email" @selected($user->login_verify === 'email')>Email code</option>
                            </select>
                            <span class="rd-help" id="login-verify-help">Email code requires the email field above. TOTP enrollment is available only to accounts with console access, from their personal two-factor settings.</span>
                        @endif
                    </div>
                    <div class="rd-field">
                        <label class="rd-label" for="note">Note</label>
                        <input class="rd-input" id="note" name="note" value="{{ $user->note }}" @disabled(! $canEdit)>
                    </div>
                    </div>
                    @if ($canEdit)
                    <div class="rd-actions">
                        <button type="submit" class="rd-btn rd-btn--primary rd-btn--save" data-state="idle">Save</button>
                    </div>
                    @endif
                </form>
            </div>
        </div>

        @if ($canEdit && ! $isFederated)
        <div class="rd-card rd-card--quiet">
            <div class="rd-card__header">
                <h2 class="rd-card__title">Reset password</h2>
            </div>
            <div class="rd-card__body">
                <form class="rd-liveform rd-stack rd-stack--lg" data-url="{{ route('admin.users.password', $user) }}" data-method="PUT">
                    <div class="rd-field">
                        <label class="rd-label" for="password">New password</label>
                        <input class="rd-input" id="password" name="password" type="password" autocomplete="new-password" minlength="{{ \App\Support\AccountPasswordPolicy::MIN_LENGTH }}" maxlength="{{ \App\Support\AccountPasswordPolicy::MAX_LENGTH }}" placeholder="••••••••••••" aria-describedby="new-password-help" required>
                        <span class="rd-help" id="new-password-help">{{ \App\Support\AccountPasswordPolicy::MIN_LENGTH }} to {{ \App\Support\AccountPasswordPolicy::MAX_LENGTH }} characters. This signs the user out everywhere and revokes their client, API, and deployment credentials. Linked LDAP and SSO accounts cannot be reset here; change access at their identity provider.</span>
                    </div>
                    <div class="rd-actions">
                        <button type="submit" class="rd-btn rd-btn--primary rd-btn--save" data-state="idle">Reset password</button>
                    </div>
                </form>
            </div>
        </div>
        @elseif ($canEdit)
        <div class="rd-card rd-card--quiet">
            <div class="rd-card__header">
                <h2 class="rd-card__title">Password managed externally</h2>
            </div>
            <div class="rd-card__body">
                <div class="rd-callout rd-callout--info" role="status">
                    <i class="ri-shield-keyhole-line" aria-hidden="true"></i>
                    <p>This account is linked to LDAP or SSO. Change its access at the identity provider; a local password cannot be assigned here.</p>
                </div>
            </div>
        </div>
        @endif
    </div>
@endsection

@if ($canManageAdminAccess)
@push('scripts')
<script>
    $(function () {
        // Mirror the admin-roles multi-select into a hidden CSV field so the live-save form
        // (which flattens array inputs) submits the full set, matching the groups editor.
        $('select[data-roles-multiselect]').each(function () {
            var $sel = $(this);
            var $target = $($sel.data('target'));
            $sel.on('change', function () {
                $target.val(($sel.val() || []).join(','));
                $sel.closest('form').trigger('change');
            });
        });
    });
</script>
@endpush
@endif
