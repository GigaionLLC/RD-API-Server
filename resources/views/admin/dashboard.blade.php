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
    <div class="rd-breadcrumb">Overview / Dashboard</div>

    <div class="rd-grid rd-grid--4" style="margin-bottom:20px;">
        @foreach ($stats as $s)
            <div class="rd-card"><div class="rd-card__body">
                <div class="rd-stat">
                    <div class="rd-stat__icon rd-stat__icon--{{ $s['tone'] }}"><i class="{{ $s['icon'] }}"></i></div>
                    <div>
                        <div class="rd-stat__value">
                            {{ $s['value'] }}
                            @if (!empty($s['trend']))
                                @php($t = $s['trend'])
                                <span style="font-size:12px;font-weight:600;margin-left:6px;color:{{ $t['dir'] === 'down' ? 'var(--rd-danger)' : 'var(--rd-success)' }};" title="vs previous 24h">
                                    <i class="{{ $t['dir'] === 'down' ? 'ri-arrow-down-line' : 'ri-arrow-up-line' }}"></i>{{ $t['pct'] }}%
                                </span>
                            @endif
                        </div>
                        <div class="rd-stat__label">{{ $s['label'] }}</div>
                    </div>
                </div>
            </div></div>
        @endforeach
    </div>

    <div class="rd-grid rd-grid--2">
        <div class="rd-card">
            <div class="rd-card__header">
                <h3 class="rd-card__title">Activity (last 14 days)</h3>
            </div>
            <div class="rd-card__body"><div id="connChart"></div></div>
        </div>

        <div class="rd-card">
            <div class="rd-card__header">
                <h3 class="rd-card__title">Recent Devices</h3>
                <a href="/admin/devices" class="rd-btn rd-btn--ghost">View all</a>
            </div>
            <div class="rd-card__body" style="padding:0;">
                <table class="rd-table">
                    <thead><tr><th>Device</th><th>OS</th><th>Status</th><th>Last seen</th></tr></thead>
                    <tbody>
                    @forelse ($recentDevices as $d)
                        <tr>
                            <td>{{ $d['hostname'] ?? $d['id'] }}</td>
                            <td>{{ $d['os'] ?? '—' }}</td>
                            <td>
                                <span class="rd-badge rd-badge--{{ ($d['online'] ?? false) ? 'online' : 'offline' }}">
                                    <span class="dot"></span>{{ ($d['online'] ?? false) ? 'Online' : 'Offline' }}
                                </span>
                            </td>
                            <td class="rd-muted">{{ $d['last_seen'] ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="rd-muted" style="text-align:center;padding:28px;">
                            No devices yet — they appear here after the first heartbeat.
                        </td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    $(function () {
        var series = @json($chartSeries);
        var devices = @json($deviceSeries);
        var cats   = @json($chartCategories);
        RD.areaChart('#connChart', [
            { name: 'Connections', data: series },
            { name: 'New devices', data: devices }
        ], cats, ['#6571ff', '#22c55e']);
    });
</script>
@endpush
