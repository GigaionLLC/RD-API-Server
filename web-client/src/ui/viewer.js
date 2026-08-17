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
import { CodecCapabilities, probeDecodable, customQuality, presetQuality } from '../media/codec.js';
import { VideoStreamDecoder } from '../media/decoder.js';
import { VideoSurface } from '../render/surface.js';
import { CursorLayer } from '../render/cursor.js';
import { AudioStreamPlayer } from '../media/audio.js';
import { InputController } from '../input/controller.js';
import { ClipboardSync } from '../clipboard.js';
import { ChatChannel } from '../chat.js';
import { ReconnectPolicy, explain } from '../session/retry.js';
import {
    describeMessageBox, describeDenials, describeElevation, describeElevationResponse,
} from '../session/notices.js';
import { JankProbe } from './jank.js';
import { supportsTrackpadScroll } from '../protocol/version.js';

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
let chat = null;
let codecs = null;
const jank = new JankProbe();

let frames = 0; let bytes = 0; let keyFrames = 0;
let firstFrameAt = 0; let startedAt = 0;
let lastError = null; let workerStats = null;
let remote = { width: 0, height: 0 };
let activeDisplay = {};
let chosenDisplay = 0;
/** @type {string[]} Permissions the peer has explicitly denied. */
let deniedNames = [];
/** Identity from the login-time peer_info; later copies may omit it. */
let peerLabel = '';
/**
 * The most recent pointer mapping, for the stats overlay.
 *
 * Shown because "my clicks do not land where I am pointing" is otherwise impossible to
 * report usefully — it turns a feeling into two coordinate pairs and the ratio between
 * them, which is enough to see whether the video and the peer's display disagree.
 */
let lastPointer = null;
/**
 * Set while the operator is cancelling. A deliberate close surfaces as a transport error,
 * which the retry policy would otherwise treat as a transient drop and reconnect — turning
 * Cancel into a pause.
 */
let aborted = false;

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
    get chat() { return chat?.stats() ?? null; },
    sendChat(text) { return chat?.send(text) ?? false; },
    get jank() { return jank.stats(); },
    get error() { return lastError; },
    get denied() { return [...deniedNames]; },
    get notices() {
        return [...notices].map(([key, el]) => ({
            key,
            title: el.querySelector('.title')?.textContent ?? '',
            detail: el.querySelector('.detail')?.textContent ?? '',
        }));
    },
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
    // Drives the notice stack without a peer. The reconciliation this exercises — a
    // banner that must disappear when a permission is granted back — cannot be reached
    // from a unit test, and getting a real peer to deny a permission on cue is not a
    // repeatable test.
    notify: {
        messageBox: (box) => onMessageBox(box),
        permissions: (denied) => onDenials(denied),
        elevation: (state) => onElevation(state),
    },
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
            // Deployments that run hbbr on its own machine advertise it through hbbs, but
            // the fallback used when nothing is advertised needs it too.
            relayHost: config.relayHost ?? '',
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
            // Set by a host that always knows the server key, so an unverifiable peer is
            // a failure rather than a peer without a registered key.
            requireEncryption: config.requireEncryption === true,
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
    // Preserve the operator's chosen monitor across a topology change, rather than
    // yanking them back to the peer's idea of "current".
    const current = sel.querySelector(`option[value="${chosenDisplay}"]`)
        ? chosenDisplay
        : (info.current_display ?? 0);
    chosenDisplay = current;
    sel.value = String(current);
    activeDisplay = info.displays[current] ?? {};
    document.body.classList.add('connected');

    // peer_info is re-delivered on a topology change — docking a laptop, waking a second
    // monitor — and that copy carries the display list without the identity fields. Writing
    // it straight to the header turned a connected session into "peer@undefined · undefined"
    // some minutes in, which reads like a broken session rather than a monitor being
    // plugged in. Keep whatever was learned at login.
    if (info.hostname || info.username) {
        peerLabel = `${info.username || 'peer'}@${info.hostname ?? 'unknown'}`
            + (info.platform ? ` · ${info.platform}` : '');
    }
    if (peerLabel) setStatus(peerLabel, 'ok');
    showEncryptionState();
}

/**
 * Surfaces the encryption state. A downgrade means the password proof, keystrokes and
 * screen content cross the relay in plaintext, so it must be visible rather than buried
 * in a debug overlay the operator never opens.
 */
function showEncryptionState() {
    const warn = $('insecure');
    if (session?.encrypted === false) {
        warn.textContent = `NOT ENCRYPTED — ${session.downgradeReason ?? 'handshake failed'}`;
        warn.hidden = false;
    } else {
        warn.hidden = true;
    }
}

/** Removes the document-level paste listener; see setupInput. */
let detachPaste = null;

function setupInput(canvas) {
    // peer_info is re-delivered mid-session whenever the peer's monitor topology changes,
    // so this must be idempotent. Without the guard a monitor hot-plug attached a second
    // InputController to the same canvas and every keystroke and click was sent twice.
    if (input) return;

    input = new InputController({
        element: canvas,
        send: (b) => (mode === 'worker' ? session.sendRaw(b) : session.socket.send(session.stream.encrypt(b))),
        remoteSize: () => remote,
        display: () => activeDisplay,
        viewOnly: $('viewonly').checked,
        onMap: (local, remote) => { lastPointer = { local, remote }; },
        // Pixel-precise scrolling only where the peer is known to apply it; older hosts
        // ignore the message, which would read as a dead trackpad.
        trackpad: supportsTrackpadScroll(session.peerInfo ?? {}),
    });
    input.attach();

    clipboard = new ClipboardSync({
        send: (msg) => session.send(msg),
        peerInfo: () => session.peerInfo,
        enabled: $('clipboard').checked,
    });

    chat = new ChatChannel({
        send: (msg) => session.send(msg),
        onMessage: (entry) => appendChat(entry),
    });

    // Outbound is paste-driven: browsers expose no clipboard-change event, so a copy on
    // this side is not visible to us until the user pastes into the viewer.
    const onPaste = (ev) => {
        if ($('viewonly').checked) return;
        // Scoped to the stage. A document-level handler also swallowed pastes into the
        // chat box — sending the operator's clipboard to the peer and blocking the local
        // paste, which is unfortunate given paste is the only outbound trigger.
        if (!$('stage').contains(ev.target) || ev.target === $('chatInput')) return;
        if (clipboard?.sendFromPaste(ev)) ev.preventDefault();
    };
    document.addEventListener('paste', onPaste);
    detachPaste = () => document.removeEventListener('paste', onPaste);

    // Both the clipboard write and the AudioContext need a transient user activation, so
    // they are flushed on the next gesture rather than when they arrive. Audio in
    // particular has no other trigger, since the operator's Connect click happens in
    // the toolbar rather than on the canvas.
    for (const type of ['pointerdown', 'keydown', 'focus']) {
        canvas.addEventListener(type, () => {
            clipboard?.flush();
            audio?.unlock().catch(() => {});
        });
    }

    applyViewOnly();
}

/* -------------------------------------------------------------------------- */
/* Notices                                                                    */
/* -------------------------------------------------------------------------- */

/**
 * A keyed stack, not a log. Peers repeat the same MessageBox for as long as they are
 * waiting for a decision, and permission state is a level rather than an edge — appending
 * would bury the canvas under identical cards within seconds.
 * @type {Map<string, HTMLElement>}
 */
const notices = new Map();

/** @param {{key: string, severity: string, title: string, detail?: string, link?: string, action?: string}} n */
function showNotice(n) {
    const card = document.createElement('div');
    card.className = `notice ${n.severity}`;

    const body = document.createElement('div');
    body.className = 'body';

    const title = document.createElement('div');
    title.className = 'title';
    title.textContent = n.title;
    body.appendChild(title);

    if (n.detail) {
        const detail = document.createElement('div');
        detail.className = 'detail';
        // textContent throughout: every one of these strings arrives from the peer.
        detail.textContent = n.detail;
        body.appendChild(detail);
    }
    if (n.link) {
        const link = document.createElement('div');
        link.className = 'link';
        // Shown, not linked. A peer-supplied URL in trusted chrome is a phishing surface.
        link.textContent = n.link;
        body.appendChild(link);
    }
    if (n.action === 'elevate') body.appendChild(elevationForm());

    const close = document.createElement('button');
    close.className = 'dismiss';
    close.type = 'button';
    close.title = 'Dismiss';
    close.textContent = '×';
    close.addEventListener('click', () => dismissNotice(n.key));

    card.append(body, close);
    notices.get(n.key)?.remove();
    notices.set(n.key, card);
    $('notices').appendChild(card);
}

/** @param {string} key */
function dismissNotice(key) {
    notices.get(key)?.remove();
    notices.delete(key);
}

function clearNotices() {
    for (const el of notices.values()) el.remove();
    notices.clear();
}

/**
 * Two ways out of an elevation block, because they fail in different situations: a consent
 * prompt needs someone at the machine, and credentials work when nobody is there.
 */
function elevationForm() {
    const wrap = document.createElement('div');

    const ask = document.createElement('button');
    ask.type = 'button';
    ask.textContent = 'Ask at the machine';
    ask.title = 'Raises a consent prompt the remote user must accept';
    ask.addEventListener('click', () => session?.requestElevation?.());

    const actions = document.createElement('div');
    actions.className = 'actions';
    actions.appendChild(ask);
    wrap.appendChild(actions);

    const form = document.createElement('form');
    const user = document.createElement('input');
    user.placeholder = 'administrator';
    user.autocomplete = 'off';
    const pass = document.createElement('input');
    pass.type = 'password';
    pass.placeholder = 'password';
    pass.autocomplete = 'off';
    const go = document.createElement('button');
    go.type = 'submit';
    go.className = 'primary';
    go.textContent = 'Elevate';
    form.addEventListener('submit', (ev) => {
        ev.preventDefault();
        if (!user.value) return;
        session?.requestElevation?.({ username: user.value, password: pass.value });
        // Held only as long as it takes to encrypt into the session stream.
        pass.value = '';
    });
    form.append(user, pass, go);
    wrap.appendChild(form);
    return wrap;
}

/** @param {object} box */
function onMessageBox(box) {
    const n = describeMessageBox(box);
    showNotice(n);
    // A peer asking for the password again wants the field, not a card to read.
    if (n.prompt === 'password') $('password')?.focus();
}

/** @param {string[]} denied */
function onDenials(denied) {
    const wanted = describeDenials(denied);
    const keep = new Set(wanted.map((n) => n.key));
    // Permissions are a live level: a permission switched back on at the remote end must
    // take its banner away, or the operator is told input is dead while it works.
    for (const key of [...notices.keys()]) {
        if (key.startsWith('perm:') && !keep.has(key)) dismissNotice(key);
    }
    for (const n of wanted) if (!notices.has(n.key)) showNotice(n);
    deniedNames = denied;
    applyViewOnly();
}

/** @param {{uac: boolean, elevated: boolean, portable: boolean, pending: boolean, response: string|null}} state */
function onElevation(state) {
    const n = describeElevation(state);
    if (n) {
        if (!notices.has(n.key)) showNotice(n);
    } else {
        dismissNotice('elev:uac');
        dismissNotice('elev:foreground');
    }
    if (state.response !== null && state.response !== undefined) {
        const r = describeElevationResponse(state.response);
        showNotice(r);
        // Success clears the block that prompted it; a failure leaves it up, because the
        // window is still unreachable.
        if (r.severity === 'info') { dismissNotice('elev:uac'); dismissNotice('elev:foreground'); }
    }
}

/** @param {{from: 'peer'|'me', text: string, at: number}} entry */
function appendChat(entry) {
    const log = $('chatLog');
    log.querySelector('.empty')?.remove();

    const row = document.createElement('div');
    row.className = `msg ${entry.from === 'me' ? 'me' : 'peer'}`;
    const who = document.createElement('div');
    who.className = 'from';
    who.textContent = `${entry.from === 'me' ? 'you' : 'remote'} · ${new Date(entry.at).toLocaleTimeString()}`;
    const body = document.createElement('div');
    // textContent, never innerHTML: this string comes straight off the wire.
    body.textContent = entry.text;
    row.append(who, body);
    log.appendChild(row);
    log.scrollTop = log.scrollHeight;

    // The protocol has no notification of its own, so an inbound message while the panel
    // is closed would otherwise go unnoticed entirely.
    if (entry.from === 'peer' && !document.body.classList.contains('showchat')) {
        $('chatBtn').classList.add('unread');
    }
}

function applyViewOnly() {
    // A peer that has switched off keyboard control is view-only whatever the checkbox
    // says; unchecking it must not resume sending input the peer is discarding.
    const v = $('viewonly').checked || deniedNames.includes('Keyboard');
    input?.setViewOnly(v);
    document.body.classList.toggle('viewonly', v);
}

/** @param {boolean} [isRetry] Suppresses the counter reset, so backoff keeps growing. */
async function connect(isRetry = false) {
    lastError = null; workerStats = null;
    aborted = false;
    peerLabel = '';
    document.body.classList.add('connecting');
    document.body.classList.remove('remotecursor');
    $('cancel').hidden = false;
    // A fresh attempt by the operator starts from a short delay again; a scheduled retry
    // must not, or backoff never grows.
    if (!isRetry) cancelRetry();
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
    ws.onPeerInfo = (info) => {
        // A session that survived long enough to log in resets the backoff, so a drop an
        // hour from now waits a second rather than thirty.
        retry.reset();
        populateDisplays(info);
        setupInput($('video'));
    };
    ws.onResize = (w, h) => { remote = { width: w, height: h }; };
    ws.onDisplaySwitch = (d) => { if (d.width) remote = { width: d.width, height: d.height }; };
    ws.onAudioFormat = async (f) => { await audio.setFormat(f); await audio.unlock(); };
    ws.onAudioFrame = (d) => audio.push(d);
    ws.onClipboard = (entries) => clipboard?.receive(entries);
    ws.onChat = (text) => chat?.receive(text);
    ws.onCursorReady = () => document.body.classList.add('remotecursor');
    ws.onMessageBox = onMessageBox;
    ws.onPermissions = onDenials;
    ws.onElevation = onElevation;
    ws.onStats = (s) => {
        workerStats = s;
        if (s.surface?.width) remote = { width: s.surface.width, height: s.surface.height };
    };
    ws.onClose = (err) => fail(err);
    ws.connect({ videoCanvas: video, cursorCanvas, session: sessionOptions() });
}

async function connectMain(video, cursorCanvas) {
    surface = new VideoSurface(video);
    cursor = new CursorLayer(cursorCanvas);
    surface.onResize = (w, h) => { cursor.resize(w, h); remote = { width: w, height: h }; };

    const decodable = await probeDecodable();
    if (decodable.size === 0) {
        // Not retryable: a browser without WebCodecs will not grow it on the next attempt.
        fail({ code: 'unsupported', message: 'WebCodecs is unavailable — this page needs a secure context (HTTPS or localhost)' });
        return;
    }
    codecs = new CodecCapabilities(decodable);

    decoder = new VideoStreamDecoder({
        onFrame: (f) => {
            const t0 = performance.now();
            surface.draw(f);
            mainDrawMs += performance.now() - t0;
            mainDrawSamples++;
            // Pairs with markFailure below: a streak is only meaningful if success clears it.
            if (decoder?.codec) codecs.markSuccess(decoder.codec);
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
        retry.reset();
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
        // Only now is it safe to hide the local pointer: there is a remote one to replace it.
        if (cursor.current) document.body.classList.add('remotecursor');
    };
    s.onDisplaySwitch = (d) => { cursor.setDisplay(d); decoder.reset(); };
    s.onClipboard = (entries) => clipboard?.receive(entries);
    s.onChat = (text) => chat?.receive(text);
    s.onMessageBox = onMessageBox;
    s.onPermissions = (set) => onDenials(set.denied());
    s.onElevation = onElevation;
    s.onVideoFrame = (f) => {
        const t0 = performance.now();
        frames++;
        if (f.key) keyFrames++;
        for (const u of f.units) bytes += u.data.length;
        if (!firstFrameAt) firstFrameAt = t0;
        decoder.decode(f);
        mainVideoWorkMs += performance.now() - t0;
    };
    s.onClose = (err) => fail(err);

    try { await s.connect(); } catch (err) { fail(err); }
}

const retry = new ReconnectPolicy();
let retryTimer = null;

/**
 * Ends the session, and reconnects if the failure was transient.
 *
 * Every session is relayed, so drops are ordinary rather than exceptional. What is NOT
 * retried matters more: a wrong password would walk the peer's failed-attempt counter
 * toward a lockout, and a refused encryption downgrade would invite whatever stripped it
 * to keep trying. The taxonomy lives in session/retry.js.
 *
 * @param {{code?: string, message?: string}} err
 */
function fail(err) {
    // A cancel closes the socket, which arrives here as a transport failure. Retrying it
    // would reopen the session the operator just stopped.
    if (aborted) return;

    const info = explain(err);
    lastError = `${info.title}: ${info.detail}`;
    teardown();

    if ($('autoreconnect').checked && retry.shouldRetry(err)) {
        const delay = retry.nextDelay();
        const seconds = Math.max(1, Math.round(delay / 1000));
        setStatus(`${info.title} — reconnecting in ${seconds}s (${retry.attempt}/${retry.maxAttempts})`, 'warn');
        retryTimer = setTimeout(() => { retryTimer = null; connect(true); }, delay);
        return;
    }

    setStatus(info.retryable && retry.exhausted
        ? `${info.title} — gave up after ${retry.maxAttempts} attempts`
        : `${info.title} — ${info.detail}`, 'err');
}

function cancelRetry() {
    if (retryTimer) { clearTimeout(retryTimer); retryTimer = null; }
    retry.reset();
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
    chosenDisplay = display;
    activeDisplay = session.peerInfo?.displays?.[display] ?? activeDisplay;
    if (mode === 'worker') { session.switchDisplay(display); return; }
    if (session.state !== 'connected') return;
    session.send({ misc: { switch_display: { display } } });
    session.send({ misc: { capture_displays: { set: [display] } } });
    session.send({ misc: { refresh_video_display: display } });
    decoder?.reset();
    cursor?.setDisplay(activeDisplay);
}

/**
 * @param {{preset?: string, percent?: number}} choice A protocol preset, or a custom
 *   percentage for callers that want a value between them.
 */
function setQuality(choice = { preset: $('quality').value }) {
    if (!session || session.state !== 'connected') return;
    // Preset and custom are mutually exclusive: sending both drops the custom value.
    const option = choice.percent ? customQuality(choice.percent) : presetQuality(choice.preset);
    session.send({ misc: { option } });
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
        ...(lastPointer ? [
            `pointer  video ${lastPointer.local.x},${lastPointer.local.y}`
            + ` -> peer ${lastPointer.remote.x},${lastPointer.remote.y}`,
            `display  ${activeDisplay.width ?? '?'}x${activeDisplay.height ?? '?'}`
            + ` at ${activeDisplay.x ?? 0},${activeDisplay.y ?? 0}`
            + ` · video ${remote.width}x${remote.height}`
            + ` · scale ${activeDisplay.scale ?? 1}`,
        ] : []),
        ...(deniedNames.length ? [`denied   ${deniedNames.join(', ')}`] : []),
    ].join('\n');
}

function teardown() {
    jank.stop();
    input?.detach(); input = null;
    detachPaste?.(); detachPaste = null;
    clipboard = null;
    chat = null;
    $('chatLog').innerHTML = '<div class="empty">No messages yet.</div>';
    clearNotices();
    deniedNames = [];
    document.body.classList.remove('showchat');
    $('chatBtn').classList.remove('unread');
    if (mode === 'main') decoder?.close();
    session?.close();
    audio?.close();
    decoder = null; surface = null; cursor = null; audio = null; session = null;
    document.body.classList.remove('connected', 'connecting', 'remotecursor');
    $('cancel').hidden = true;
    $('connect').disabled = false;
}

$('connect').addEventListener('click', () => { connect(); });
$('cancel').addEventListener('click', () => {
    // Reachable during rendezvous, relay, handshake and login — including while the peer
    // is waiting for someone at the far end to accept, which can be indefinite.
    aborted = true;
    cancelRetry();
    teardown();
    setStatus('Cancelled');
});
$('disconnect').addEventListener('click', () => {
    // An explicit disconnect must cancel a pending retry, or the session reappears.
    cancelRetry();
    setStatus('disconnected');
    teardown();
});
$('display').addEventListener('change', switchDisplay);
$('quality').addEventListener('change', () => setQuality());
$('viewonly').addEventListener('change', applyViewOnly);
$('remotecursor').addEventListener('change', (e) => {
    const on = e.target.checked;
    document.body.classList.toggle('noremotecursor', !on);
    if (mode === 'worker') session?.showRemoteCursor?.(on);
    else if (cursor) { cursor.visible = on; cursor.render(); }
});
$('mute').addEventListener('change', (e) => audio?.setMuted(e.target.checked));
$('clipboard').addEventListener('change', (e) => clipboard?.setEnabled(e.target.checked));
$('chatBtn').addEventListener('click', () => {
    const open = document.body.classList.toggle('showchat');
    $('chatBtn').classList.remove('unread');
    if (open) { chat?.markRead(); $('chatInput').focus(); }
});
$('chatForm').addEventListener('submit', (e) => {
    e.preventDefault();
    const field = /** @type {HTMLInputElement} */ ($('chatInput'));
    if (chat?.send(field.value)) field.value = '';
});
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

// Nothing here ever connects on its own, and there is deliberately no option to.
//
// A remote desktop session is visible on the other machine and interrupts whoever is
// sitting at it. Starting one has to be a decision someone made, not a side effect of
// opening a page — a mis-clicked link, a restored browser tab or a refresh must never
// reach another person's screen. Anything that would auto-connect is therefore absent
// rather than defaulted off, so it cannot be switched on by configuration or by a URL.
if (config) {
    // Host-provided config: hide the server fields, keep the password prompt.
    for (const id of ['host', 'peer', 'key', 'mode']) $(id)?.remove();
    if (config.password) { $('password').value = config.password; }
    $('hint').textContent = `Ready to connect to ${config.peerLabel ?? config.peerId}. `
        + 'Press Connect when you are.';
} else {
    // Server details may be prefilled from the query string for development, but never
    // the password: a peer secret in a URL lands in browser history, the Referer header
    // and every access log between here and the server.
    const params = new URLSearchParams(location.search);
    for (const k of ['host', 'peer', 'key', 'mode']) {
        if (params.has(k)) $(k).value = params.get(k);
    }
}

setInterval(paintStats, 500);
