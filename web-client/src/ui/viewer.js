/**
 * Development viewer.
 *
 * Supports two pipelines so they can be compared on the same page against the same peer:
 *
 *   worker  — socket, decryption, protobuf, decode and compositing all off the main
 *             thread. This is the intended production path.
 *   main    — everything on the main thread, which is what both competing
 *             implementations do. Kept solely as the baseline for measurement.
 *
 * View-only: no input is sent, because the peer under test is usually the machine
 * running the browser.
 */

import { RustDeskSession } from '../session/machine.js';
import { WorkerSession } from '../session/client.js';
import { CodecCapabilities, probeDecodable } from '../media/codec.js';
import { VideoStreamDecoder } from '../media/decoder.js';
import { VideoSurface } from '../render/surface.js';
import { CursorLayer } from '../render/cursor.js';
import { AudioStreamPlayer } from '../media/audio.js';
import { JankProbe } from './jank.js';

const $ = (id) => document.getElementById(id);
const statusEl = $('status');
const statsEl = $('stats');

let mode = 'worker';
let session = null;      // RustDeskSession (main mode) or WorkerSession (worker mode)
let decoder = null;      // main mode only
let surface = null;      // main mode only
let cursor = null;       // main mode only
let audio = null;
let codecs = null;
const jank = new JankProbe();

let frames = 0;
let bytes = 0;
let keyFrames = 0;
let firstFrameAt = 0;
let startedAt = 0;
let lastError = null;
let workerStats = null;

/**
 * Cumulative main-thread time spent handling video, measured directly.
 *
 * The rAF-based JankProbe only samples when the page is compositing, which is not always
 * true in an automated harness — and jank is a downstream symptom anyway. This counts the
 * actual blocking work: in main mode, decrypt/decode/draw per frame; in worker mode it
 * should stay at zero, because no frame byte reaches this thread.
 */
let mainVideoWorkMs = 0;
let mainVideoSamples = 0;
let mainDrawMs = 0;
let mainDrawSamples = 0;

globalThis.__viewer = {
    get mode() { return mode; },
    get state() { return session?.state ?? 'idle'; },
    get frames() { return mode === 'worker' ? (workerStats?.frames ?? 0) : frames; },
    get painted() { return mode === 'worker' ? (workerStats?.surface?.painted ?? 0) : (surface?.stats().painted ?? 0); },
    get decoded() { return mode === 'worker' ? (workerStats?.decoder?.decoded ?? 0) : (decoder?.stats().decoded ?? 0); },
    get codec() { return mode === 'worker' ? (workerStats?.decoder?.codec ?? null) : (decoder?.stats().codec ?? null); },
    get size() {
        if (mode === 'worker') return [workerStats?.surface?.width ?? 0, workerStats?.surface?.height ?? 0];
        return surface ? [surface.width, surface.height] : [0, 0];
    },
    get cursor() { return mode === 'worker' ? (workerStats?.cursor ?? null) : (cursor?.stats() ?? null); },
    get audio() { audio?.requestStats(); return audio?.stats() ?? null; },
    get jank() { return jank.stats(); },
    get mainThreadVideoWork() {
        const f = mode === 'worker' ? (workerStats?.frames ?? 0) : frames;
        const total = mainVideoWorkMs + mainDrawMs;
        return {
            framesReceived: f,
            // Receive-path work: secretbox decrypt, protobuf decode, decode() enqueue.
            receiveMs: +mainVideoWorkMs.toFixed(2),
            // Composite work, in the WebCodecs output callback.
            drawMs: +mainDrawMs.toFixed(2),
            drawSamples: mainDrawSamples,
            totalMs: +total.toFixed(2),
            perFrameMs: f ? +(total / f).toFixed(3) : 0,
            note: mode === 'worker'
                ? 'worker mode: no frame byte reaches this thread, so zero is expected'
                : 'main mode: decrypt, protobuf decode and canvas composite all block here '
                  + '(WebCodecs decode itself is off-thread in both modes)',
        };
    },
    get workerStats() { return workerStats; },
    get error() { return lastError; },
    get timeToFirstFrameMs() {
        if (mode === 'worker') return Math.round(workerStats?.ttff ?? 0);
        return firstFrameAt ? Math.round(firstFrameAt - startedAt) : 0;
    },
    switchTo(i) { $('display').value = String(i); switchDisplay(); },
    setMuted(v) { audio?.setMuted(v); $('mute').checked = v; },
    resetJank() { jank.gaps.length = 0; },
};

function setStatus(text, kind = '') {
    statusEl.textContent = text;
    statusEl.className = kind;
}

/** Canvas transfer to a worker is permanent, so each connect needs fresh elements. */
function freshCanvases() {
    for (const id of ['video', 'cursor']) {
        const old = $(id);
        const next = document.createElement('canvas');
        next.id = id;
        old.replaceWith(next);
    }
    return { video: $('video'), cursorCanvas: $('cursor') };
}

function sessionOptions() {
    return {
        host: $('host').value.trim(),
        peerId: $('peer').value.trim(),
        serverKey: $('key').value.trim(),
        password: $('password').value,
        myId: 'web-client',
        myName: 'Web Client',
        secure: location.protocol === 'https:',
    };
}

function populateDisplays(info) {
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
    setStatus(`${info.username}@${info.hostname} · ${info.platform}`, 'ok');
}

async function connect() {
    lastError = null;
    workerStats = null;
    frames = 0; bytes = 0; keyFrames = 0; firstFrameAt = 0;
    mainVideoWorkMs = 0; mainVideoSamples = 0; mainDrawMs = 0; mainDrawSamples = 0;
    startedAt = performance.now();
    mode = $('mode').value;

    const { video, cursorCanvas } = freshCanvases();
    audio = new AudioStreamPlayer({ muted: $('mute').checked });

    $('connect').disabled = true;
    $('disconnect').disabled = false;
    jank.start();

    if (mode === 'worker') await connectWorker(video, cursorCanvas);
    else await connectMain(video, cursorCanvas);
}

/** @param {HTMLCanvasElement} video @param {HTMLCanvasElement} cursorCanvas */
async function connectWorker(video, cursorCanvas) {
    const ws = new WorkerSession();
    session = ws;

    ws.onState = (s) => setStatus(s);
    ws.onPeerInfo = (info) => populateDisplays(info);
    ws.onAudioFormat = async (f) => { await audio.setFormat(f); await audio.unlock(); };
    ws.onAudioFrame = (data) => audio.push(data);
    ws.onStats = (s) => { workerStats = s; };
    ws.onClose = (err) => { lastError = err.message; setStatus(`closed: ${err.message}`, 'err'); teardown(); };

    ws.connect({ videoCanvas: video, cursorCanvas, session: sessionOptions() });
}

/** @param {HTMLCanvasElement} video @param {HTMLCanvasElement} cursorCanvas */
async function connectMain(video, cursorCanvas) {
    surface = new VideoSurface(video);
    cursor = new CursorLayer(cursorCanvas);
    surface.onResize = (w, h) => cursor.resize(w, h);

    const decodable = await probeDecodable();
    if (decodable.size === 0) {
        setStatus('WebCodecs unavailable — needs a secure context', 'err');
        lastError = 'no-webcodecs';
        return;
    }
    codecs = new CodecCapabilities(decodable);

    decoder = new VideoStreamDecoder({
        onFrame: (f) => {
            // The real main-thread cost sits here, not at decode(). WebCodecs decodes on
            // its own thread and hands the frame back via this callback, where the canvas
            // composite actually happens.
            const t0 = performance.now();
            surface.draw(f);
            mainDrawMs += performance.now() - t0;
            mainDrawSamples++;
        },
        onError: (err, codec) => {
            lastError = `${codec}: ${err.message}`;
            if (session && codecs.markFailure(codec)) {
                session.send({ misc: { option: { supported_decoding: codecs.toSupportedDecoding() } } });
            }
        },
        onKeyFrameNeeded: () => refresh(),
    });

    const s = new RustDeskSession({ ...sessionOptions(), codecs });
    session = s;

    s.onState = (st) => setStatus(st);
    s.onPeerInfo = (info) => { populateDisplays(info); cursor.setDisplay(info.displays[info.current_display ?? 0] ?? {}); };
    s.onAudioFormat = async (f) => { await audio.setFormat(f); await audio.unlock(); };
    s.onAudioFrame = (bytesIn) => audio.push(bytesIn);
    s.onCursor = (c) => {
        if (c.type === 'shape') cursor.setShape(c);
        else if (c.type === 'id') cursor.useShape(c.id);
        else if (c.type === 'position') cursor.setPosition(c.x, c.y);
    };
    s.onDisplaySwitch = (d) => { cursor.setDisplay(d); decoder.reset(); };
    s.onVideoFrame = (f) => {
        const t0 = performance.now();
        frames++;
        if (f.key) keyFrames++;
        for (const u of f.units) bytes += u.data.length;
        if (!firstFrameAt) firstFrameAt = t0;
        decoder.decode(f);
        mainVideoWorkMs += performance.now() - t0;
        mainVideoSamples++;
    };
    s.onClose = (err) => { lastError = err.message; setStatus(`closed: ${err.message}`, 'err'); teardown(); };

    try {
        await s.connect();
    } catch (err) {
        lastError = err.message;
        setStatus(`${err.code ?? 'error'}: ${err.message}`, 'err');
        teardown();
    }
}

function refresh() {
    if (!session) return;
    if (mode === 'worker') session.refresh();
    else if (session.state === 'connected') {
        session.send({ misc: { refresh_video_display: Number($('display').value || 0) } });
        decoder?.reset();
    }
}

function switchDisplay() {
    if (!session) return;
    const display = Number(/** @type {HTMLSelectElement} */ ($('display')).value || 0);
    if (mode === 'worker') { session.switchDisplay(display); return; }
    if (session.state !== 'connected') return;
    session.send({ misc: { switch_display: { display } } });
    session.send({ misc: { capture_displays: { set: [display] } } });
    session.send({ misc: { refresh_video_display: display } });
    decoder?.reset();
    const info = session.peerInfo?.displays?.[display];
    if (info) cursor?.setDisplay(info);
}

function paintStats() {
    if (!session) { statsEl.textContent = 'no session'; return; }
    if (mode === 'worker') session.requestStats?.();

    const j = jank.stats();
    const a = audio?.stats() ?? {};
    let head;
    let body;

    if (mode === 'worker') {
        const w = workerStats;
        head = `state    ${session.state}${w?.encrypted ? ' · encrypted' : ''} · WORKER`;
        body = w ? [
            `codec    ${w.decoder?.codec ?? '—'}  ${w.surface?.width || 0}x${w.surface?.height || 0}`,
            `frames   ${w.frames} recv · ${w.decoder?.decoded ?? 0} dec · ${w.surface?.painted ?? 0} painted`,
            `keys     ${w.keyFrames}  dropped ${w.decoder?.dropped ?? 0}  queue ${w.decoder?.queueSize ?? 0}`,
            `data     ${(w.bytes / 1024).toFixed(0)} KiB  ${w.fps.toFixed(1)} fps`,
            `ttff     ${Math.round(w.ttff)} ms   rtt ${w.rtt ?? '—'} ms`,
            `cursor   ${w.cursor?.cached ?? 0} cached · ${w.cursor?.missing ?? 0} missing`,
        ] : ['waiting for worker stats…'];
    } else {
        const d = decoder?.stats() ?? {};
        const s = surface?.stats() ?? {};
        const c = cursor?.stats() ?? {};
        const secs = (performance.now() - startedAt) / 1000;
        head = `state    ${session.state}${session.encrypted ? ' · encrypted' : ''} · MAIN THREAD`;
        body = [
            `codec    ${d.codec ?? '—'}  ${s.width || 0}x${s.height || 0}`,
            `frames   ${frames} recv · ${d.decoded ?? 0} dec · ${s.painted ?? 0} painted`,
            `keys     ${keyFrames}  dropped ${d.dropped ?? 0}  queue ${d.queueSize ?? 0}`,
            `data     ${(bytes / 1024).toFixed(0)} KiB  ${(frames / Math.max(secs, 0.001)).toFixed(1)} fps`,
            `ttff     ${firstFrameAt ? Math.round(firstFrameAt - startedAt) : '—'} ms   rtt ${session.lastDelayMs ?? '—'} ms`,
            `cursor   ${c.cached ?? 0} cached · ${c.missing ?? 0} missing`,
        ];
    }

    statsEl.textContent = [
        head,
        ...body,
        `audio    ${a.format ? `${a.format.sampleRate}Hz x${a.format.channels}` : '—'} · ${a.packets ?? 0} pkt · ${a.errors ?? 0} err${a.muted ? ' · muted' : ''}`,
        `jank     p50 ${j.p50 ?? '—'}ms · p95 ${j.p95 ?? '—'}ms · max ${j.max ?? '—'}ms · >${jank.budgetMs}ms: ${j.overBudgetPct ?? '—'}%`,
    ].join('\n');
}

function teardown() {
    jank.stop();
    if (mode === 'main') decoder?.close();
    session?.close();
    audio?.close();
    decoder = null; surface = null; cursor = null; audio = null;
    $('connect').disabled = false;
    $('disconnect').disabled = true;
}

$('connect').addEventListener('click', () => { connect(); });
$('disconnect').addEventListener('click', () => { setStatus('disconnected'); teardown(); });
$('display').addEventListener('change', switchDisplay);
$('mute').addEventListener('change', (e) => audio?.setMuted(e.target.checked));

const params = new URLSearchParams(location.search);
for (const k of ['host', 'peer', 'key', 'password']) {
    if (params.has(k)) $(k).value = params.get(k);
}
if (params.has('mode')) $('mode').value = params.get('mode');
if (params.get('auto') === '1') connect();

setInterval(paintStats, 500);
