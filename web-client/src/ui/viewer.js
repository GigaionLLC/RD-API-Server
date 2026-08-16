/**
 * Remote desktop viewer.
 *
 * Config may be injected by a host application as `window.RD_CONFIG` — the Laravel
 * integration renders it so the operator never types server details — otherwise the
 * connection form is used. Either way this is the same file; there is no build step and
 * no separate "embedded" variant to drift.
 *
 * Two pipelines are selectable. `worker` is the real one: socket, decryption, protobuf,
 * decode and compositing all off the main thread. `main` runs the same pipeline on the
 * main thread and exists only as a measurement baseline.
 */

import { RustDeskSession } from '../session/machine.js';
import { WorkerSession } from '../session/client.js';
import { CodecCapabilities, probeDecodable, customQuality } from '../media/codec.js';
import { VideoStreamDecoder } from '../media/decoder.js';
import { VideoSurface } from '../render/surface.js';
import { CursorLayer } from '../render/cursor.js';
import { AudioStreamPlayer } from '../media/audio.js';
import { InputController } from '../input/controller.js';
import { ClipboardSync } from '../clipboard.js';
import { JankProbe } from './jank.js';

const $ = (id) => document.getElementById(id);
const statusEl = $('status');
const statsEl = $('stats');
const config = globalThis.RD_CONFIG ?? null;

let mode = 'worker';
let session = null;
let decoder = null;
let surface = null;
let cursor = null;
let audio = null;
let input = null;
let clipboard = null;
let codecs = null;
const jank = new JankProbe();

let frames = 0; let bytes = 0; let keyFrames = 0;
let firstFrameAt = 0; let startedAt = 0;
let lastError = null; let workerStats = null;
let remote = { width: 0, height: 0 };
let activeDisplay = {};

let mainVideoWorkMs = 0; let mainDrawMs = 0; let mainDrawSamples = 0;

globalThis.__viewer = {
    get mode() { return mode; },
    get state() { return session?.state ?? 'idle'; },
    get frames() { return mode === 'worker' ? (workerStats?.frames ?? 0) : frames; },
    get painted() { return mode === 'worker' ? (workerStats?.surface?.painted ?? 0) : (surface?.stats().painted ?? 0); },
    get codec() { return mode === 'worker' ? (workerStats?.decoder?.codec ?? null) : (decoder?.stats().codec ?? null); },
    get size() { return [remote.width, remote.height]; },
    get cursor() { return mode === 'worker' ? (workerStats?.cursor ?? null) : (cursor?.stats() ?? null); },
    get audio() { audio?.requestStats(); return audio?.stats() ?? null; },
    get input() { return input?.stats() ?? null; },
    get clipboard() { return clipboard?.stats() ?? null; },
    get jank() { return jank.stats(); },
    get error() { return lastError; },
    get mainThreadVideoWork() {
        const f = mode === 'worker' ? (workerStats?.frames ?? 0) : frames;
        const total = mainVideoWorkMs + mainDrawMs;
        return {
            framesReceived: f,
            receiveMs: +mainVideoWorkMs.toFixed(2),
            drawMs: +mainDrawMs.toFixed(2),
            drawSamples: mainDrawSamples,
            totalMs: +total.toFixed(2),
            perFrameMs: f ? +(total / f).toFixed(3) : 0,
            note: mode === 'worker'
                ? 'worker mode: no frame byte reaches this thread'
                : 'main mode: decrypt, protobuf decode and composite block here '
                  + '(WebCodecs decode is off-thread in both modes)',
        };
    },
    switchTo(i) { $('display').value = String(i); switchDisplay(); },
    setViewOnly(v) { $('viewonly').checked = v; applyViewOnly(); },
};

function setStatus(text, kind = '') {
    statusEl.textContent = text;
    statusEl.className = kind;
}

/** transferControlToOffscreen is permanent, so each connect needs fresh elements. */
function freshCanvases() {
    for (const id of ['video', 'cursor']) {
        const next = document.createElement('canvas');
        next.id = id;
        $(id).replaceWith(next);
    }
    return { video: $('video'), cursorCanvas: $('cursor') };
}

function sessionOptions() {
    if (config) {
        return {
            host: config.host,
            peerId: config.peerId,
            serverKey: config.serverKey ?? '',
            password: $('password')?.value ?? config.password ?? '',
            myId: config.myId ?? 'web-client',
            myName: config.myName ?? 'Web Client',
            secure: config.secure ?? location.protocol === 'https:',
            pathRouted: config.pathRouted ?? false,
            rendezvousUrl: config.rendezvousUrl ?? '',
            relayUrl: config.relayUrl ?? '',
            rendezvousPort: config.rendezvousPort,
            relayPort: config.relayPort,
        };
    }
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
        opt.textContent = `Monitor ${i + 1} · ${d.width}×${d.height}`;
        sel.appendChild(opt);
    });
    const current = info.current_display ?? 0;
    sel.value = String(current);
    activeDisplay = info.displays[current] ?? {};
    document.body.classList.add('connected');
    setStatus(`${info.username || 'peer'}@${info.hostname} · ${info.platform}`, 'ok');
}

function setupInput(canvas) {
    input = new InputController({
        element: canvas,
        send: (b) => (mode === 'worker' ? session.sendRaw(b) : session.socket.send(session.stream.encrypt(b))),
        remoteSize: () => remote,
        display: () => activeDisplay,
        viewOnly: $('viewonly').checked,
    });
    input.attach();

    clipboard = new ClipboardSync({
        send: (msg) => session.send(msg),
        peerInfo: () => session.peerInfo,
        enabled: $('clipboard').checked,
    });

    // Outbound is paste-driven: browsers expose no clipboard-change event, so a copy on
    // this side is not visible to us until the user pastes into the viewer.
    document.addEventListener('paste', (ev) => {
        if ($('viewonly').checked) return;
        if (clipboard?.sendFromPaste(ev)) ev.preventDefault();
    });

    // Inbound needs a transient user activation, so it is flushed on the next gesture
    // rather than when it arrives.
    for (const type of ['pointerdown', 'keydown', 'focus']) {
        canvas.addEventListener(type, () => { clipboard?.flush(); });
    }

    applyViewOnly();
}

function applyViewOnly() {
    const v = $('viewonly').checked;
    input?.setViewOnly(v);
    document.body.classList.toggle('viewonly', v);
}

async function connect() {
    lastError = null; workerStats = null;
    frames = 0; bytes = 0; keyFrames = 0; firstFrameAt = 0;
    mainVideoWorkMs = 0; mainDrawMs = 0; mainDrawSamples = 0;
    remote = { width: 0, height: 0 };
    startedAt = performance.now();
    mode = $('mode')?.value ?? 'worker';

    const { video, cursorCanvas } = freshCanvases();
    audio = new AudioStreamPlayer({ muted: $('mute').checked });
    $('connect').disabled = true;
    jank.start();
    setStatus('connecting…');

    if (mode === 'worker') await connectWorker(video, cursorCanvas);
    else await connectMain(video, cursorCanvas);
}

async function connectWorker(video, cursorCanvas) {
    const ws = new WorkerSession();
    session = ws;
    ws.onState = (s) => { if (s !== 'connected') setStatus(s); };
    ws.onPeerInfo = (info) => { populateDisplays(info); setupInput($('video')); };
    ws.onResize = (w, h) => { remote = { width: w, height: h }; };
    ws.onDisplaySwitch = (d) => { if (d.width) remote = { width: d.width, height: d.height }; };
    ws.onAudioFormat = async (f) => { await audio.setFormat(f); await audio.unlock(); };
    ws.onAudioFrame = (d) => audio.push(d);
    ws.onClipboard = (entries) => clipboard?.receive(entries);
    ws.onStats = (s) => {
        workerStats = s;
        if (s.surface?.width) remote = { width: s.surface.width, height: s.surface.height };
    };
    ws.onClose = (err) => fail(err.message);
    ws.connect({ videoCanvas: video, cursorCanvas, session: sessionOptions() });
}

async function connectMain(video, cursorCanvas) {
    surface = new VideoSurface(video);
    cursor = new CursorLayer(cursorCanvas);
    surface.onResize = (w, h) => { cursor.resize(w, h); remote = { width: w, height: h }; };

    const decodable = await probeDecodable();
    if (decodable.size === 0) { fail('WebCodecs unavailable — needs a secure context'); return; }
    codecs = new CodecCapabilities(decodable);

    decoder = new VideoStreamDecoder({
        onFrame: (f) => {
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
        onKeyFrameNeeded: () => doRefresh(),
    });

    const s = new RustDeskSession({ ...sessionOptions(), codecs });
    session = s;
    s.onState = (st) => { if (st !== 'connected') setStatus(st); };
    s.onPeerInfo = (info) => {
        populateDisplays(info);
        cursor.setDisplay(info.displays[info.current_display ?? 0] ?? {});
        setupInput($('video'));
    };
    s.onAudioFormat = async (f) => { await audio.setFormat(f); await audio.unlock(); };
    s.onAudioFrame = (b) => audio.push(b);
    s.onCursor = (c) => {
        if (c.type === 'shape') cursor.setShape(c);
        else if (c.type === 'id') cursor.useShape(c.id);
        else if (c.type === 'position') cursor.setPosition(c.x, c.y);
    };
    s.onDisplaySwitch = (d) => { cursor.setDisplay(d); decoder.reset(); };
    s.onClipboard = (entries) => clipboard?.receive(entries);
    s.onVideoFrame = (f) => {
        const t0 = performance.now();
        frames++;
        if (f.key) keyFrames++;
        for (const u of f.units) bytes += u.data.length;
        if (!firstFrameAt) firstFrameAt = t0;
        decoder.decode(f);
        mainVideoWorkMs += performance.now() - t0;
    };
    s.onClose = (err) => fail(err.message);

    try { await s.connect(); } catch (err) { fail(`${err.code ?? 'error'}: ${err.message}`); }
}

function fail(message) {
    lastError = message;
    setStatus(message, 'err');
    teardown();
}

function doRefresh() {
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
    activeDisplay = session.peerInfo?.displays?.[display] ?? activeDisplay;
    if (mode === 'worker') { session.switchDisplay(display); return; }
    if (session.state !== 'connected') return;
    session.send({ misc: { switch_display: { display } } });
    session.send({ misc: { capture_displays: { set: [display] } } });
    session.send({ misc: { refresh_video_display: display } });
    decoder?.reset();
    cursor?.setDisplay(activeDisplay);
}

function setQuality() {
    const pct = Number($('quality').value || 0);
    if (!session || session.state !== 'connected' || !pct) return;
    // Preset and custom are mutually exclusive: sending both drops the custom value.
    session.send({ misc: { option: customQuality(pct) } });
}

function paintStats() {
    if (!session) { statsEl.textContent = 'no session'; return; }
    if (mode === 'worker') session.requestStats?.();
    const a = audio?.stats() ?? {};
    const i = input?.stats() ?? {};
    const cb = clipboard?.stats() ?? {};
    const j = jank.stats();
    let lines;

    if (mode === 'worker') {
        const w = workerStats;
        lines = w ? [
            `worker   ${w.state}${w.encrypted ? ' · encrypted' : ''}`,
            `codec    ${w.decoder?.codec ?? '—'}  ${w.surface?.width || 0}×${w.surface?.height || 0}`,
            `frames   ${w.frames} · ${w.surface?.painted ?? 0} painted · ${w.decoder?.dropped ?? 0} dropped`,
            `data     ${(w.bytes / 1024).toFixed(0)} KiB  ${w.fps.toFixed(1)} fps  ttff ${Math.round(w.ttff)}ms`,
            `rtt      ${w.rtt ?? '—'} ms   buffered ${w.bufferedAmount ?? 0}`,
            `cursor   ${w.cursor?.cached ?? 0} cached · ${w.cursor?.missing ?? 0} missing`,
        ] : ['worker starting…'];
    } else {
        const d = decoder?.stats() ?? {}; const s = surface?.stats() ?? {};
        const secs = (performance.now() - startedAt) / 1000;
        lines = [
            `main     ${session.state}${session.encrypted ? ' · encrypted' : ''}`,
            `codec    ${d.codec ?? '—'}  ${s.width || 0}×${s.height || 0}`,
            `frames   ${frames} · ${s.painted ?? 0} painted · ${d.dropped ?? 0} dropped`,
            `data     ${(bytes / 1024).toFixed(0)} KiB  ${(frames / Math.max(secs, 0.001)).toFixed(1)} fps`,
            `mainwork ${(mainVideoWorkMs + mainDrawMs).toFixed(1)} ms total`,
        ];
    }

    statsEl.textContent = [
        ...lines,
        `audio    ${a.format ? `${a.format.sampleRate}Hz ×${a.format.channels}` : '—'} · ${a.packets ?? 0} pkt · ${a.errors ?? 0} err${a.muted ? ' · muted' : ''}`,
        `input    ${i.mouse ?? 0} mouse · ${i.keys ?? 0} keys${i.viewOnly ? ' · VIEW ONLY' : ''}${i.locked ? ' · kbd locked' : ''}`,
        `clip     ${cb.received ?? 0} in · ${cb.sent ?? 0} out · ${cb.dropped ?? 0} dropped${cb.pending ? ' · awaiting gesture' : ''}${cb.enabled === false ? ' · off' : ''}`,
        `jank     p95 ${j.p95 ?? '—'}ms · max ${j.max ?? '—'}ms`,
    ].join('\n');
}

function teardown() {
    jank.stop();
    input?.detach(); input = null;
    clipboard = null;
    if (mode === 'main') decoder?.close();
    session?.close();
    audio?.close();
    decoder = null; surface = null; cursor = null; audio = null; session = null;
    document.body.classList.remove('connected');
    $('connect').disabled = false;
}

$('connect').addEventListener('click', () => { connect(); });
$('disconnect').addEventListener('click', () => { setStatus('disconnected'); teardown(); });
$('display').addEventListener('change', switchDisplay);
$('quality').addEventListener('change', setQuality);
$('viewonly').addEventListener('change', applyViewOnly);
$('mute').addEventListener('change', (e) => audio?.setMuted(e.target.checked));
$('clipboard').addEventListener('change', (e) => clipboard?.setEnabled(e.target.checked));
$('cad').addEventListener('click', () => input?.sendCtrlAltDel());
$('refresh').addEventListener('click', doRefresh);
$('statsBtn').addEventListener('click', () => document.body.classList.toggle('showstats'));
$('fullscreen').addEventListener('click', async () => {
    if (document.fullscreenElement) { await document.exitFullscreen(); input?.unlockKeyboard(); return; }
    await $('stage').requestFullscreen();
    // Keyboard lock generally requires fullscreen, and is what lets Ctrl+W and Escape
    // reach the peer instead of the browser.
    await input?.lockKeyboard();
});

if (config) {
    // Host-provided config: hide the server fields, keep the password prompt.
    for (const id of ['host', 'peer', 'key', 'mode']) $(id)?.remove();
    if (config.password) { $('password').value = config.password; }
    $('hint').textContent = `Ready to connect to ${config.peerLabel ?? config.peerId}.`;
    if (config.autoConnect) connect();
} else {
    const params = new URLSearchParams(location.search);
    for (const k of ['host', 'peer', 'key', 'password', 'mode']) {
        if (params.has(k)) $(k).value = params.get(k);
    }
    if (params.get('auto') === '1') connect();
}

setInterval(paintStats, 500);
