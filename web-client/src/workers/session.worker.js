/**
 * The session, running entirely off the main thread.
 *
 * Everything on the hot path lives here: the WebSocket, secretbox decryption, protobuf
 * decoding, video decode, and compositing into an OffscreenCanvas. Frame bytes never
 * cross a thread boundary, so main-thread cost does not scale with bitrate.
 *
 * That last point is the whole reason for this file. Compositing, secretbox decryption and
 * protobuf decoding all scale with resolution and bitrate, and on the main thread they
 * show up directly as dropped animation frames. Pure-JS XSalsa20-Poly1305 alone is
 * meaningful work at 4K bitrates, before any decoding happens.
 *
 * Stays on the main thread, deliberately:
 *  - Audio. AudioContext and AudioWorklet are not available to workers, so Opus packets
 *    are forwarded up. They are tiny (~50 bytes each) and infrequent next to video.
 *  - Input encoding. Cheap, and it keeps the encoders next to the DOM events they read.
 *
 * Protocol: main → worker {connect, send, raw, switchDisplay, refresh, close}
 *           worker → main {state, peerInfo, audioFormat, audio, chat, stats, closed, error}
 */

import { RustDeskSession } from '../session/machine.js';
import { CodecCapabilities, probeDecodable } from '../media/codec.js';
import { VideoStreamDecoder } from '../media/decoder.js';
import { VideoSurface } from '../render/surface.js';
import { CursorLayer } from '../render/cursor.js';

/** @type {RustDeskSession | null} */
let session = null;
/** @type {VideoStreamDecoder | null} */
let decoder = null;
/** @type {VideoSurface | null} */
let surface = null;
/** @type {CursorLayer | null} */
let cursor = null;
/** @type {CodecCapabilities | null} */
let codecs = null;

let frames = 0;
let bytes = 0;
let keyFrames = 0;
let startedAt = 0;
let firstFrameAt = 0;

const post = (msg, transfer) => globalThis.postMessage(msg, transfer ?? []);

/**
 * @param {{video: OffscreenCanvas, cursor: OffscreenCanvas, opts: object}} payload
 */
async function connect({ video, cursor: cursorCanvas, opts }) {
    startedAt = performance.now();
    frames = 0; bytes = 0; keyFrames = 0; firstFrameAt = 0;

    surface = new VideoSurface(video);
    cursor = new CursorLayer(cursorCanvas);
    surface.onResize = (w, h) => {
        cursor.resize(w, h);
        post({ type: 'resize', width: w, height: h });
    };

    const decodable = await probeDecodable();
    if (decodable.size === 0) {
        post({ type: 'error', code: 'no-webcodecs', message: 'WebCodecs unavailable in this worker' });
        return;
    }
    codecs = new CodecCapabilities(decodable);

    decoder = new VideoStreamDecoder({
        onFrame: (f) => surface.draw(f),
        onError: (err, codec) => {
            post({ type: 'decodeError', codec, message: err.message });
            if (codecs.markFailure(codec)) {
                session?.send({ misc: { option: { supported_decoding: codecs.toSupportedDecoding() } } });
            }
        },
        onKeyFrameNeeded: () => refresh(),
    });

    session = new RustDeskSession({ ...opts, codecs });

    session.onState = (s) => post({ type: 'state', state: s });
    session.onPeerInfo = (info) => {
        cursor.setDisplay(info.displays[info.current_display ?? 0] ?? {});
        // PeerInfo contains only plain values, so it survives structured clone intact.
        post({
            type: 'peerInfo',
            info: JSON.parse(JSON.stringify(info, (k, v) => (typeof v === 'bigint' ? String(v) : v))),
            encrypted: session.encrypted,
            // Surfaced so the UI can warn: a downgrade means the session, including the
            // password proof and every keystroke, crosses the relay in plaintext.
            downgradeReason: session.downgradeReason,
        });
    };
    session.onVideoFrame = (f) => {
        frames++;
        if (f.key) keyFrames++;
        for (const u of f.units) bytes += u.data.length;
        if (!firstFrameAt) firstFrameAt = performance.now();
        decoder.decode(f);
    };
    session.onCursor = (c) => {
        if (c.type === 'shape') cursor.setShape(c);
        else if (c.type === 'id') cursor.useShape(c.id);
        else if (c.type === 'position') cursor.setPosition(c.x, c.y);
    };
    session.onDisplaySwitch = (d) => {
        cursor.setDisplay(d);
        decoder.reset();
        post({ type: 'displaySwitch', display: d.display ?? 0, width: d.width, height: d.height });
    };
    session.onAudioFormat = (f) => post({ type: 'audioFormat', format: f });
    session.onAudioFrame = (data) => {
        // Copy out of the frame buffer before transferring: the source is a view into the
        // receive buffer, which is reused.
        const copy = new Uint8Array(data);
        post({ type: 'audio', data: copy }, [copy.buffer]);
    };
    session.onChat = (text) => post({ type: 'chat', text });
    session.onClipboard = (entries) => {
        // Copy each payload out of the receive buffer, which is reused, before it crosses
        // the thread boundary.
        post({
            type: 'clipboard',
            entries: entries.map((e) => ({
                format: e.format ?? 0,
                compress: e.compress === true,
                content: new Uint8Array(e.content ?? []),
                special_name: e.special_name ?? '',
            })),
        });
    };
    session.onPermissions = (p) => post({ type: 'permissions', denied: p.denied() });
    session.onClose = (err) => post({ type: 'closed', code: err.code, message: err.message });

    try {
        await session.connect();
    } catch (err) {
        post({ type: 'error', code: err.code ?? 'error', message: err.message });
    }
}

/**
 * Requests a key frame for the display we are actually viewing.
 *
 * Rate-limited because a refresh restarts the peer's capture pipeline for EVERY viewer of
 * that display, and the decoder fires `onKeyFrameNeeded` on every error — an unbounded
 * path would hammer the host during a persistently bad stream.
 */
let lastRefreshAt = -Infinity;
let refreshCount = 0;
const REFRESH_INTERVAL_MS = 10_000;
const MAX_REFRESHES = 20;

function refresh() {
    if (!session || session.state !== 'connected') return false;
    const now = performance.now();
    if (refreshCount >= MAX_REFRESHES || now - lastRefreshAt < REFRESH_INTERVAL_MS) return false;
    lastRefreshAt = now;
    refreshCount++;
    session.send({ misc: { refresh_video_display: session.activeDisplay } });
    decoder?.reset();
    return true;
}

/** @param {number} display */
function switchDisplay(display) {
    if (!session || session.state !== 'connected') return;
    // All three, in order: a display already captured for another viewer emits no new key
    // frame on switch alone, and the canvas would stay blank until one happened to arrive.
    session.send({ misc: { switch_display: { display } } });
    session.send({ misc: { capture_displays: { set: [display] } } });
    session.send({ misc: { refresh_video_display: display } });
    // Recovery refreshes read this; without it a decode error after switching asks the
    // peer to re-key the monitor we are no longer watching, forever.
    session.activeDisplay = display;
    lastRefreshAt = performance.now();
    decoder?.reset();
    const info = session.peerInfo?.displays?.[display];
    if (info) cursor?.setDisplay(info);
}

function stats() {
    const d = decoder?.stats() ?? {};
    const s = surface?.stats() ?? {};
    const c = cursor?.stats() ?? {};
    const secs = (performance.now() - startedAt) / 1000;
    return {
        type: 'stats',
        state: session?.state ?? 'idle',
        encrypted: session?.encrypted ?? false,
        frames,
        bytes,
        keyFrames,
        fps: frames / Math.max(secs, 0.001),
        ttff: firstFrameAt ? firstFrameAt - startedAt : 0,
        rtt: session?.lastDelayMs ?? null,
        decoder: d,
        surface: s,
        cursor: { ...c, position: undefined },
        bufferedAmount: session?.bufferedAmount ?? 0,
    };
}

globalThis.onmessage = async (ev) => {
    const msg = ev.data;
    switch (msg.type) {
        case 'connect':
            await connect(msg);
            break;
        case 'send':
            session?.send(msg.message);
            break;
        case 'raw':
            // Pre-encoded Message bytes from the main thread's input encoders.
            if (session?.socket && session.stream) session.socket.send(session.stream.encrypt(msg.bytes));
            break;
        case 'switchDisplay':
            switchDisplay(msg.display);
            break;
        case 'refresh':
            refresh();
            break;
        case 'stats':
            post(stats());
            break;
        case 'close':
            decoder?.close();
            session?.close();
            session = null;
            break;
        default:
            break;
    }
};
