@extends('layouts.admin')
@section('title', 'Edit Admin Role')

@php
    $selectedPerms = old('perms', (array) $role->perms);
    $selectedScope = array_map('intval', old('scope', (array) $role->scope));
@endphp

@section('content')
    <header class="rd-page-header">
        <div class="rd-page-header__copy">
            <div class="rd-page-header__eyebrow">People &amp; Access / Admin Roles</div>
            <h1 class="rd-page-header__title">{{ $role->name }}</h1>
            <p class="rd-page-header__description">{{ $canEdit ? "Adjust this role's scope and delegated console permissions." : 'Review this role. Only a full administrator may change administrative authority.' }}</p>
        </div>
        <div class="rd-page-header__actions">
            <a href="{{ route('admin.roles.index') }}" class="rd-btn rd-btn--ghost"><i class="ri-arrow-left-line" aria-hidden="true"></i> Back</a>
        </div>
    </header>

    <div class="rd-card rd-card--quiet rd-max-w-lg">
        <div class="rd-card__body rd-stack rd-stack--lg">
            @if ($errors->any())
                <div class="rd-callout rd-callout--danger" role="alert">
                    <i class="ri-error-warning-line" aria-hidden="true"></i>
                    <div><strong>Role not saved.</strong> {{ $errors->first() }}</div>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.roles.update', $role) }}" class="rd-stack rd-stack--lg">
                @csrf
                @method('PUT')
                @include('admin.admin_roles._form')
                @if ($canEdit)
                <div class="rd-actions">
                    <button type="submit" class="rd-btn rd-btn--primary"><i class="ri-save-line" aria-hidden="true"></i> Save</button>
                </div>
                @endif
            </form>
        </div>
    </div>
@endsection
