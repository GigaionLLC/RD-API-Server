@extends('layouts.admin')
@section('title', 'Dashboard')

@php
    // Placeholder values until the dashboard controller/service is wired.
    $stats = $stats ?? [
        ['label' => 'Total Devices',   'value' => '—', 'icon' => 'ri-computer-line',     'tone' => 'primary', 'trend' => null],
        ['label' => 'Online Now',      'value' => '—', 'icon' => 'ri-base-station-line',  'tone' => 'success', 'trend' => null],
        ['label' => 'Users',           'value' => '—', 'icon' => 'ri-user-line',          'tone' => 'warning', 'trend' => null],
        ['label' => 'Sessions (24h)',  'value' => '—', 'icon' => 'ri-exchange-line',      'tone' => 'danger',  'trend' => null],
    ];
    $recentDevices = $recentDevices ?? [];
    // Inline array literals can't go directly inside @json(); compute defaults here.
    $chartSeries = $chartSeries ?? [3, 5, 4, 7, 6, 9, 8, 11, 7, 10, 12, 9, 13, 11];
    $deviceSeries = $deviceSeries ?? [];
    $chartCategories = $chartCategories ?? [];
@endphp

@section('content')
    <div class="rd-stack rd-stack--lg">
        <header class="rd-page-header">
            <div class="rd-page-header__copy">
                <div class="rd-breadcrumb" aria-label="Breadcrumb">Overview / Dashboard</div>
                <p class="rd-page-header__eyebrow">Remote operations</p>
                <h1 class="rd-page-header__title">Fleet overview</h1>
                <p class="rd-page-header__description">
                    Monitor availability, recent enrollment, and connection activity from one operational view.
                </p>
            </div>
            @if (auth()->user()?->hasPermission('devices.view'))
                <div class="rd-page-header__actions">
                    <a href="{{ route('admin.devices.index') }}" class="rd-btn rd-btn--primary">
                        <i class="ri-computer-line" aria-hidden="true"></i> Manage devices
                    </a>
                </div>
            @endif
        </header>

        <section aria-labelledby="fleet-summary-title">
            <h2 class="visually-hidden" id="fleet-summary-title">Fleet summary</h2>
            <div class="rd-summary">
                @foreach ($stats as $s)
                    <article class="rd-summary__item rd-summary__item--{{ $s['tone'] }}">
                        <span class="rd-summary__icon" aria-hidden="true"><i class="{{ $s['icon'] }}"></i></span>
                        <div>
                            <p class="rd-summary__label">{{ $s['label'] }}</p>
                            <div class="rd-summary__value-row">
                                <p class="rd-summary__value">{{ $s['value'] }}</p>
                                @if (!empty($s['trend']))
                                    @php
                                        $t = $s['trend'];
                                    @endphp
                                    <span class="rd-summary__trend rd-summary__trend--{{ $t['dir'] }}" title="Compared with the previous 24 hours">
                                        <i class="{{ $t['dir'] === 'down' ? 'ri-arrow-down-line' : 'ri-arrow-up-line' }}" aria-hidden="true"></i>
                                        {{ $t['pct'] }}%
                                        <span class="visually-hidden">{{ $t['dir'] === 'down' ? 'decrease' : 'increase' }}</span>
                                    </span>
                                @endif
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

        <div class="rd-dashboard-grid">
            <section class="rd-card rd-dashboard-grid__activity" aria-labelledby="activity-title">
                <div class="rd-card__header">
                    <div>
                        <p class="rd-card__eyebrow">Telemetry</p>
                        <h2 class="rd-card__title" id="activity-title">Activity over 14 days</h2>
                    </div>
                    @if (auth()->user()?->hasPermission('audit.view'))
                        <a href="{{ route('admin.audit.connections') }}" class="rd-btn rd-btn--ghost">Review logs</a>
                    @endif
                </div>
                <div class="rd-card__body">
                    <div id="connChart" class="rd-chart" role="img" aria-label="Connections and new devices over the last 14 days"></div>
                </div>
            </section>

            <section class="rd-card rd-card--flush" aria-labelledby="recent-devices-title">
                <div class="rd-card__header">
                    <div>
                        <p class="rd-card__eyebrow">Latest heartbeat</p>
                        <h2 class="rd-card__title" id="recent-devices-title">Recent devices</h2>
                    </div>
                    @if (auth()->user()?->hasPermission('devices.view'))
                        <a href="{{ route('admin.devices.index') }}" class="rd-btn rd-btn--ghost">View all</a>
                    @endif
                </div>
                <div class="rd-table-wrap" role="region" tabindex="0" aria-label="Recent devices table">
                    <table class="rd-table rd-table--compact">
                        <thead><tr><th>Device</th><th>OS</th><th>Status</th><th>Last seen</th></tr></thead>
                        <tbody>
                        @forelse ($recentDevices as $d)
                            <tr>
                                <td>
                                    <span class="rd-table__primary">{{ $d['hostname'] ?? $d['id'] }}</span>
                                    <span class="rd-table__meta rd-mono">{{ $d['id'] }}</span>
                                </td>
                                <td class="rd-muted">{{ $d['os'] ?? '—' }}</td>
                                <td>
                                    <span class="rd-badge rd-badge--{{ ($d['online'] ?? false) ? 'online' : 'offline' }}">
                                        <span class="dot" aria-hidden="true"></span>{{ ($d['online'] ?? false) ? 'Online' : 'Offline' }}
                                    </span>
                                </td>
                                <td class="rd-muted">{{ $d['last_seen'] ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4">
                                    <div class="rd-empty">
                                        <i class="ri-radar-line rd-empty__icon" aria-hidden="true"></i>
                                        <p class="rd-empty__title">Waiting for the first heartbeat</p>
                                        <p class="rd-empty__body">Devices will appear here after they contact the API server.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
@endsection

@push('scripts')
<script src="{{ asset('assets/vendor/apexcharts/apexcharts.min.js') }}"></script>
<script>
    $(function () {
        var series = @json($chartSeries);
        var devices = @json($deviceSeries);
        var cats   = @json($chartCategories);
        RD.areaChart('#connChart', [
            { name: 'Connections', data: series },
            { name: 'New devices', data: devices }
        ], cats, ['primary', 'info']);
    });
</script>
@endpush
