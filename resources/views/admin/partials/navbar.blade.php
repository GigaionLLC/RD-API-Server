<header class="rd-navbar">
    <button class="rd-sidebar__toggle" type="button" aria-label="Open navigation"
            aria-controls="rdSidebar" aria-expanded="false">
        <i class="ri-menu-line" aria-hidden="true"></i>
    </button>

    <div class="rd-navbar__context" aria-label="Current area">
        <i class="ri-shield-keyhole-line" aria-hidden="true"></i>
        <span>Administration</span>
    </div>

    <div class="rd-navbar__spacer"></div>
    <span class="rd-status-chip">Console ready</span>

    <button class="rd-icon-btn" type="button" data-theme-toggle
            aria-label="Switch theme" title="Switch theme">
        <i class="ri-sun-line" aria-hidden="true"></i>
    </button>

    <div class="dropdown">
        <button class="rd-btn rd-btn--ghost dropdown-toggle" type="button"
                data-bs-toggle="dropdown" aria-expanded="false"
                aria-label="Account menu for {{ auth()->user()?->username ?? 'admin' }}">
            <i class="ri-account-circle-line" aria-hidden="true"></i>
            <span class="rd-user-label">{{ auth()->user()?->username ?? 'admin' }}</span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
            <li>
                <a class="dropdown-item" href="{{ route('admin.2fa.show') }}">
                    <i class="ri-shield-check-line" aria-hidden="true"></i> Two-factor auth
                </a>
            </li>
            @if (auth()->user()?->hasPermission('settings.view'))
                <li>
                    <a class="dropdown-item" href="/admin/settings">
                        <i class="ri-settings-3-line" aria-hidden="true"></i> Settings
                    </a>
                </li>
            @endif
            <li><hr class="dropdown-divider"></li>
            <li>
                <form method="POST" action="/admin/logout" class="m-0">
                    @csrf
                    <button type="submit" class="dropdown-item rd-danger">
                        <i class="ri-logout-box-r-line" aria-hidden="true"></i> Sign out
                    </button>
                </form>
            </li>
        </ul>
    </div>
</header>
