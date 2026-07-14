@extends('layouts.admin')
@section('title', 'OAuth Providers')
@php($canEdit = auth()->user()->hasPermission('oauth.edit'))

@section('content')
    @include('admin.partials.flash')

    <header class="rd-page-header">
        <div class="rd-page-header__copy">
            <p class="rd-page-header__eyebrow">Integrations</p>
            <h1 class="rd-page-header__title">OAuth / OIDC Providers</h1>
            <p class="rd-page-header__description">Manage external identity providers and automatic user registration.</p>
        </div>
        @if ($canEdit)
        <div class="rd-page-header__actions">
            <a href="{{ route('admin.oauth-providers.create') }}" class="rd-btn rd-btn--primary"><i class="ri-add-line" aria-hidden="true"></i> New provider</a>
        </div>
        @endif
    </header>

    <div class="rd-card rd-card--flush">
        <div class="rd-table-wrap" role="region" aria-label="OAuth and OIDC providers" tabindex="0">
            <table class="rd-table">
                <thead>
                    <tr>
                        <th>Key (op)</th>
                        <th>Type</th>
                        <th>Client ID</th>
                        <th>Auto-register</th>
                        <th>Status</th>
                        <th class="rd-table__actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($providers as $provider)
                    <tr>
                        <td><span class="rd-table__primary">{{ $provider->op }}</span></td>
                        <td><span class="rd-badge rd-badge--muted">{{ $provider->type }}</span></td>
                        <td class="rd-muted rd-mono">{{ $provider->client_id }}</td>
                        <td class="rd-muted">{{ $provider->auto_register ? 'Yes' : 'No' }}</td>
                        <td>
                            @if ($provider->enabled)
                                <span class="rd-badge rd-badge--online"><span class="dot"></span> Enabled</span>
                            @else
                                <span class="rd-badge rd-badge--offline"><span class="dot"></span> Disabled</span>
                            @endif
                        </td>
                        <td class="rd-table__actions">
                            <div class="rd-actions rd-actions--end rd-actions--wrap">
                                <a href="{{ route('admin.oauth-providers.edit', $provider) }}" class="rd-btn rd-btn--ghost"><i class="{{ $canEdit ? 'ri-pencil-line' : 'ri-eye-line' }}" aria-hidden="true"></i> {{ $canEdit ? 'Edit' : 'View' }}</a>
                                @if ($canEdit)
                                <form method="POST" action="{{ route('admin.oauth-providers.destroy', $provider) }}" class="m-0">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="rd-btn rd-btn--danger" data-confirm="Delete provider '{{ $provider->op }}'?" aria-label="Delete {{ $provider->op }} provider" title="Delete provider"><i class="ri-delete-bin-line" aria-hidden="true"></i></button>
                                </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6"><div class="rd-empty"><i class="rd-empty__icon ri-shield-keyhole-line" aria-hidden="true"></i><p class="rd-empty__title">No OAuth providers yet</p><p class="rd-empty__body">Add a provider to enable external sign-in.</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('admin.partials.pagination', ['paginator' => $providers])
    </div>
@endsection
