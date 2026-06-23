@php($nav = $nav ?? request()->path())
@php($u = auth()->user())
<aside class="rd-sidebar">
    <div class="rd-sidebar__brand">
        <span class="rd-logo"><i class="ri-remote-control-line"></i></span>
        <span>rustdesk-api</span>
    </div>

    <nav class="rd-nav">
        @if ($u && $u->hasPermission('dashboard.view'))
            <div class="rd-nav__label">Overview</div>
            <a href="/admin" class="rd-nav__item {{ $nav === 'admin' ? 'active' : '' }}">
                <i class="ri-dashboard-line"></i> Dashboard
            </a>
        @endif

        <div class="rd-nav__label">Management</div>
        @if ($u && $u->hasPermission('devices.view'))
            <a href="/admin/devices" class="rd-nav__item {{ str_contains($nav, 'devices') ? 'active' : '' }}">
                <i class="ri-computer-line"></i> Devices
            </a>
        @endif
        @if ($u && $u->hasPermission('users.view'))
            <a href="/admin/users" class="rd-nav__item {{ str_contains($nav, 'users') ? 'active' : '' }}">
                <i class="ri-user-line"></i> Users
            </a>
        @endif
        @if ($u && $u->hasPermission('address_books.view'))
            <a href="/admin/address-books" class="rd-nav__item {{ str_contains($nav, 'address') ? 'active' : '' }}">
                <i class="ri-book-2-line"></i> Address Books
            </a>
        @endif
        @if ($u && $u->hasPermission('groups.view'))
            <a href="/admin/groups" class="rd-nav__item {{ str_contains($nav, 'groups') ? 'active' : '' }}">
                <i class="ri-group-line"></i> Groups
            </a>
        @endif
        @if ($u && $u->hasPermission('deploy.view'))
            <a href="/admin/devices/pending" class="rd-nav__item {{ str_contains($nav, 'pending') ? 'active' : '' }}">
                <i class="ri-shield-check-line"></i> Pending Devices
            </a>
            <a href="/admin/deploy-tokens" class="rd-nav__item {{ str_contains($nav, 'deploy') ? 'active' : '' }}">
                <i class="ri-key-2-line"></i> Deploy Tokens
            </a>
            <a href="/admin/client-config" class="rd-nav__item {{ str_contains($nav, 'client-config') ? 'active' : '' }}">
                <i class="ri-qr-code-line"></i> Client Config
            </a>
            <a href="/admin/api-keys" class="rd-nav__item {{ str_contains($nav, 'api-keys') ? 'active' : '' }}">
                <i class="ri-terminal-box-line"></i> API Keys
            </a>
        @endif

        @if ($u && ($u->hasPermission('strategies.view') || $u->hasPermission('sessions.view')))
            <div class="rd-nav__label">Control</div>
        @endif
        @if ($u && $u->hasPermission('strategies.view'))
            <a href="/admin/strategies" class="rd-nav__item {{ str_contains($nav, 'strateg') ? 'active' : '' }}">
                <i class="ri-settings-5-line"></i> Strategies
            </a>
        @endif
        @if ($u && $u->hasPermission('sessions.view'))
            <a href="/admin/sessions" class="rd-nav__item {{ str_contains($nav, 'sessions') ? 'active' : '' }}">
                <i class="ri-base-station-line"></i> Live Sessions
            </a>
        @endif

        <div class="rd-nav__label">Audit</div>
        @if ($u && $u->hasPermission('audit.view'))
            <a href="/admin/audit/connections" class="rd-nav__item {{ str_contains($nav, 'connections') ? 'active' : '' }}">
                <i class="ri-history-line"></i> Connection Logs
            </a>
            <a href="/admin/audit/files" class="rd-nav__item {{ str_contains($nav, 'files') ? 'active' : '' }}">
                <i class="ri-file-transfer-line"></i> File Transfers
            </a>
            <a href="/admin/audit/logins" class="rd-nav__item {{ str_contains($nav, 'logins') ? 'active' : '' }}">
                <i class="ri-login-circle-line"></i> Login Logs
            </a>
            <a href="/admin/console-audit" class="rd-nav__item {{ str_contains($nav, 'console-audit') ? 'active' : '' }}">
                <i class="ri-terminal-box-line"></i> Console Operations
            </a>
        @endif
        @if ($u && $u->hasPermission('alarms.view'))
            <a href="/admin/alarms" class="rd-nav__item {{ str_contains($nav, 'alarms') ? 'active' : '' }}">
                <i class="ri-alarm-warning-line"></i> Alarms
            </a>
        @endif
        @if ($u && $u->hasPermission('recordings.view'))
            <a href="/admin/recordings" class="rd-nav__item {{ str_contains($nav, 'recordings') ? 'active' : '' }}">
                <i class="ri-film-line"></i> Recordings
            </a>
        @endif

        <div class="rd-nav__label">System</div>
        @if ($u && $u->hasPermission('oauth.view'))
            <a href="/admin/oauth-providers" class="rd-nav__item {{ str_contains($nav, 'oauth') ? 'active' : '' }}">
                <i class="ri-shield-keyhole-line"></i> OAuth Providers
            </a>
        @endif
        @if ($u && $u->hasPermission('ldap.view'))
            <a href="/admin/ldap" class="rd-nav__item {{ str_contains($nav, 'ldap') ? 'active' : '' }}">
                <i class="ri-government-line"></i> LDAP / AD
            </a>
        @endif
        @if ($u && $u->hasPermission('roles.view'))
            <a href="/admin/roles" class="rd-nav__item {{ str_contains($nav, 'roles') ? 'active' : '' }}">
                <i class="ri-shield-user-line"></i> Admin Roles
            </a>
        @endif
        @if ($u && $u->hasPermission('settings.view'))
            <a href="/admin/settings" class="rd-nav__item {{ str_contains($nav, 'settings') ? 'active' : '' }}">
                <i class="ri-tools-line"></i> Settings
            </a>
        @endif
    </nav>
</aside>
