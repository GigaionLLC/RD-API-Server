@extends('layouts.admin')

@section('title', 'Remote — '.($device->alias ?: $device->rustdesk_id))

@push('head')
    <style>
        /* The viewer owns its own chrome and fills the content area. Its stylesheet is
           scoped to #rd-viewer so it cannot leak into the rest of the console. */
        #rd-viewer { height: calc(100vh - 190px); min-height: 420px; border: 1px solid var(--rd-border, #2a2f38); border-radius: 8px; overflow: hidden; }
        #rd-viewer iframe { width: 100%; height: 100%; border: 0; display: block; }
    </style>
@endpush

@section('content')
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-1">Remote desktop</h1>
            <div class="text-muted small">
                {{ $device->alias ?: $device->hostname ?: '—' }}
                <span class="mx-1">·</span>
                <code>{{ $device->rustdesk_id }}</code>
                @if ($device->os)
                    <span class="mx-1">·</span>{{ $device->os }}
                @endif
            </div>
        </div>
        <a href="{{ route('admin.devices.index') }}" class="btn btn-sm btn-outline-secondary">Back to devices</a>
    </div>

    @if (! $assetsPresent)
        <div class="alert alert-warning">
            <strong>Viewer assets are not installed.</strong>
            Copy them from <code>web-client/</code> to <code>public/assets/webclient/</code>:
            <pre class="mb-0 mt-2"><code>node web-client/scripts/install-assets.mjs</code></pre>
        </div>
    @elseif (! $config['host'])
        <div class="alert alert-warning">
            <strong>No ID server configured.</strong>
            Set <code>RUSTDESK_ID_SERVER</code> so the viewer knows where to connect.
        </div>
    @elseif ($needsWsUrls)
        {{-- The one setup step with no other symptom: everything below would render and
             then fail to connect, which reads as an unreachable server rather than a
             configuration gap. --}}
        <div class="alert alert-warning">
            <strong>WebSocket endpoints are not configured.</strong>
            This console is served over HTTPS, and a secure page cannot open a plain
            <code>ws://</code> socket — but <code>hbbs</code> and <code>hbbr</code> speak
            only plain <code>ws</code>. Terminate TLS in front of ports 21118 and 21119, then
            set both:
            <pre class="mb-0 mt-2"><code>RUSTDESK_WS_ID_URL=wss://your-host/ws/id
RUSTDESK_WS_RELAY_URL=wss://your-host/ws/relay</code></pre>
            <div class="mt-2">
                Setting only one has no effect. See
                <code>docs/web-client-deployment.md</code> for nginx and Caddy configuration,
                including why the proxy must <em>not</em> forward <code>X-Real-IP</code> or
                <code>X-Forwarded-For</code> to port 21118.
            </div>
        </div>
    @else
        @if (! $config['secure'])
            <div class="alert alert-info py-2">
                This page is not served over HTTPS. The viewer needs a secure context for
                video decoding — <code>localhost</code> counts, any other host does not.
            </div>
        @endif

        <div id="rd-viewer">
            {{-- An iframe keeps the viewer's fullscreen, keyboard-lock and pointer capture
                 from fighting the console's own layout and key handlers. --}}
            <iframe
                src="{{ route('admin.devices.connect.frame', $device) }}"
                allow="fullscreen; autoplay; clipboard-read; clipboard-write"
                allowfullscreen
                title="Remote desktop"></iframe>
        </div>
    @endif
@endsection
