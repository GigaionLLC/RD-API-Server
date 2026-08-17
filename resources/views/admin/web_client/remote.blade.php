@extends('layouts.admin')

@section('title', 'Remote control')

@push('styles')
    <style>
        /* The viewer owns its own chrome and fills the content area. Sized here because an
           iframe with no height falls back to 300x150, which renders the viewer as a
           thumbnail with its toolbar wrapped into a column. */
        #rd-viewer { height: calc(100vh - 260px); min-height: 420px; border: 1px solid var(--rd-border, #2a2f38); border-radius: 8px; overflow: hidden; background: #000; }
        #rd-viewer iframe { width: 100%; height: 100%; border: 0; display: block; }
        #rd-peer { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; letter-spacing: .04em; }
        .rd-remote__meta { font-size: 13px; color: var(--rd-muted, #98a1b0); }
        .rd-remote__idle { display: grid; place-content: center; text-align: center; height: 260px; color: var(--rd-muted, #98a1b0); border: 1px dashed var(--rd-border, #2a2f38); border-radius: 8px; }
    </style>
@endpush

@section('content')
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-1">Remote control</h1>
            <div class="text-muted small">
                Connect to a machine by its RustDesk ID. Server details are filled in from this
                deployment's own configuration.
            </div>
        </div>
        <a href="{{ $diagnostics }}" class="btn btn-sm btn-outline-secondary">Diagnostics</a>
    </div>

    @if (! $assetsPresent)
        <div class="alert alert-warning">
            <strong>Viewer assets are not installed.</strong>
            The Docker image publishes these during the build. A source deployment runs:
            <pre class="mb-0 mt-2"><code>node web-client/scripts/install-assets.mjs</code></pre>
        </div>
    @else
        @if ($needsWsUrls)
            <div class="alert alert-warning">
                <strong>WebSocket endpoints are not configured.</strong>
                This console is served over HTTPS, and a secure page cannot open a plain
                <code>ws://</code> socket — but <code>hbbs</code> and <code>hbbr</code> speak only
                plain <code>ws</code>. The <a href="{{ $diagnostics }}">diagnostics page</a> says
                what to set.
            </div>
        @endif

        <div class="card mb-3">
            <div class="card-body">
                {{-- GET, so a session is a URL an operator can return to — and so the id is
                     never in a POST body that a refresh would resubmit. The password is never
                     in this form: it belongs to the peer, this server does not hold it, and a
                     secret in a URL lands in history and every access log on the way. --}}
                <form method="GET" action="{{ route('admin.remote') }}" class="row g-2 align-items-end">
                    <div class="col-12 col-sm-auto">
                        <label for="rd-peer" class="form-label mb-1">RustDesk ID</label>
                        <input type="text" class="form-control" id="rd-peer" name="peer"
                               value="{{ $peer }}" placeholder="345 890 346"
                               autocomplete="off" inputmode="numeric" spellcheck="false"
                               @if ($peer === '') autofocus @endif>
                    </div>
                    <div class="col-12 col-sm-auto">
                        <button type="submit" class="btn btn-primary">Load</button>
                    </div>
                    <div class="col-12 col-sm">
                        <label for="rd-known" class="form-label mb-1">…or search a known device</label>
                        {{-- Searched, never listed. A fleet is thousands of devices; rendering
                             them into a <select> builds every one into the DOM on page load and
                             leaves the operator scrolling for a hostname they already know.
                             See the combobox rule in CLAUDE.md. --}}
                        <div class="rd-combo" data-url="{{ route('admin.remote.search') }}">
                            <input type="hidden" id="rd-known-id">
                            <input type="text" class="rd-input rd-combo__input" id="rd-known"
                                   placeholder="Search hostname, alias or ID…" autocomplete="off">
                            <div class="rd-combo__menu"></div>
                        </div>
                    </div>
                </form>

                @if ($peer !== '')
                    <div class="rd-remote__meta mt-3">
                        @if ($device)
                            <strong>{{ $device->alias ?: $device->hostname ?: $device->rustdesk_id }}</strong>
                            <span class="mx-1">·</span><code>{{ $device->rustdesk_id }}</code>
                            @if ($device->os)<span class="mx-1">·</span>{{ $device->os }}@endif
                            <span class="mx-1">·</span>{{ $device->is_online ? 'online' : 'offline' }}
                        @else
                            <code>{{ $peer }}</code>
                            <span class="mx-1">·</span>not in this deployment's device list
                            @unless ($unrestricted)
                                <span class="mx-1">·</span><span class="text-warning">outside your scope</span>
                            @endunless
                        @endif
                    </div>
                @endif
            </div>
        </div>

        @if ($peer === '')
            <div class="rd-remote__idle">
                <div>
                    <div class="mb-1">Enter a RustDesk ID above to prepare a session.</div>
                    <div class="small">Nothing connects until you press Connect in the viewer.</div>
                </div>
            </div>
        @else
            {{-- The viewer loads with the ID and this deployment's servers already filled in,
                 and waits. It never connects on its own: a session is visible on the other
                 machine and interrupts whoever is using it, so starting one is a decision
                 rather than a side effect of opening a page. --}}
            <div id="rd-viewer">
                <iframe
                    src="{{ route('admin.remote.frame', ['peer' => $peer]) }}"
                    allow="fullscreen; autoplay; clipboard-read; clipboard-write"
                    allowfullscreen
                    title="Remote desktop"></iframe>
            </div>
        @endif
    @endif
@endsection

@push('scripts')
<script>
(function () {
    // Picking a known device fills the ID field rather than navigating, so the operator
    // still presses Load — and then Connect — before anything reaches the other machine.
    var chosen = document.getElementById('rd-known-id');
    var field = document.getElementById('rd-peer');
    if (!chosen || !field) return;
    // The combobox writes the peer id into its hidden input. Copy it across rather than
    // navigating: the operator still presses Load, and then Connect, before anything
    // reaches the other machine.
    $(chosen).on('change', function () {
        if (chosen.value) { field.value = chosen.value; field.focus(); }
    });
})();
</script>
@endpush
