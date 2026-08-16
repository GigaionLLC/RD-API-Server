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
            clearTimeout(waiter.timer);
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
            clearTimeout(w.timer);
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
     * @returns {Promise<Uint8Array>}
     */
    next(label = 'frame') {
        const buffered = this._pending.shift();
        if (buffered) return Promise.resolve(buffered);
        if (this._closed) return Promise.reject(this._closed);
        return new Promise((resolve, reject) => {
            const timer = setTimeout(
                () => {
                    const i = this._waiters.findIndex((w) => w.timer === timer);
                    if (i >= 0) this._waiters.splice(i, 1);
                    reject(new TransportError(`timed out waiting for ${label}`));
                },
                this.timeoutMs,
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

/**
 * WebSocket URLs for a deployment.
 *
 * Ports are derived, not configured: hbbs binds `port + 2` and hbbr binds `port + 2`,
 * unconditionally, in every stock build since server 1.1.6.
 *
 * `wss` is mandatory in production — the servers speak plain `ws` only, so a TLS
 * terminator must sit in front, and an HTTPS page cannot open `ws://` anyway. A
 * domain-based deployment conventionally routes `/ws/id` and `/ws/relay`; the server
 * ignores the request path entirely, so that split is purely an nginx convention.
 *
 * @param {object} opts
 * @param {string} opts.host
 * @param {number} [opts.rendezvousPort]
 * @param {number} [opts.relayPort]
 * @param {boolean} [opts.secure]
 * @param {boolean} [opts.pathRouted] Use `/ws/id` and `/ws/relay` instead of ports.
 */
export function endpoints({ host, rendezvousPort = 21116, relayPort = 21117, secure = false, pathRouted = false }) {
    const scheme = secure ? 'wss' : 'ws';
    const bare = host.split(':')[0];
    if (pathRouted) {
        return { rendezvous: `${scheme}://${bare}/ws/id`, relay: `${scheme}://${bare}/ws/relay` };
    }
    return {
        rendezvous: `${scheme}://${bare}:${rendezvousPort + 2}`,
        relay: `${scheme}://${bare}:${relayPort + 2}`,
    };
}
