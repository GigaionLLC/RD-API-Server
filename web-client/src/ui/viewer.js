/**
 * Development viewer.
 *
 * Wires session → decoder → surface and reports what is happening. Deliberately
 * view-only: no input is sent, because the peer under test is usually the machine
 * running the browser.
 *
 * Decode and render run on the main thread here. That is a staging decision, not the
 * destination — moving them into a worker with `transferControlToOffscreen` is the
 * planned performance step, and this file exists to prove the pipeline is correct first.
 */

import { RustDeskSession } from '../session/machine.js';
import { CodecCapabilities, probeDecodable } from '../media/codec.js';
import { VideoStreamDecoder } from '../media/decoder.js';
import { VideoSurface } from '../render/surface.js';
import { CursorLayer } from '../render/cursor.js';

const $ = (id) => document.getElementById(id);
const statusEl = $('status');
const statsEl = $('stats');

/** @type {RustDeskSession | null} */
let session = null;
/** @type {VideoStreamDecoder | null} */
let decoder = null;
/** @type {VideoSurface | null} */
let surface = null;
/** @type {CursorLayer | null} */
let cursor = null;

let frames = 0;
let bytes = 0;
let keyFrames = 0;
let firstFrameAt = 0;
let startedAt = 0;

// Exposed so a browser-driven test can assert on real numbers rather than screenshots.
globalThis.__viewer = {
    get state() { return session?.state ?? 'idle'; },
    get painted() { return surface?.stats().painted ?? 0; },
    get decoded() { return decoder?.stats().decoded ?? 0; },
    get codec() { return decoder?.stats().codec ?? null; },
    get size() { return surface ? [surface.width, surface.height] : [0, 0]; },
    get frames() { return frames; },
    get timeToFirstFrameMs() { return firstFrameAt ? firstFrameAt - startedAt : 0; },
    get encrypted() { return session?.encrypted ?? false; },
    get displays() { return session?.peerInfo?.displays?.length ?? 0; },
    get error() { return lastError; },
    get cursor() { return cursor?.stats() ?? null; },
    switchTo(i) { $('display').value = String(i); switchDisplay(); },
};

let lastError = null;

/** @param {string} text @param {'ok'|'err'|''} [kind] */
function setStatus(text, kind = '') {
    statusEl.textContent = text;
    statusEl.className = kind;
}

function paintStats() {
    if (!session) { statsEl.textContent = 'no session'; return; }
    const d = decoder?.stats() ?? {};
    const s = surface?.stats() ?? {};
    const c = cursor?.stats() ?? {};
    const secs = (performance.now() - startedAt) / 1000;
    statsEl.textContent = [
        `state    ${session.state}${session.encrypted ? ' · encrypted' : ' · PLAINTEXT'}`,
        `codec    ${d.codec ?? '—'}  ${s.width || 0}x${s.height || 0}`,
        `frames   ${frames} recv · ${d.decoded ?? 0} decoded · ${s.painted ?? 0} painted`,
        `keys     ${keyFrames}  dropped ${d.dropped ?? 0}  queue ${d.queueSize ?? 0}`,
        `data     ${(bytes / 1024).toFixed(0)} KiB  ${(frames / Math.max(secs, 0.001)).toFixed(1)} fps`,
        `ttff     ${firstFrameAt ? Math.round(firstFrameAt - startedAt) : '—'} ms`,
        `rtt      ${session.lastDelayMs ?? '—'} ms`,
        `cursor   ${c.cached ?? 0} cached · ${c.decoded ?? 0} decoded · ${c.missing ?? 0} missing` +
            `${c.embedded ? ' · embedded' : ''}`,
    ].join('\n');
}

async function connect() {
    lastError = null;
    frames = 0; bytes = 0; keyFrames = 0; firstFrameAt = 0;
    startedAt = performance.now();

    const canvas = /** @type {HTMLCanvasElement} */ ($('video'));
    surface = new VideoSurface(canvas);
    cursor = new CursorLayer(/** @type {HTMLCanvasElement} */ ($('cursor')));
    // Keep the overlay the same pixel size as the video so one coordinate space serves
    // both; CSS object-fit then scales them identically.
    surface.onResize = (w, h) => cursor.resize(w, h);

    // Advertise only what this browser genuinely decodes: a codec claimed here is a
    // codec every other viewer of the same peer is forced onto.
    const decodable = await probeDecodable();
    if (decodable.size === 0) {
        setStatus('WebCodecs unavailable — needs a secure context', 'err');
        lastError = 'no-webcodecs';
        return;
    }

    decoder = new VideoStreamDecoder({
        onFrame: (f) => surface.draw(f),
        onError: (err, codec) => {
            lastError = `${codec}: ${err.message}`;
            // Retiring a codec after repeated failure makes the peer re-encode in
            // something we can actually decode.
            if (session && codecs.markFailure(codec)) {
                session.send({ misc: { option: { supported_decoding: codecs.toSupportedDecoding() } } });
            }
        },
        onKeyFrameNeeded: () => requestRefresh(),
    });

    const codecs = new CodecCapabilities(decodable);

    session = new RustDeskSession({
        host: $('host').value.trim(),
        peerId: $('peer').value.trim(),
        serverKey: $('key').value.trim(),
        password: $('password').value,
        myId: 'web-client',
        myName: 'Web Client',
        secure: location.protocol === 'https:',
        codecs,
    });

    session.onState = (s) => { setStatus(s); paintStats(); };

    session.onPeerInfo = (info) => {
        const sel = /** @type {HTMLSelectElement} */ ($('display'));
        sel.innerHTML = '';
        info.displays.forEach((d, i) => {
            const opt = document.createElement('option');
            opt.value = String(i);
            opt.textContent = `${i}: ${d.width}x${d.height}${d.name ? ` ${d.name}` : ''}`;
            sel.appendChild(opt);
        });
        sel.value = String(info.current_display ?? 0);
        sel.disabled = false;
        cursor.setDisplay(info.displays[info.current_display ?? 0] ?? {});
        setStatus(`${info.username}@${info.hostname} · ${info.platform}`, 'ok');
    };

    session.onCursor = (c) => {
        if (c.type === 'shape') cursor.setShape(c);
        else if (c.type === 'id') cursor.useShape(c.id);
        else if (c.type === 'position') cursor.setPosition(c.x, c.y);
    };

    session.onDisplaySwitch = (d) => {
        // Arrives on the video queue, so it is correctly ordered against frames: content
        // before it belongs to the old geometry, content after to the new.
        cursor.setDisplay(d);
        decoder?.reset();
    };

    session.onVideoFrame = (f) => {
        frames++;
        if (f.key) keyFrames++;
        for (const u of f.units) bytes += u.data.length;
        if (!firstFrameAt) firstFrameAt = performance.now();
        decoder.decode(f);
        paintStats();
    };

    session.onClose = (err) => {
        lastError = err.message;
        setStatus(`closed: ${err.message}`, 'err');
        teardown();
    };

    $('connect').disabled = true;
    $('disconnect').disabled = false;

    try {
        await session.connect();
    } catch (err) {
        lastError = err.message;
        setStatus(`${err.code ?? 'error'}: ${err.message}`, 'err');
        teardown();
    }
}

function requestRefresh() {
    // Refresh restarts the peer's capture pipeline for every viewer of that display,
    // so it is a recovery action, not routine error handling.
    if (!session || session.state !== 'connected') return;
    const display = Number(/** @type {HTMLSelectElement} */ ($('display')).value || 0);
    session.send({ misc: { refresh_video_display: display } });
    decoder?.reset();
}

function switchDisplay() {
    if (!session || session.state !== 'connected') return;
    const display = Number(/** @type {HTMLSelectElement} */ ($('display')).value || 0);
    // All three are needed: switch, then narrow the capture set, then force a key frame —
    // without the refresh, a display already captured for another viewer emits no new
    // key frame and the canvas stays blank until one happens to arrive.
    session.send({ misc: { switch_display: { display } } });
    session.send({ misc: { capture_displays: { set: [display] } } });
    session.send({ misc: { refresh_video_display: display } });
    decoder?.reset();
    const info = session.peerInfo?.displays?.[display];
    if (info) cursor?.setDisplay(info);
}

function teardown() {
    decoder?.close();
    session?.close();
    cursor = null;
    $('connect').disabled = false;
    $('disconnect').disabled = true;
    paintStats();
}

$('connect').addEventListener('click', () => { connect(); });
$('disconnect').addEventListener('click', () => { setStatus('disconnected'); teardown(); });
$('display').addEventListener('change', switchDisplay);

// Prefill from the query string so a harness can drive this without typing. Values are
// never persisted; this is a development page.
const params = new URLSearchParams(location.search);
for (const k of ['host', 'peer', 'key', 'password']) {
    if (params.has(k)) $(k).value = params.get(k);
}
if (params.get('auto') === '1') connect();

setInterval(paintStats, 500);
