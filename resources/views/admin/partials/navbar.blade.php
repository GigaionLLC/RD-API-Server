<header class="rd-navbar">
    <button class="rd-sidebar__toggle" aria-label="Toggle menu"><i class="ri-menu-line"></i></button>

    <div>
        <div class="rd-page-title rd-mb-0" style="font-size:16px;">@yield('title', 'Dashboard')</div>
    </div>

    <div class="rd-navbar__spacer"></div>

    <button class="rd-btn rd-btn--ghost" data-theme-toggle title="Toggle light/dark">
        <i class="ri-contrast-2-line"></i>
    </button>

    <div class="dropdown">
        <button class="rd-btn rd-btn--ghost dropdown-toggle" data-bs-toggle="dropdown">
            <i class="ri-account-circle-line"></i>
            <span>{{ auth()->user()?->username ?? 'admin' }}</span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="{{ route('admin.2fa.show') }}">Two-factor auth</a></li>
            <li><a class="dropdown-item" href="/admin/settings">Settings</a></li>
            <li><hr class="dropdown-divider"></li>
            <li>
                <form method="POST" action="/admin/logout" class="m-0">
                    @csrf
                    <button type="submit" class="dropdown-item text-danger">Sign out</button>
                </form>
            </li>
        </ul>
    </div>
</header>
