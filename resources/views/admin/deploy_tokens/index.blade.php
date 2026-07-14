@extends('layouts.admin')
@section('title', 'Deploy Tokens')
@php($canEdit = auth()->user()->hasPermission('deploy.edit'))

@section('content')
    @include('admin.partials.flash')

    <header class="rd-page-header">
        <div class="rd-page-header__copy">
            <p class="rd-page-header__eyebrow">Policies &amp; Rollout</p>
            <h1 class="rd-page-header__title">Deploy Tokens</h1>
            <p class="rd-page-header__description">{{ $canEdit ? 'Create enrollment credentials and review tokens used for device rollout.' : 'Review tokens used for device rollout.' }}</p>
        </div>
        <div class="rd-page-header__actions">
            <a href="{{ route('admin.devices.pending') }}" class="rd-btn rd-btn--ghost"><i class="ri-shield-check-line" aria-hidden="true"></i> Pending Devices</a>
        </div>
    </header>

    <div class="rd-stack rd-stack--lg">

    @if ($newToken)
        <div class="rd-callout rd-callout--success rd-actions rd-align-start">
            <i class="ri-key-2-line rd-success" aria-hidden="true"></i>
            <div class="rd-stack rd-stack--sm rd-grow">
                <strong>New deploy token</strong>
                <span class="rd-help">Copy this token now — it will not be shown again.</span>
                <input class="rd-input rd-mono" type="text" value="{{ $newToken }}" readonly onclick="this.select()" aria-label="New deploy token">
            </div>
        </div>
    @endif

    @if ($canEdit)
    <div class="rd-card rd-max-w-md">
        <div class="rd-card__header">
            <h3 class="rd-card__title">Create token</h3>
        </div>
        <div class="rd-card__body rd-stack rd-stack--md">
            @if ($errors->any())
                <div class="rd-callout rd-callout--danger rd-actions rd-align-start" role="alert">
                    <i class="ri-error-warning-line rd-danger" aria-hidden="true"></i><span>{{ $errors->first() }}</span>
                </div>
            @endif
            <form method="POST" action="{{ route('admin.deploy-tokens.store') }}">
                @csrf
                <div class="rd-form-grid rd-form-grid--2">
                <div class="rd-field">
                    <label class="rd-label" for="name">Name</label>
                    <input class="rd-input" id="name" name="name" value="{{ old('name') }}" placeholder="e.g. Office rollout">
                </div>
                <div class="rd-field">
                    <label class="rd-label" for="expires_at">Expires at</label>
                    <input class="rd-input" id="expires_at" name="expires_at" type="date" value="{{ old('expires_at') }}">
                    <span class="rd-help">Leave empty for a token that never expires.</span>
                </div>
                <div class="rd-actions rd-form-grid__full">
                    <button type="submit" class="rd-btn rd-btn--primary"><i class="ri-add-line" aria-hidden="true"></i> Create token</button>
                </div>
                </div>
            </form>
        </div>
    </div>
    @endif

    <div class="rd-card rd-card--flush">
        <div class="rd-card__header">
            <h3 class="rd-card__title">Your deploy tokens</h3>
        </div>
        <div class="rd-table-wrap" role="region" aria-label="Deploy tokens" tabindex="0">
            <table class="rd-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Created</th>
                        <th>Expires</th>
                        <th>Last used</th>
                        <th class="rd-table__actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($tokens as $token)
                    <tr>
                        <td><span class="rd-table__primary">{{ $token->name ?: '—' }}</span></td>
                        <td class="rd-muted">{{ $token->created_at?->format('Y-m-d H:i') ?? '—' }}</td>
                        <td class="rd-muted">{{ $token->expires_at?->format('Y-m-d') ?? 'Never' }}</td>
                        <td class="rd-muted">{{ $token->last_used_at?->diffForHumans() ?? '—' }}</td>
                        <td class="rd-table__actions">
                            @if ($canEdit)
                            <div class="rd-actions rd-actions--end">
                                <form method="POST" action="{{ route('admin.deploy-tokens.destroy', $token) }}" class="m-0">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="rd-btn rd-btn--danger" data-confirm="Revoke this deploy token?"><i class="ri-delete-bin-line" aria-hidden="true"></i> Revoke</button>
                                </form>
                            </div>
                            @else
                                <span class="rd-muted">View only</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5"><div class="rd-empty"><i class="rd-empty__icon ri-key-2-line" aria-hidden="true"></i><p class="rd-empty__title">No deploy tokens yet</p><p class="rd-empty__body">Create a token to enroll devices during rollout.</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('admin.partials.pagination', ['paginator' => $tokens])
    </div>
    </div>
@endsection
