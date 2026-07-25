@extends('layouts.admin')
@section('title', 'SSO Role Mappings')

@php
    $kindLabels = [
        \App\Models\SsoRoleMapping::KIND_LDAP => 'LDAP / FreeIPA',
        \App\Models\SsoRoleMapping::KIND_OIDC => 'OIDC',
    ];
@endphp

@section('content')
    @include('admin.partials.flash')

    <header class="rd-page-header">
        <div>
            <p class="rd-page-header__eyebrow">People &amp; Access</p>
            <h1 class="rd-page-header__title">SSO role mappings</h1>
            <p class="rd-page-header__description">
                Membership of an identity-provider group grants a console role. Mappings are applied
                at sign-in and revoked when a user leaves the group.
            </p>
        </div>
        @if ($canEdit)
            <div class="rd-page-header__actions">
                <a href="{{ route('admin.sso-role-mappings.create') }}" class="rd-btn rd-btn--primary">
                    <i class="ri-add-line" aria-hidden="true"></i> New mapping
                </a>
            </div>
        @endif
    </header>

    @unless ($allowGlobal)
        <div class="rd-callout rd-callout--info">
            <i class="ri-information-line" aria-hidden="true"></i>
            <div>
                <strong>Global roles cannot be mapped.</strong>
                A global role grants every permission, so conferring one from a group claim requires
                <code>SSO_ROLE_MAPPING_ALLOW_GLOBAL=true</code> on the server. Mappings to individual
                and group-scoped roles are unaffected.
            </div>
        </div>
    @endunless

    <div class="rd-card rd-card--flush">
        <div class="rd-table-wrap" role="region" aria-label="SSO role mappings" tabindex="0">
            <table class="rd-table">
                <thead>
                    <tr>
                        <th scope="col">Provider</th>
                        <th scope="col">Group</th>
                        <th scope="col">Grants role</th>
                        <th scope="col">Status</th>
                        <th scope="col"><span class="rd-visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($mappings as $mapping)
                        <tr>
                            <td>
                                <span class="rd-badge">{{ $kindLabels[$mapping->provider_kind] ?? $mapping->provider_kind }}</span>
                                <div class="rd-text-muted rd-text-sm">{{ $mapping->provider_key }}</div>
                            </td>
                            <td><code>{{ $mapping->group_value }}</code></td>
                            <td>
                                {{ $mapping->role?->name ?? '—' }}
                                @if ($mapping->role?->type === \App\Models\AdminRole::TYPE_GLOBAL)
                                    <span class="rd-badge rd-badge--danger">global</span>
                                @endif
                            </td>
                            <td>
                                @if ($mapping->enabled)
                                    <span class="rd-badge rd-badge--success">Enabled</span>
                                @else
                                    <span class="rd-badge">Disabled</span>
                                @endif
                            </td>
                            <td class="rd-table__actions">
                                <div class="rd-actions rd-actions--end rd-actions--wrap">
                                    <a href="{{ route('admin.sso-role-mappings.edit', $mapping) }}" class="rd-btn rd-btn--ghost rd-btn--sm">
                                        @if ($canEdit)
                                            <i class="ri-pencil-line" aria-hidden="true"></i> Edit
                                        @else
                                            <i class="ri-eye-line" aria-hidden="true"></i> View
                                        @endif
                                    </a>
                                    @if ($canEdit)
                                        <form method="POST" action="{{ route('admin.sso-role-mappings.destroy', $mapping) }}" class="m-0">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="rd-btn rd-btn--ghost rd-btn--sm rd-btn--danger"
                                                    aria-label="Delete mapping for {{ $mapping->group_value }}"
                                                    data-confirm="Delete this mapping? The role it granted is revoked from every user immediately.">
                                                <i class="ri-delete-bin-line" aria-hidden="true"></i> Delete
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <div class="rd-empty">
                                    <i class="ri-git-merge-line rd-empty__icon" aria-hidden="true"></i>
                                    <p class="rd-empty__title">No mappings yet</p>
                                    <p class="rd-empty__body">
                                        Add one to let a directory or SSO group grant a console role automatically.
                                    </p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @include('admin.partials.pagination', ['paginator' => $mappings])

    @if ($recentSyncs->isNotEmpty())
        <section class="rd-card rd-card--quiet">
            <div class="rd-card__body rd-stack rd-stack--sm">
                <h2 class="rd-card__title">Recent sign-in reconciliations</h2>
                <p class="rd-help">
                    Why a sign-in did or did not change someone's roles. A skipped result is recorded
                    as deliberately as a change, because a silent no-op is the failure this feature is
                    most likely to produce.
                </p>
                <div class="rd-table-wrap" role="region" aria-label="Recent reconciliations" tabindex="0">
                    <table class="rd-table">
                        <thead>
                            <tr>
                                <th scope="col">When</th>
                                <th scope="col">User</th>
                                <th scope="col">Provider</th>
                                <th scope="col">Outcome</th>
                                <th scope="col">Change</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recentSyncs as $sync)
                                <tr>
                                    <td>{{ $sync->created_at?->diffForHumans() }}</td>
                                    <td>{{ $sync->username }}</td>
                                    <td>{{ $kindLabels[$sync->provider_kind] ?? $sync->provider_kind }}</td>
                                    <td><code>{{ $sync->outcome }}</code></td>
                                    <td class="rd-text-sm">
                                        @foreach (($sync->granted ?? []) as $entry)
                                            <span class="rd-badge rd-badge--success">+{{ $entry['name'] ?? $entry['id'] ?? '' }}</span>
                                        @endforeach
                                        @foreach (($sync->revoked ?? []) as $entry)
                                            <span class="rd-badge rd-badge--danger">−{{ $entry['name'] ?? $entry['id'] ?? '' }}</span>
                                        @endforeach
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    @endif
@endsection
