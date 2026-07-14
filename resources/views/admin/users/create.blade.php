@extends('layouts.admin')
@section('title', 'New User')

@section('content')
    <header class="rd-page-header">
        <div class="rd-page-header__copy">
            <div class="rd-page-header__eyebrow">People &amp; Access / Users</div>
            <h1 class="rd-page-header__title">Create user</h1>
            <p class="rd-page-header__description">Add an account, assign its group, and choose its sign-in policy.</p>
        </div>
        <div class="rd-page-header__actions">
            <a href="{{ route('admin.users.index') }}" class="rd-btn rd-btn--ghost"><i class="ri-arrow-left-line" aria-hidden="true"></i> Back</a>
        </div>
    </header>

    <div class="rd-card rd-card--quiet rd-max-w-lg">
        <div class="rd-card__body rd-stack rd-stack--lg">
            @if ($errors->any())
                <div class="rd-callout rd-callout--danger" role="alert">
                    <i class="ri-error-warning-line" aria-hidden="true"></i>
                    <div><strong>User not created.</strong> {{ $errors->first() }}</div>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.users.store') }}" class="rd-stack rd-stack--lg">
                @csrf
                <div class="rd-form-grid rd-form-grid--2">
                <div class="rd-field">
                    <label class="rd-label" for="username">Username</label>
                    <input class="rd-input" id="username" name="username" value="{{ old('username') }}" required
                           @error('username') aria-invalid="true" aria-describedby="username-error" @enderror>
                    @error('username')<span class="rd-help rd-help--error" id="username-error">{{ $message }}</span>@enderror
                </div>
                <div class="rd-field">
                    <label class="rd-label" for="password">Password</label>
                    <input class="rd-input" id="password" name="password" type="password" autocomplete="new-password" required aria-describedby="password-help"
                           @error('password') aria-invalid="true" aria-errormessage="password-error" @enderror>
                    <span class="rd-help" id="password-help">At least 6 characters.</span>
                    @error('password')<span class="rd-help rd-help--error" id="password-error">{{ $message }}</span>@enderror
                </div>
                <div class="rd-field">
                    <label class="rd-label" for="email">Email</label>
                    <input class="rd-input" id="email" name="email" type="email" value="{{ old('email') }}"
                           @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                    @error('email')<span class="rd-help rd-help--error" id="email-error">{{ $message }}</span>@enderror
                </div>
                <div class="rd-field">
                    <label class="rd-label" for="display_name">Display name</label>
                    <input class="rd-input" id="display_name" name="display_name" value="{{ old('display_name') }}">
                </div>
                <div class="rd-field">
                    <label class="rd-label" for="group_id">Group</label>
                    <select class="rd-select" id="group_id" name="group_id">
                        <option value="">— None —</option>
                        @foreach ($groups as $g)
                            <option value="{{ $g->id }}" @selected(old('group_id') == $g->id)>{{ $g->name }}</option>
                        @endforeach
                    </select>
                </div>
                @if ($canManageAdminAccess)
                <div class="rd-field">
                    <label class="rd-label" for="is_admin">Role</label>
                    <select class="rd-select" id="is_admin" name="is_admin">
                        <option value="0" @selected(! old('is_admin'))>User</option>
                        <option value="1" @selected(old('is_admin'))>Administrator</option>
                    </select>
                </div>
                @endif
                <div class="rd-field">
                    <label class="rd-label" for="status">Status</label>
                    <select class="rd-select" id="status" name="status">
                        <option value="{{ \App\Models\User::STATUS_NORMAL }}" @selected(old('status', \App\Models\User::STATUS_NORMAL) == \App\Models\User::STATUS_NORMAL)>Active</option>
                        <option value="{{ \App\Models\User::STATUS_DISABLED }}" @selected(old('status') === (string) \App\Models\User::STATUS_DISABLED)>Disabled</option>
                        <option value="{{ \App\Models\User::STATUS_UNVERIFIED }}" @selected(old('status') === (string) \App\Models\User::STATUS_UNVERIFIED)>Unverified</option>
                    </select>
                </div>
                <div class="rd-field">
                    <label class="rd-label" for="login_verify">Login verification</label>
                    <select class="rd-select" id="login_verify" name="login_verify">
                        <option value="off" @selected(old('login_verify', 'off') === 'off')>Off</option>
                        <option value="email" @selected(old('login_verify') === 'email')>Email code</option>
                        <option value="totp" @selected(old('login_verify') === 'totp')>TOTP</option>
                    </select>
                </div>
                <div class="rd-field">
                    <label class="rd-label" for="note">Note</label>
                    <input class="rd-input" id="note" name="note" value="{{ old('note') }}">
                </div>
                </div>
                <div class="rd-actions">
                    <button type="submit" class="rd-btn rd-btn--primary"><i class="ri-save-line" aria-hidden="true"></i> Create user</button>
                </div>
            </form>
        </div>
    </div>
@endsection
