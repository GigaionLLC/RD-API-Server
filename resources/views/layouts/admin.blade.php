<!DOCTYPE html>
<html lang="en" data-theme="dark" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="dark light">
    <title>@yield('title', 'Dashboard') &middot; RD-API-Server</title>

    <script>
        (function () {
            try {
                var saved = window.localStorage.getItem('rd_theme');
                var theme = saved === 'light' || saved === 'dark'
                    ? saved
                    : (window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark');
                document.documentElement.setAttribute('data-theme', theme);
                document.documentElement.setAttribute('data-bs-theme', theme);
            } catch (error) {
                document.documentElement.setAttribute('data-theme', 'dark');
                document.documentElement.setAttribute('data-bs-theme', 'dark');
            }
        }());
    </script>

    <link href="{{ asset('assets/vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/vendor/remixicon/remixicon.css') }}" rel="stylesheet">
    <link href="{{ asset('assets/css/theme-dark.css') }}" rel="stylesheet">
    @stack('styles')
</head>
<body>
<a class="rd-skip-link" href="#main-content">Skip to main content</a>

<div class="rd-app">
    @include('admin.partials.sidebar')
    <button class="rd-sidebar__backdrop" type="button" aria-label="Close navigation" tabindex="-1"></button>

    <div class="rd-main">
        @include('admin.partials.navbar')

        <main class="rd-content" id="main-content" tabindex="-1">
            @yield('content')
        </main>
    </div>
</div>

<script src="{{ asset('assets/vendor/jquery/jquery.min.js') }}"></script>
<script src="{{ asset('assets/vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('assets/js/app.js') }}"></script>
@stack('scripts')
</body>
</html>
