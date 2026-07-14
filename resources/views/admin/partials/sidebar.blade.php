@php
    $nav = $nav ?? request()->path();
    $u = auth()->user();

    $canDashboard = $u?->hasPermission('dashboard.view');
    $canDevices = $u?->hasPermission('devices.view');
    $canDeviceGroups = $u?->hasPermission('device_groups.view');
    $canDeploy = $u?->hasPermission('deploy.view');
    $canApiKeys = $u?->hasPermission('api_keys.view');
    $canUsers = $u?->hasPermission('users.view');
    $canGroups = $u?->hasPermission('groups.view');
    $canAddressBooks = $u?->hasPermission('address_books.view');
    $canRoles = $u?->hasPermission('roles.view');
    $canStrategies = $u?->hasPermission('strategies.view');
    $canSessions = $u?->hasPermission('sessions.view');
    $canAudit = $u?->hasPermission('audit.view');
    $canAlarms = $u?->hasPermission('alarms.view');
    $canRecordings = $u?->hasPermission('recordings.view');
    $canOauth = $u?->hasPermission('oauth.view');
    $canLdap = $u?->hasPermission('ldap.view');
    $canWebhooks = $u?->hasPermission('webhooks.view');
    $canSettings = $u?->hasPermission('settings.view');

    $fleetActive = str_starts_with($nav, 'admin/devices')
        || str_starts_with($nav, 'admin/device-groups')
        || str_starts_with($nav, 'admin/deploy-tokens')
        || str_starts_with($nav, 'admin/client-config');
    $peopleActive = str_starts_with($nav, 'admin/users')
        || str_starts_with($nav, 'admin/groups')
        || str_starts_with($nav, 'admin/address-books')
        || str_starts_with($nav, 'admin/roles');
    $controlActive = str_starts_with($nav, 'admin/strategies')
        || str_starts_with($nav, 'admin/sessions');
    $auditActive = str_starts_with($nav, 'admin/audit')
        || str_starts_with($nav, 'admin/console-audit')
        || str_starts_with($nav, 'admin/alarms')
        || str_starts_with($nav, 'admin/recordings');
    $integrationActive = str_starts_with($nav, 'admin/api-keys')
        || str_starts_with($nav, 'admin/oauth-providers')
        || str_starts_with($nav, 'admin/ldap')
        || str_starts_with($nav, 'admin/webhooks');
@endphp

<aside class="rd-sidebar" id="rdSidebar" aria-label="Primary navigation">
    <button class="rd-sidebar__close" type="button" aria-label="Close navigation"
            aria-controls="rdSidebar">
        <i class="ri-close-line" aria-hidden="true"></i>
    </button>
    <a class="rd-sidebar__brand" href="/admin">
        <span class="rd-logo" aria-hidden="true"><i class="ri-remote-control-line"></i></span>
        <span class="rd-sidebar__brand-copy">
            <span class="rd-sidebar__brand-name">RD-API-Server</span>
            <span class="rd-sidebar__brand-meta">Administration</span>
        </span>
    </a>

    <nav class="rd-nav" aria-label="Administration">
        @if ($canDashboard)
            <section class="rd-nav__group" aria-labelledby="nav-overview-label">
                <div class="rd-nav__label" id="nav-overview-label">Overview</div>
                <div class="rd-nav__group-items">
                    <a href="/admin" class="rd-nav__item {{ $nav === 'admin' ? 'active' : '' }}"
                       @if ($nav === 'admin') aria-current="page" @endif>
                        <i class="ri-dashboard-line" aria-hidden="true"></i>
                        <span>Dashboard</span>
                    </a>
                </div>
            </section>
        @endif

        @if ($canDevices || $canDeviceGroups || $canDeploy)
            <section class="rd-nav__group">
                <button class="rd-nav__group-toggle" type="button" aria-expanded="true"
                        aria-controls="nav-fleet-items">
                    <span>Fleet</span><i class="ri-arrow-down-s-line" aria-hidden="true"></i>
                </button>
                <div class="rd-nav__group-items" id="nav-fleet-items">
                    @if ($canDevices)
                        <a href="/admin/devices"
                           class="rd-nav__item {{ str_starts_with($nav, 'admin/devices') && ! str_contains($nav, 'pending') ? 'active' : '' }}"
                           @if (str_starts_with($nav, 'admin/devices') && ! str_contains($nav, 'pending')) aria-current="page" @endif>
                            <i class="ri-computer-line" aria-hidden="true"></i><span>Devices</span>
                        </a>
                    @endif
                    @if ($canDeploy)
                        <a href="/admin/devices/pending"
                           class="rd-nav__item {{ str_contains($nav, 'devices/pending') ? 'active' : '' }}"
                           @if (str_contains($nav, 'devices/pending')) aria-current="page" @endif>
                            <i class="ri-shield-check-line" aria-hidden="true"></i><span>Pending devices</span>
                        </a>
                    @endif
                    @if ($canDeviceGroups)
                        <a href="/admin/device-groups"
                           class="rd-nav__item {{ str_starts_with($nav, 'admin/device-groups') ? 'active' : '' }}"
                           @if (str_starts_with($nav, 'admin/device-groups')) aria-current="page" @endif>
                            <i class="ri-stack-line" aria-hidden="true"></i><span>Device groups</span>
                        </a>
                    @endif
                    @if ($canDeploy)
                        <a href="/admin/deploy-tokens"
                           class="rd-nav__item {{ str_starts_with($nav, 'admin/deploy-tokens') ? 'active' : '' }}"
                           @if (str_starts_with($nav, 'admin/deploy-tokens')) aria-current="page" @endif>
                            <i class="ri-key-2-line" aria-hidden="true"></i><span>Deploy tokens</span>
                        </a>
                        <a href="/admin/client-config"
                           class="rd-nav__item {{ str_starts_with($nav, 'admin/client-config') ? 'active' : '' }}"
                           @if (str_starts_with($nav, 'admin/client-config')) aria-current="page" @endif>
                            <i class="ri-qr-code-line" aria-hidden="true"></i><span>Client config</span>
                        </a>
                    @endif
                </div>
            </section>
        @endif

        @if ($canUsers || $canGroups || $canAddressBooks || $canRoles)
            <section class="rd-nav__group">
                <button class="rd-nav__group-toggle" type="button" aria-expanded="true"
                        aria-controls="nav-people-items">
                    <span>People &amp; access</span><i class="ri-arrow-down-s-line" aria-hidden="true"></i>
                </button>
                <div class="rd-nav__group-items" id="nav-people-items">
                    @if ($canUsers)
                        <a href="/admin/users"
                           class="rd-nav__item {{ str_starts_with($nav, 'admin/users') ? 'active' : '' }}"
                           @if (str_starts_with($nav, 'admin/users')) aria-current="page" @endif>
                            <i class="ri-user-line" aria-hidden="true"></i><span>Users</span>
                        </a>
                    @endif
                    @if ($canGroups)
                        <a href="/admin/groups"
                           class="rd-nav__item {{ str_starts_with($nav, 'admin/groups') ? 'active' : '' }}"
                           @if (str_starts_with($nav, 'admin/groups')) aria-current="page" @endif>
                            <i class="ri-group-line" aria-hidden="true"></i><span>User groups</span>
                        </a>
                    @endif
                    @if ($canAddressBooks)
                        <a href="/admin/address-books"
                           class="rd-nav__item {{ str_starts_with($nav, 'admin/address-books') ? 'active' : '' }}"
                           @if (str_starts_with($nav, 'admin/address-books')) aria-current="page" @endif>
                            <i class="ri-book-2-line" aria-hidden="true"></i><span>Address books</span>
                        </a>
                    @endif
                    @if ($canRoles)
                        <a href="/admin/roles"
                           class="rd-nav__item {{ str_starts_with($nav, 'admin/roles') ? 'active' : '' }}"
                           @if (str_starts_with($nav, 'admin/roles')) aria-current="page" @endif>
                            <i class="ri-shield-user-line" aria-hidden="true"></i><span>Admin roles</span>
                        </a>
                    @endif
                </div>
            </section>
        @endif

        @if ($canStrategies || $canSessions)
            <section class="rd-nav__group">
                <button class="rd-nav__group-toggle" type="button" aria-expanded="true"
                        aria-controls="nav-control-items">
                    <span>Control</span><i class="ri-arrow-down-s-line" aria-hidden="true"></i>
                </button>
                <div class="rd-nav__group-items" id="nav-control-items">
                    @if ($canStrategies)
                        <a href="/admin/strategies"
                           class="rd-nav__item {{ str_starts_with($nav, 'admin/strategies') ? 'active' : '' }}"
                           @if (str_starts_with($nav, 'admin/strategies')) aria-current="page" @endif>
                            <i class="ri-settings-5-line" aria-hidden="true"></i><span>Strategies</span>
                        </a>
                    @endif
                    @if ($canSessions)
                        <a href="/admin/sessions"
                           class="rd-nav__item {{ str_starts_with($nav, 'admin/sessions') ? 'active' : '' }}"
                           @if (str_starts_with($nav, 'admin/sessions')) aria-current="page" @endif>
                            <i class="ri-base-station-line" aria-hidden="true"></i><span>Live sessions</span>
                        </a>
                    @endif
                </div>
            </section>
        @endif

        @if ($canAudit || $canAlarms || $canRecordings)
            <section class="rd-nav__group">
                <button class="rd-nav__group-toggle" type="button" aria-expanded="true"
                        aria-controls="nav-audit-items">
                    <span>Audit &amp; safety</span><i class="ri-arrow-down-s-line" aria-hidden="true"></i>
                </button>
                <div class="rd-nav__group-items" id="nav-audit-items">
                    @if ($canAudit)
                        <a href="/admin/audit/connections"
                           class="rd-nav__item {{ str_starts_with($nav, 'admin/audit/connections') ? 'active' : '' }}"
                           @if (str_starts_with($nav, 'admin/audit/connections')) aria-current="page" @endif>
                            <i class="ri-history-line" aria-hidden="true"></i><span>Connections</span>
                        </a>
                        <a href="/admin/audit/files"
                           class="rd-nav__item {{ str_starts_with($nav, 'admin/audit/files') ? 'active' : '' }}"
                           @if (str_starts_with($nav, 'admin/audit/files')) aria-current="page" @endif>
                            <i class="ri-file-transfer-line" aria-hidden="true"></i><span>File transfers</span>
                        </a>
                        <a href="/admin/audit/logins"
                           class="rd-nav__item {{ str_starts_with($nav, 'admin/audit/logins') ? 'active' : '' }}"
                           @if (str_starts_with($nav, 'admin/audit/logins')) aria-current="page" @endif>
                            <i class="ri-login-circle-line" aria-hidden="true"></i><span>Login activity</span>
                        </a>
                        <a href="/admin/console-audit"
                           class="rd-nav__item {{ str_starts_with($nav, 'admin/console-audit') ? 'active' : '' }}"
                           @if (str_starts_with($nav, 'admin/console-audit')) aria-current="page" @endif>
                            <i class="ri-terminal-box-line" aria-hidden="true"></i><span>Console operations</span>
                        </a>
                    @endif
                    @if ($canAlarms)
                        <a href="/admin/alarms"
                           class="rd-nav__item {{ str_starts_with($nav, 'admin/alarms') ? 'active' : '' }}"
                           @if (str_starts_with($nav, 'admin/alarms')) aria-current="page" @endif>
                            <i class="ri-alarm-warning-line" aria-hidden="true"></i><span>Alarms</span>
                        </a>
                    @endif
                    @if ($canRecordings)
                        <a href="/admin/recordings"
                           class="rd-nav__item {{ str_starts_with($nav, 'admin/recordings') ? 'active' : '' }}"
                           @if (str_starts_with($nav, 'admin/recordings')) aria-current="page" @endif>
                            <i class="ri-film-line" aria-hidden="true"></i><span>Recordings</span>
                        </a>
                    @endif
                </div>
            </section>
        @endif

        @if ($canApiKeys || $canDeploy || $canOauth || $canLdap || $canWebhooks)
            <section class="rd-nav__group">
                <button class="rd-nav__group-toggle" type="button" aria-expanded="true"
                        aria-controls="nav-integrations-items">
                    <span>Integrations</span><i class="ri-arrow-down-s-line" aria-hidden="true"></i>
                </button>
                <div class="rd-nav__group-items" id="nav-integrations-items">
                    @if ($canApiKeys)
                        <a href="/admin/api-keys"
                           class="rd-nav__item {{ str_starts_with($nav, 'admin/api-keys') ? 'active' : '' }}"
                           @if (str_starts_with($nav, 'admin/api-keys')) aria-current="page" @endif>
                            <i class="ri-terminal-box-line" aria-hidden="true"></i><span>API keys</span>
                        </a>
                    @endif
                    @if ($canOauth)
                        <a href="/admin/oauth-providers"
                           class="rd-nav__item {{ str_starts_with($nav, 'admin/oauth-providers') ? 'active' : '' }}"
                           @if (str_starts_with($nav, 'admin/oauth-providers')) aria-current="page" @endif>
                            <i class="ri-shield-keyhole-line" aria-hidden="true"></i><span>OAuth providers</span>
                        </a>
                    @endif
                    @if ($canLdap)
                        <a href="/admin/ldap"
                           class="rd-nav__item {{ str_starts_with($nav, 'admin/ldap') ? 'active' : '' }}"
                           @if (str_starts_with($nav, 'admin/ldap')) aria-current="page" @endif>
                            <i class="ri-building-2-line" aria-hidden="true"></i><span>LDAP / AD</span>
                        </a>
                    @endif
                    @if ($canWebhooks)
                        <a href="/admin/webhooks"
                           class="rd-nav__item {{ str_starts_with($nav, 'admin/webhooks') ? 'active' : '' }}"
                           @if (str_starts_with($nav, 'admin/webhooks')) aria-current="page" @endif>
                            <i class="ri-send-plane-line" aria-hidden="true"></i><span>Webhooks</span>
                        </a>
                    @endif
                </div>
            </section>
        @endif

        @if ($canSettings)
            <section class="rd-nav__group" aria-labelledby="nav-system-label">
                <div class="rd-nav__label" id="nav-system-label">System</div>
                <div class="rd-nav__group-items">
                    <a href="/admin/settings"
                       class="rd-nav__item {{ str_starts_with($nav, 'admin/settings') ? 'active' : '' }}"
                       @if (str_starts_with($nav, 'admin/settings')) aria-current="page" @endif>
                        <i class="ri-tools-line" aria-hidden="true"></i><span>Settings</span>
                    </a>
                </div>
            </section>
        @endif
    </nav>

    <div class="rd-sidebar__footer">Self-hosted &middot; Independent</div>
</aside>
