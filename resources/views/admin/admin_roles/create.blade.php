@extends('layouts.admin')
@section('title', 'New Admin Role')

@php
    $selectedPerms = old('perms', []);
    $selectedScope = array_map('intval', old('scope', []));
@endphp

@section('content')
    <header class="rd-page-header">
        <div class="rd-page-header__copy">
            <div class="rd-page-header__eyebrow">People &amp; Access / Admin Roles</div>
            <h1 class="rd-page-header__title">Create admin role</h1>
            <p class="rd-page-header__description">Define a clear scope and the console actions delegated administrators can use.</p>
        </div>
        <div class="rd-page-header__actions">
            <a href="{{ route('admin.roles.index') }}" class="rd-btn rd-btn--ghost"><i class="ri-arrow-left-line"></i> Back</a>
        </div>
    </header>

    <div class="rd-card rd-card--quiet rd-max-w-lg">
        <div class="rd-card__body rd-stack rd-stack--lg">
            @if ($errors->any())
                <div class="rd-callout rd-callout--danger" role="alert">
                    <i class="ri-error-warning-line" aria-hidden="true"></i>
                    <div><strong>Role not created.</strong> {{ $errors->first() }}</div>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.roles.store') }}" class="rd-stack rd-stack--lg">
                @csrf
                @include('admin.admin_roles._form')
                <div class="rd-actions">
                    <button type="submit" class="rd-btn rd-btn--primary"><i class="ri-save-line"></i> Create role</button>
                </div>
            </form>
        </div>
    </div>
@endsection
