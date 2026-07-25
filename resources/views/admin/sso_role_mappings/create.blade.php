@extends('layouts.admin')
@section('title', 'New SSO Role Mapping')

@php $canEdit = true; @endphp

@section('content')
    @include('admin.partials.flash')

    <header class="rd-page-header">
        <div>
            <p class="rd-page-header__eyebrow">People &amp; Access</p>
            <h1 class="rd-page-header__title">New SSO role mapping</h1>
            <p class="rd-page-header__description">Grant a console role to everyone in an identity-provider group.</p>
        </div>
    </header>

    <div class="rd-card rd-card--quiet rd-max-w-lg">
        <div class="rd-card__body rd-stack rd-stack--lg">
            @if ($errors->any())
                <div class="rd-callout rd-callout--danger" role="alert">
                    <i class="ri-error-warning-line" aria-hidden="true"></i>
                    <div><strong>Mapping not created.</strong> {{ $errors->first() }}</div>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.sso-role-mappings.store') }}" class="rd-stack rd-stack--lg">
                @csrf
                @include('admin.sso_role_mappings._form')

                <div class="rd-actions rd-actions--end">
                    <a href="{{ route('admin.sso-role-mappings.index') }}" class="rd-btn rd-btn--ghost">Cancel</a>
                    <button type="submit" class="rd-btn rd-btn--primary">Create mapping</button>
                </div>
            </form>
        </div>
    </div>
@endsection
