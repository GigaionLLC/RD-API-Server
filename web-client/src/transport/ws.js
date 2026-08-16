/**
 * WebSocket transport.
 *
 * One binary frame carries exactly one protobuf message — no length prefix, because
 * hbbr translates framing for us on mixed WS/TCP pairs. The `BytesCodec` length-delimited
 * header used on raw TCP therefore never appears in this client.
 *
 * Two constraints that are easy to trip over:
 *
 *  - Never request a subprotocol. The server does not echo `Sec-WebSocket-Protocol`, and
 *    a browser fails the handshake when its request goes unanswered.
 *  - A rendezvous socket is single-use. hbbs keeps the read loop alive only for
 *    PunchHoleRequest and RequestRelay; any other message closes it, and the reply sink
 *    is dropped once a response is routed. Open a fresh socket per request.
 *
 * The same code runs in the browser and under Node 18+, both of which provide a global
 * WebSocket, so the transport needs no environment branching.
 */

export class TransportError extends Error {
    /** @param {string} message */
    constructor(message) {
        super(message);
        this.name = 'TransportError';
    }
}

export const DEFAULT_TIMEOUT_MS = 20_000;

/**
 * A WebSocket with an awaitable inbound frame queue.
 *
 * Frames are buffered when nobody is waiting, so a message that arrives between awaits
 * is never lost — which matters because the peer can send TestDelay at any moment,
 * including between the frames we are stepping through during login.
 */
export class FrameSocket {
    /**
     * @param {WebSocket} ws
     * @param {number} timeoutMs
     */
    constructor(ws, timeoutMs = DEFAULT_TIMEOUT_MS) {
        this.ws = ws;
        this.timeoutMs = timeoutMs;
        /** @type {Uint8Array[]} */
        this._pending = [];
        /** @type {Array<{resolve: Function, reject: Function, timer: any}>} */
        this._waiters = [];
        /** @type {Error | null} */
        this._closed = null;

        ws.binaryType = 'arraybuffer';
        ws.addEventListener('message', (ev) => this._onMessage(ev));
        ws.addEventListener('close', (ev) => this._onClose(ev));
    }

    /**
     * @param {string} url
     * @param {number} [timeoutMs]
     * @returns {Promise<FrameSocket>}
     */
    static open(url, timeoutMs = DEFAULT_TIMEOUT_MS) {
        return new Promise((resolve, reject) => {
            let ws;
            try {
                ws = new WebSocket(url); // no subprotocol — see module note
            } catch (err) {
                reject(new TransportError(`cannot construct WebSocket for ${url}: ${err.message}`));
                return;
            }
            const timer = setTimeout(
                () => { try { ws.close(); } catch { /* already closing */ } reject(new TransportError(`timed out opening ${url}`)); },
                timeoutMs,
            );
            ws.addEventListener('open', () => { clearTimeout(timer); resolve(new FrameSocket(ws, timeoutMs)); }, { once: true });
            ws.addEventListener('error', () => { clearTimeout(timer); reject(new TransportError(`cannot connect to ${url}`)); }, { once: true });
        });
    }

    /** @param {MessageEvent} ev */
    _onMessage(ev) {
        // Text and control frames carry nothing we consume; hbbr drops empty payloads
        // rather than relaying them, so they only ever act as keepalives.
        if (typeof ev.data === 'string') return;
        const bytes = new Uint8Array(ev.data);
        const waiter = this._waiters.shift();
        if (waiter) {
            if (waiter.timer) clearTimeout(waiter.timer);
            waiter.resolve(bytes);
        } else {
            this._pending.push(bytes);
        }
    }

    /** @param {CloseEvent} ev */
    _onClose(ev) {
        this._closed = new TransportError(`socket closed (code ${ev.code}${ev.reason ? `: ${ev.reason}` : ''})`);
        while (this._waiters.length) {
            const w = this._waiters.shift();
            if (w.timer) clearTimeout(w.timer);
            w.reject(this._closed);
        }
    }

    /** @param {Uint8Array} bytes */
    send(bytes) {
        if (this._closed) throw this._closed;
        this.ws.send(bytes);
    }

    /**
     * @param {string} [label] Used in the timeout message.
     * @param {number} [timeoutMs] Override; pass 0 to wait indefinitely.
     * @returns {Promise<Uint8Array>}
     */
    next(label = 'frame', timeoutMs = this.timeoutMs) {
        const buffered = this._pending.shift();
        if (buffered) return Promise.resolve(buffered);
        if (this._closed) return Promise.reject(this._closed);
        return new Promise((resolve, reject) => {
            // A zero timeout means "wait for the socket". Steady-state reads use it: a
            // handshake that stalls is broken, but an established session legitimately
            // goes quiet — the peer only sends video when the screen changes — and a
            // per-read deadline would kill a session that a suspend, a Wi-Fi roam or a
            // busy host would otherwise have survived.
            if (!timeoutMs) {
                this._waiters.push({ resolve, reject, timer: null });
                return;
            }
            const timer = setTimeout(
                () => {
                    const i = this._waiters.findIndex((w) => w.timer === timer);
                    if (i >= 0) this._waiters.splice(i, 1);
                    reject(new TransportError(`timed out waiting for ${label}`));
                },
                timeoutMs,
            );
            this._waiters.push({ resolve, reject, timer });
        });
    }

    /** Backpressure signal. Callers pacing input or file blocks must consult this. */
    get bufferedAmount() {
        return this.ws.bufferedAmount ?? 0;
    }

    get closed() {
        return this._closed !== null;
    }

    close() {
        try {
            this.ws.close();
        } catch {
            // Already closing or closed; nothing useful to do.
        }
    }
}

export const DEFAULT_RENDEZVOUS_PORT = 21116;
export const DEFAULT_RELAY_PORT = 21117;

/**
 * Splits `host`, `host:port`, `[v6]:port` or a bare IPv6 literal.
 *
 * Naively splitting on the first colon works for every address a small deployment will
 * ever produce and fails on the one that matters: `[::1]:21117` becomes the host `[` with
 * no port, which then silently connects somewhere else.
 *
 * @param {string} endpoint
 * @returns {{host: string, port: number | null}}
 */
export function splitHostPort(endpoint) {
    const s = String(endpoint ?? '').trim();
    if (!s) return { host: '', port: null };

    if (s.startsWith('[')) {
        const end = s.indexOf(']');
        if (end < 0) return { host: s, port: null };
        const host = s.slice(0, end + 1);
        const rest = s.slice(end + 1);
        const port = rest.startsWith(':') ? Number(rest.slice(1)) : NaN;
        return { host, port: Number.isInteger(port) && port > 0 ? port : null };
    }

    // More than one colon and no brackets: a bare IPv6 literal, which carries no port.
    const first = s.indexOf(':');
    if (first < 0) return { host: s, port: null };
    if (s.indexOf(':', first + 1) >= 0) return { host: `[${s}]`, port: null };

    const port = Number(s.slice(first + 1));
    return {
        host: s.slice(0, first),
        port: Number.isInteger(port) && port > 0 ? port : null,
    };
}

/**
 * WebSocket URLs for a deployment.
 *
 * Ports are derived, not configured: hbbs binds `port + 2` and hbbr binds `port + 2`,
 * unconditionally, in every stock build since server 1.1.6.
 *
 * A port written into the host string is honoured, because that is how a non-default
 * deployment is described everywhere else — including in the address the client itself is
 * configured with. Following the same convention, hbbr sits one above hbbs, so a host of
 * `example.com:31116` yields WebSocket ports 31118 and 31119. Explicit port options
 * override both.
 *
 * `wss` is mandatory in production — the servers speak plain `ws` only, so a TLS
 * terminator must sit in front, and an HTTPS page cannot open `ws://` anyway. A
 * domain-based deployment conventionally routes `/ws/id` and `/ws/relay`; the server
 * ignores the request path entirely, so that split is purely an nginx convention.
 *
 * @param {object} opts
 * @param {string} opts.host
 * @param {string} [opts.relayHost] When the relay is not on the id server's host.
 * @param {number} [opts.rendezvousPort]
 * @param {number} [opts.relayPort]
 * @param {boolean} [opts.secure]
 * @param {boolean} [opts.pathRouted] Use `/ws/id` and `/ws/relay` instead of ports.
 */
export function endpoints({
    host, relayHost = '', rendezvousPort, relayPort, secure = false, pathRouted = false,
    rendezvousUrl = '', relayUrl = '',
}) {
    // Explicit URLs win. A deployment behind a reverse proxy rarely exposes the ports at
    // all, and its wss endpoints may live on another hostname entirely.
    if (rendezvousUrl && relayUrl) return { rendezvous: rendezvousUrl, relay: relayUrl };

    const scheme = secure ? 'wss' : 'ws';
    const id = splitHostPort(host);
    const relay = splitHostPort(relayHost || host);

    if (pathRouted) {
        return {
            rendezvous: `${scheme}://${id.host}/ws/id`,
            relay: `${scheme}://${relay.host}/ws/relay`,
        };
    }

    const rp = rendezvousPort ?? id.port ?? DEFAULT_RENDEZVOUS_PORT;
    // A port on a shared host string belongs to hbbs, so hbbr is one above it. A separate
    // relay host speaks for itself, and falls back to the standard port rather than to an
    // offset from a machine it has nothing to do with.
    const lp = relayPort ?? (relayHost
        ? (relay.port ?? DEFAULT_RELAY_PORT)
        : (id.port !== null ? id.port + 1 : DEFAULT_RELAY_PORT));

    return {
        rendezvous: `${scheme}://${id.host}:${rp + 2}`,
        relay: `${scheme}://${relay.host}:${lp + 2}`,
    };
}
