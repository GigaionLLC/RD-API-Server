@extends('layouts.admin')
@section('title', 'LDAP / Active Directory')

@section('content')
    <header class="rd-page-header">
        <div class="rd-page-header__copy">
            <div class="rd-page-header__eyebrow">Integrations</div>
            <h1 class="rd-page-header__title">LDAP / Active Directory</h1>
            <p class="rd-page-header__description">Review directory authentication settings and verify the configured connection.</p>
        </div>
        <div class="rd-page-header__actions">
            <div class="rd-actions rd-actions--wrap">
                @if ($enabled)
                    <span class="rd-badge rd-badge--online"><span class="dot"></span> Enabled</span>
                @else
                    <span class="rd-badge rd-badge--offline"><span class="dot"></span> Disabled</span>
                @endif
                <form method="POST" action="{{ route('admin.ldap.test') }}" class="m-0">
                    @csrf
                    <button type="submit" class="rd-btn rd-btn--primary" @disabled(! $enabled)>
                        <i class="ri-plug-line"></i> Test connection
                    </button>
                </form>
            </div>
        </div>
    </header>

    @include('admin.partials.flash')

    <div class="rd-stack rd-stack--lg">
        @unless ($extensionLoaded)
            <div class="rd-callout rd-callout--danger" role="alert">
                <i class="ri-error-warning-line" aria-hidden="true"></i>
                <div>
                    <strong>LDAP runtime unavailable.</strong>
                    The PHP <code>ldap</code> extension is not loaded — LDAP authentication will not work.
                </div>
            </div>
        @endunless

        <div class="rd-callout rd-callout--info">
            <i class="ri-information-line" aria-hidden="true"></i>
            <div>
                These settings are read-only and configured via environment variables
                (<code>LDAP_*</code> in <code>config/ldap.php</code>). LDAP is disabled by default;
                when enabled, client and admin login try LDAP first and fall back to local passwords.
            </div>
        </div>

        <section class="rd-card rd-card--quiet" aria-labelledby="ldap-config-title">
            <div class="rd-card__header">
                <h2 class="rd-card__title" id="ldap-config-title">Configuration snapshot</h2>
            </div>
            <div class="rd-card__body">
            <table class="rd-table">
                <caption class="visually-hidden">Current LDAP and Active Directory configuration</caption>
                <tbody>
                    <tr>
                        <th scope="row">Status</th>
                        <td>{{ $enabled ? 'Enabled' : 'Disabled' }}</td>
                    </tr>
                    <tr>
                        <th scope="row">Host</th>
                        <td class="rd-mono">{{ $host !== '' ? $host : '—' }}</td>
                    </tr>
                    <tr>
                        <th scope="row">Port</th>
                        <td class="rd-mono">{{ $port }}</td>
                    </tr>
                    <tr>
                        <th scope="row">Base DN</th>
                        <td class="rd-mono">{{ $baseDn !== '' ? $baseDn : '—' }}</td>
                    </tr>
                    <tr>
                        <th scope="row">Bind DN (service account)</th>
                        <td class="rd-mono">{{ $bindDn !== '' ? $bindDn : '(anonymous)' }}</td>
                    </tr>
                    <tr>
                        <th scope="row">Bind password</th>
                        <td>{{ $bindPasswordSet ? '•••••••• (set)' : '(not set)' }}</td>
                    </tr>
                    <tr>
                        <th scope="row">User filter</th>
                        <td><code>{{ $userFilter !== '' ? $userFilter : '—' }}</code></td>
                    </tr>
                    <tr>
                        <th scope="row">Username attribute</th>
                        <td class="rd-mono">{{ $usernameAttr !== '' ? $usernameAttr : '—' }}</td>
                    </tr>
                    <tr>
                        <th scope="row">Email attribute</th>
                        <td class="rd-mono">{{ $emailAttr !== '' ? $emailAttr : '—' }}</td>
                    </tr>
                    <tr>
                        <th scope="row">Display-name attribute</th>
                        <td class="rd-mono">{{ $displayNameAttr !== '' ? $displayNameAttr : '—' }}</td>
                    </tr>
                    <tr>
                        <th scope="row">StartTLS</th>
                        <td>{{ $useStartTls ? 'On' : 'Off' }}</td>
                    </tr>
                    <tr>
                        <th scope="row">TLS certificate verification</th>
                        <td>{{ $tlsVerify ? 'On' : 'Off' }}</td>
                    </tr>
                    <tr>
                        <th scope="row">Admin group</th>
                        <td class="rd-mono">{{ $adminGroup !== '' ? $adminGroup : '(none)' }}</td>
                    </tr>
                    <tr>
                        <th scope="row">Allow group</th>
                        <td class="rd-mono">{{ $allowGroup !== '' ? $allowGroup : '(any)' }}</td>
                    </tr>
                    <tr>
                        <th scope="row">Sync on login</th>
                        <td>{{ $sync ? 'On' : 'Off' }}</td>
                    </tr>
                </tbody>
            </table>
            </div>
        </section>
        </div>
@endsection

@if (session('error'))
    @push('scripts')
        <script>$(function () { RD.toast(@json(session('error')), 'error'); });</script>
    @endpush
@endif
