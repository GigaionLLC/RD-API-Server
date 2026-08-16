@extends('layouts.admin')

@section('title', 'Remote desktop diagnostics')

@push('styles')
    <style>
        .rd-diag__row { display: flex; gap: 12px; align-items: flex-start; padding: 12px 0; border-top: 1px solid var(--rd-border, #2a2f38); }
        .rd-diag__row:first-child { border-top: 0; }
        .rd-diag__pill { flex: 0 0 auto; min-width: 62px; text-align: center; border-radius: 999px; padding: 2px 10px; font-size: 12px; font-weight: 600; }
        .rd-diag__pill--ok { background: rgba(46, 160, 67, .18); color: #3fb950; }
        .rd-diag__pill--warn { background: rgba(210, 153, 34, .18); color: #d29922; }
        .rd-diag__pill--fail { background: rgba(248, 81, 73, .18); color: #f85149; }
        .rd-diag__label { flex: 0 0 190px; font-weight: 600; }
        .rd-diag__detail { flex: 1; color: var(--rd-muted, #98a1b0); overflow-wrap: anywhere; }
        .rd-diag__endpoint { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; }
    </style>
@endpush

@section('content')
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-1">Remote desktop diagnostics</h1>
            <div class="text-muted small">
                Whether this deployment can run the browser remote desktop, and what to change if not.
            </div>
        </div>
        <a href="{{ route('admin.devices.index') }}" class="btn btn-sm btn-outline-secondary">Back to devices</a>
    </div>

    @php
        $pill = ['ok' => 'ok', 'warn' => 'warn', 'fail' => 'fail'];
        $word = ['ok' => 'OK', 'warn' => 'Check', 'fail' => 'Blocked'];
    @endphp

    <div class="alert alert-{{ $report['status'] === 'ok' ? 'success' : ($report['status'] === 'warn' ? 'warning' : 'danger') }}">
        @if ($report['status'] === 'ok')
            <strong>Ready.</strong> Every server-side check passed. Confirm the browser can actually
            reach the endpoint using the live test below — that is the one thing this server cannot
            check on its own.
        @elseif ($report['status'] === 'warn')
            <strong>Usable, with something worth reviewing.</strong> See the rows marked <em>Check</em>.
        @else
            <strong>The remote desktop will not connect yet.</strong> The rows marked
            <em>Blocked</em> below say why.
        @endif
    </div>

    <div class="card mb-4">
        <div class="card-body">
            @foreach ($report['checks'] as $check)
                <div class="rd-diag__row">
                    <span class="rd-diag__pill rd-diag__pill--{{ $pill[$check['status']] }}">{{ $word[$check['status']] }}</span>
                    <div class="rd-diag__label">{{ $check['label'] }}</div>
                    <div class="rd-diag__detail">{{ $check['detail'] }}</div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h6 mb-2">Endpoints the viewer will use</h2>
            @if ($report['endpoints']['rendezvous'] === '')
                <p class="text-muted mb-0">None — no transport is configured.</p>
            @else
                <div class="rd-diag__endpoint mb-1">{{ $report['endpoints']['rendezvous'] }}</div>
                <div class="rd-diag__endpoint mb-3">{{ $report['endpoints']['relay'] }}</div>

                {{-- Reachability from the browser is the check that matters and the one the
                     server cannot make: the operator's browser, not this container, is what
                     has to get through the reverse proxy. --}}
                <button type="button" id="rd-diag-probe" class="btn btn-sm btn-primary">Test from this browser</button>
                <span id="rd-diag-probe-result" class="ms-2 text-muted"></span>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h2 class="h6 mb-2">Configuration</h2>
            <p class="text-muted small">
                The transport is set through the environment rather than in this console, and
                deliberately so: the WebSocket route is part of the container's own web-server
                configuration, rendered once at start-up. A value editable here could not change
                that configuration without a restart, and the two would disagree in the meantime —
                the console claiming one thing while the server did another.
            </p>
            <p class="mb-2"><strong>Either</strong> let this container carry the WebSocket
                (one hostname, one certificate, no reverse-proxy changes):</p>
            <pre class="mb-3"><code>RUSTDESK_WS_ID_UPSTREAM=hbbs:21118
RUSTDESK_WS_RELAY_UPSTREAM=hbbr:21119</code></pre>
            <p class="mb-2"><strong>Or</strong> terminate TLS in front of hbbs and hbbr yourself
                and name the endpoints (keeps this server out of the media path):</p>
            <pre class="mb-0"><code>RUSTDESK_WS_ID_URL=wss://your-host/ws/id
RUSTDESK_WS_RELAY_URL=wss://your-host/ws/relay</code></pre>
        </div>
    </div>
@endsection

@push('scripts')
<script>
(function () {
    var button = document.getElementById('rd-diag-probe');
    if (!button) return;
    var out = document.getElementById('rd-diag-probe-result');
    var url = @json($report['endpoints']['rendezvous']);

    button.addEventListener('click', function () {
        button.disabled = true;
        out.className = 'ms-2 text-muted';
        out.textContent = 'Connecting…';

        var settled = false;
        var socket;
        // A handshake that is going to work does so immediately; anything still pending
        // after this is a proxy holding the connection open without upgrading it, which
        // never resolves on its own.
        var timer = setTimeout(function () {
            finish('warn', 'No response within 8 seconds. The proxy is accepting the connection but not upgrading it — check that it forwards the Upgrade and Connection headers.');
            try { socket.close(); } catch (e) { /* already closing */ }
        }, 8000);

        function finish(kind, message) {
            if (settled) return;
            settled = true;
            clearTimeout(timer);
            button.disabled = false;
            out.className = 'ms-2 ' + (kind === 'ok' ? 'text-success' : (kind === 'warn' ? 'text-warning' : 'text-danger'));
            out.textContent = message;
        }

        try {
            socket = new WebSocket(url);
        } catch (err) {
            finish('fail', 'The browser refused the URL: ' + err.message);
            return;
        }

        socket.addEventListener('open', function () {
            finish('ok', 'Connected. The endpoint is reachable from this browser and speaks WebSocket.');
            socket.close();
        });

        // The browser deliberately withholds the reason for a failed WebSocket handshake,
        // so the message names the things that actually cause it rather than guessing.
        socket.addEventListener('error', function () {
            finish('fail', 'Could not connect. Common causes: no TLS terminator in front of the port, the proxy not forwarding Upgrade/Connection headers, or a certificate the browser rejects.');
        });
    });
})();
</script>
@endpush
