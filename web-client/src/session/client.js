/**
 * Main-thread facade over the session worker.
 *
 * Presents roughly the same surface as RustDeskSession, but the socket, decryption,
 * protobuf decoding, video decode and compositing all run in a worker. Callers hand it
 * two canvases and receive events; frame bytes never reach this thread.
 *
 * RustDeskSession remains directly usable for headless consumers — the Node integration
 * tools drive it that way, where a worker would add nothing.
 */

const WORKER_URL = new URL('../workers/session.worker.js', import.meta.url);

export class WorkerSession {
    constructor() {
        /** @type {Worker | null} */
        this.worker = null;
        this.state = 'idle';
        this.peerInfo = null;
        this.encrypted = false;
        /** Non-null when the session fell back to plaintext; the UI must surface this. */
        this.downgradeReason = null;
        this.denied = [];
        /** @type {{uac: boolean, elevated: boolean, portable: boolean, pending: boolean, response: string | null}} */
        this.elevation = { uac: false, elevated: false, portable: false, pending: false, response: null };
        /** @type {object | null} */
        this.lastStats = null;
        this.transferred = false;
    }

    /**
     * @param {object} opts
     * @param {HTMLCanvasElement} opts.videoCanvas
     * @param {HTMLCanvasElement} opts.cursorCanvas
     * @param {object} opts.session Options for RustDeskSession.
     */
    connect({ videoCanvas, cursorCanvas, session }) {
        if (this.transferred) throw new Error('canvases are already transferred; use fresh ones');
        this.worker = new Worker(WORKER_URL, { type: 'module' });
        this.worker.onmessage = (ev) => this._onMessage(ev.data);

        // Once transferred, these canvases belong to the worker permanently — the main
        // thread can no longer get a 2D context from them.
        const video = videoCanvas.transferControlToOffscreen();
        const cursor = cursorCanvas.transferControlToOffscreen();
        this.transferred = true;

        this.worker.postMessage({ type: 'connect', video, cursor, opts: session }, [video, cursor]);
    }

    /** @param {any} msg */
    _onMessage(msg) {
        switch (msg.type) {
            case 'state':
                this.state = msg.state;
                this.onState?.(msg.state);
                break;
            case 'peerInfo':
                this.peerInfo = msg.info;
                this.encrypted = msg.encrypted !== false;
                this.downgradeReason = msg.downgradeReason ?? null;
                this.onPeerInfo?.(msg.info);
                break;
            case 'resize':
                this.onResize?.(msg.width, msg.height);
                break;
            case 'displaySwitch':
                this.onDisplaySwitch?.(msg);
                break;
            case 'audioFormat':
                this.onAudioFormat?.(msg.format);
                break;
            case 'audio':
                this.onAudioFrame?.(msg.data);
                break;
            case 'chat':
                this.onChat?.(msg.text);
                break;
            case 'clipboard':
                this.onClipboard?.(msg.entries);
                break;
            case 'permissions':
                this.denied = msg.denied;
                this.onPermissions?.(msg.denied);
                break;
            case 'messageBox':
                this.onMessageBox?.(msg.box);
                break;
            case 'elevation':
                this.elevation = msg.state;
                this.onElevation?.(msg.state);
                break;
            case 'stats':
                this.lastStats = msg;
                this.encrypted = msg.encrypted;
                this.onStats?.(msg);
                break;
            case 'decodeError':
                this.onDecodeError?.(msg);
                break;
            case 'closed':
            case 'error':
                this.state = 'closed';
                this.onClose?.({ code: msg.code, message: msg.message });
                break;
            default:
                break;
        }
    }

    /** @param {object} message A `Message` shaped object, encoded in the worker. */
    send(message) {
        this.worker?.postMessage({ type: 'send', message });
    }

    /**
     * Pre-encoded `Message` bytes, for the input encoders. Transferred rather than
     * copied — input is frequent enough that the copies would add up.
     * @param {Uint8Array} bytes
     */
    sendRaw(bytes) {
        const copy = new Uint8Array(bytes);
        this.worker?.postMessage({ type: 'raw', bytes: copy }, [copy.buffer]);
    }

    /** @param {number} display */
    switchDisplay(display) {
        this.worker?.postMessage({ type: 'switchDisplay', display });
    }

    refresh() {
        this.worker?.postMessage({ type: 'refresh' });
    }

    /** @param {{username?: string, password?: string}} [creds] Omit for a consent prompt. */
    requestElevation(creds) {
        this.worker?.postMessage({ type: 'elevate', creds });
    }

    requestStats() {
        this.worker?.postMessage({ type: 'stats' });
    }

    close() {
        this.worker?.postMessage({ type: 'close' });
        this.worker?.terminate();
        this.worker = null;
        this.state = 'closed';
    }
}
