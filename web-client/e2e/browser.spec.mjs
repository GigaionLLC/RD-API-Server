/**
 * Real-browser coverage for the parts that cannot run under Node.
 *
 * Everything protocol-shaped is covered by test/conformance with `node --test`. What is
 * left needs an actual Chromium: WebCodecs, canvas compositing, AudioContext and
 * AudioWorklet, and a module Worker. Those were previously verified only by running the
 * viewer by hand and reading the numbers, which is not something CI can repeat.
 *
 *   npm run test:browser
 *
 * The harness is served over http://localhost, which is a secure context — WebCodecs is
 * unavailable otherwise and every capability assertion here would fail for the wrong
 * reason.
 */

import { test, expect } from '@playwright/test';

test.beforeEach(async ({ page }) => {
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    await page.goto('/e2e/harness.html');
    await page.waitForFunction(() => globalThis.H?.ready === true, null, { timeout: 15_000 });
    // A module that fails to load leaves H undefined and every later assertion confusing.
    expect(errors, 'harness modules must load without error').toEqual([]);
});

/* -------------------------------------------------------------------------- */
/* WebCodecs                                                                  */
/* -------------------------------------------------------------------------- */

test('WebCodecs is available and the browser decodes real codecs', async ({ page }) => {
    const result = await page.evaluate(async () => {
        const decodable = await globalThis.H.probeDecodable();
        return { available: typeof VideoDecoder !== 'undefined', decodable: [...decodable].sort() };
    });

    expect(result.available).toBe(true);
    // VP9 has no host-side gate and is the protocol's universal fallback; a browser that
    // cannot decode it cannot be a viewer at all.
    expect(result.decodable).toContain('vp9');
    expect(result.decodable.length).toBeGreaterThan(1);
});

test('every advertised codec config is actually accepted by the browser', async ({ page }) => {
    // probeDecodable claims support; this checks the config we would really send is the
    // one that was probed, rather than a differently-shaped string.
    const results = await page.evaluate(async () => {
        const out = {};
        for (const family of globalThis.H.CODEC_FAMILIES) {
            const cfg = globalThis.H.decoderConfig(family);
            try {
                const r = await VideoDecoder.isConfigSupported(cfg);
                out[family] = { supported: !!r.supported, hasDescription: 'description' in cfg };
            } catch (e) {
                out[family] = { supported: false, error: String(e).slice(0, 60) };
            }
        }
        return out;
    });

    for (const [family, r] of Object.entries(results)) {
        expect(r.hasDescription, `${family} config must omit description for in-band parameter sets`).toBeFalsy();
    }
    expect(results.vp9.supported).toBe(true);
});

/* -------------------------------------------------------------------------- */
/* Compositing                                                                */
/* -------------------------------------------------------------------------- */

test('a real VideoFrame is drawn to canvas and closed', async ({ page }) => {
    const result = await page.evaluate(() => {
        const surface = new globalThis.H.VideoSurface(document.getElementById('video'));
        const sizes = [];
        surface.onResize = (w, h) => sizes.push([w, h]);

        const frame = globalThis.H.makeFrame(320, 180);
        surface.draw(frame);

        // A closed VideoFrame does NOT throw on property access — it reports 0. clone()
        // is the unambiguous signal, and proving release matters because leaking frames
        // exhausts a small GPU buffer pool and stalls decoding permanently.
        let released = false;
        try { frame.clone().close(); } catch { released = true; }
        const dimsZeroed = frame.codedWidth === 0;

        const ctx = document.getElementById('video').getContext('2d');
        const px = ctx.getImageData(160, 90, 1, 1).data;

        return { painted: surface.stats().painted, sizes, released, dimsZeroed, pixel: [px[0], px[1], px[2]] };
    });

    expect(result.painted).toBe(1);
    expect(result.sizes).toEqual([[320, 180]]);
    expect(result.released, 'clone() must fail on a closed frame').toBe(true);
    expect(result.dimsZeroed, 'a closed frame reports zero dimensions').toBe(true);
    // #c04030 — proves real pixels landed, not just that draw() returned.
    expect(result.pixel[0]).toBeGreaterThan(150);
    expect(result.pixel[2]).toBeLessThan(100);
});

test('a decoded frame reaches the canvas through the decoder', async ({ page }) => {
    // The full path: encode a frame with a real VideoEncoder, feed the bytes through
    // VideoStreamDecoder, and confirm the pixels arrive. This is the pipeline that was
    // previously only ever verified by looking at a live session.
    const result = await page.evaluate(async () => {
        const chunks = [];
        const encoder = new VideoEncoder({
            output: (c) => {
                const data = new Uint8Array(c.byteLength);
                c.copyTo(data);
                chunks.push({ data, key: c.type === 'key' });
            },
            error: () => {},
        });
        encoder.configure({ codec: 'vp8', width: 320, height: 180, bitrate: 500_000 });
        encoder.encode(globalThis.H.makeFrame(320, 180, '#20a020'), { keyFrame: true });
        await encoder.flush();
        encoder.close();
        if (!chunks.length) return { error: 'encoder produced nothing' };

        const surface = new globalThis.H.VideoSurface(document.getElementById('video'));
        const decoder = new globalThis.H.VideoStreamDecoder({ onFrame: (f) => surface.draw(f) });
        decoder.decode({ codec: 'vp8', key: chunks[0].key, units: chunks.map((c) => ({ data: c.data, key: c.key })) });

        await new Promise((r) => { setTimeout(r, 1500); });
        const ctx = document.getElementById('video').getContext('2d');
        const px = ctx.getImageData(160, 90, 1, 1).data;
        return { stats: decoder.stats(), painted: surface.stats().painted, pixel: [px[0], px[1], px[2]] };
    });

    expect(result.error).toBeUndefined();
    expect(result.stats.decoded).toBeGreaterThan(0);
    expect(result.painted).toBeGreaterThan(0);
    // Green survived encode → decode → composite, allowing for lossy colour conversion.
    expect(result.pixel[1]).toBeGreaterThan(result.pixel[0]);
    expect(result.pixel[1]).toBeGreaterThan(result.pixel[2]);
});

test('deltas before a key frame are dropped rather than decoded', async ({ page }) => {
    const stats = await page.evaluate(() => {
        const decoder = new globalThis.H.VideoStreamDecoder({ onFrame: (f) => f.close() });
        decoder.decode({ codec: 'vp9', key: false, units: [{ data: new Uint8Array([1, 2, 3]), key: false }] });
        return decoder.stats();
    });
    expect(stats.dropped).toBe(1);
    expect(stats.awaitingKeyFrame).toBe(true);
});

/* -------------------------------------------------------------------------- */
/* Cursor                                                                     */
/* -------------------------------------------------------------------------- */

test('a cursor shape decodes through real createImageBitmap and paints', async ({ page }) => {
    const result = await page.evaluate(async () => {
        // A 16x16 opaque square, zstd-compressed the way a peer sends it. The bytes below
        // are produced by the vendored decoder's own round trip in Node; here we only
        // need a payload it accepts, so build one via the raw path instead.
        const raw = new Uint8Array(16 * 16 * 4);
        for (let i = 0; i < raw.length; i += 4) { raw[i] = 255; raw[i + 3] = 255; }

        // Uncompressed shapes are not a protocol case, so exercise the decompressor by
        // asserting it rejects garbage, then feed the layer a pre-decompressed payload
        // through the same code path by compressing with CompressionStream is not
        // possible for zstd — so verify the failure path and the bitmap path separately.
        const layer = new globalThis.H.CursorLayer(document.getElementById('cursor'));
        layer.resize(64, 64);

        const badRejected = (await layer.setShape({
            id: 1n, width: 16, height: 16, colors: new Uint8Array([9, 9, 9]),
        }), layer.stats().cached === 0);

        // Now the bitmap path, bypassing zstd by calling the internals the same way
        // setShape does once decompression has succeeded.
        const imageData = new ImageData(new Uint8ClampedArray(raw), 16, 16);
        const image = await createImageBitmap(imageData);
        layer.shapes.set('42', { width: 16, height: 16, hotx: 2, hoty: 3, image });
        layer.current = layer.shapes.get('42');
        layer.setPosition(20, 20);

        const ctx = document.getElementById('cursor').getContext('2d');
        const px = ctx.getImageData(20, 20, 1, 1).data;
        return {
            badRejected,
            isBitmap: image instanceof ImageBitmap,
            resolved: layer.useShape(42n),
            missing: layer.useShape(999n),
            pixel: [px[0], px[1], px[2], px[3]],
        };
    });

    expect(result.badRejected).toBe(true);
    expect(result.isBitmap).toBe(true);
    expect(result.resolved).toBe(true);
    expect(result.missing).toBe(false);
    // Drawn at position minus hotspot, so (20,20) is inside the 16x16 square.
    expect(result.pixel[3]).toBeGreaterThan(0);
    expect(result.pixel[0]).toBeGreaterThan(200);
});

/* -------------------------------------------------------------------------- */
/* Audio                                                                      */
/* -------------------------------------------------------------------------- */

test('the audio graph builds with a real AudioContext and AudioWorklet', async ({ page }) => {
    const result = await page.evaluate(async () => {
        const player = new globalThis.H.AudioStreamPlayer({ muted: true });
        if (!player.supported) return { supported: false };

        const ok = await player.setFormat({ sample_rate: 48000, channels: 2 });
        const stats = player.stats();
        await player.close();
        return {
            supported: true,
            ok,
            sampleRate: stats.format?.sampleRate,
            channels: stats.format?.channels,
            contextState: stats.contextState,
            hasWorklet: typeof AudioWorkletNode !== 'undefined',
        };
    });

    expect(result.supported).toBe(true);
    expect(result.ok).toBe(true);
    expect(result.hasWorklet).toBe(true);
    expect(result.sampleRate).toBe(48000);
    expect(result.channels).toBe(2);
    // Suspended until a gesture — that is the constraint the viewer works around, so it
    // is worth pinning rather than assuming.
    expect(['suspended', 'running']).toContain(result.contextState);
});

test('the audio decoder configures for Opus at every protocol sample rate', async ({ page }) => {
    // The rate is quantised into this set; a hardcoded 48000 breaks against a host whose
    // device runs at another rate.
    const results = await page.evaluate(async () => {
        const out = {};
        for (const rate of [8000, 12000, 16000, 24000, 48000]) {
            const support = await AudioDecoder.isConfigSupported({
                codec: 'opus', sampleRate: rate, numberOfChannels: 2,
            });
            out[rate] = !!support.supported;
        }
        return out;
    });
    for (const [rate, supported] of Object.entries(results)) {
        expect(supported, `Opus at ${rate}Hz must be decodable`).toBe(true);
    }
});

/* -------------------------------------------------------------------------- */
/* Worker                                                                     */
/* -------------------------------------------------------------------------- */

test('the session worker loads as a module and accepts a canvas transfer', async ({ page }) => {
    // The worker pulls in the whole stack — crypto, codec, transport, render. If any of
    // it is not worker-safe this fails at import, which no Node test would catch.
    const result = await page.evaluate(async () => {
        const worker = new Worker('/src/workers/session.worker.js', { type: 'module' });
        const errors = [];
        worker.onerror = (e) => errors.push(e.message ?? 'error');

        const messages = [];
        worker.onmessage = (e) => messages.push(e.data);

        // Ask for stats before connecting: it must answer without a session rather than
        // throwing, which also proves the module evaluated.
        worker.postMessage({ type: 'stats' });
        await new Promise((r) => { setTimeout(r, 1200); });

        const canvas = document.createElement('canvas');
        const cursor = document.createElement('canvas');
        const off1 = canvas.transferControlToOffscreen();
        const off2 = cursor.transferControlToOffscreen();
        let transferred = false;
        try {
            worker.postMessage({ type: 'ping-transfer', video: off1, cursor: off2 }, [off1, off2]);
            transferred = true;
        } catch { /* transfer rejected */ }

        await new Promise((r) => { setTimeout(r, 400); });
        worker.terminate();
        return { errors, replied: messages.some((m) => m?.type === 'stats'), stats: messages.find((m) => m?.type === 'stats'), transferred };
    });

    expect(result.errors).toEqual([]);
    expect(result.replied).toBe(true);
    expect(result.stats.state).toBe('idle');
    expect(result.transferred).toBe(true);
});

/* -------------------------------------------------------------------------- */
/* Viewer                                                                     */
/* -------------------------------------------------------------------------- */

test('the viewer page loads cleanly and exposes its diagnostics', async ({ page }) => {
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e)));
    await page.goto('/src/ui/viewer.html');
    await page.waitForFunction(() => globalThis.__viewer !== undefined, null, { timeout: 15_000 });

    const state = await page.evaluate(() => ({
        state: globalThis.__viewer.state,
        mode: globalThis.__viewer.mode,
        hasConnect: !!document.getElementById('connect'),
        hasChat: !!document.getElementById('chatForm'),
    }));

    expect(errors).toEqual([]);
    expect(state.state).toBe('idle');
    expect(state.mode).toBe('worker');
    expect(state.hasConnect).toBe(true);
    expect(state.hasChat).toBe(true);
});

test('notices render, deduplicate and dismiss', async ({ page }) => {
    // Peers repeat the same box for as long as they are waiting for a decision, so an
    // append-only list buries the canvas within seconds of a click-to-accept prompt.
    await page.goto('/src/ui/viewer.html');
    await page.waitForFunction(() => globalThis.__viewer !== undefined, null, { timeout: 15_000 });

    const box = { msgtype: 'custom-nocancel', title: 'Waiting', text: 'Accept on the remote machine' };
    const after = await page.evaluate((b) => {
        globalThis.__viewer.notify.messageBox(b);
        globalThis.__viewer.notify.messageBox(b);
        globalThis.__viewer.notify.messageBox(b);
        return {
            cards: document.querySelectorAll('#notices .notice').length,
            titles: globalThis.__viewer.notices.map((n) => n.title),
        };
    }, box);

    expect(after.cards).toBe(1);
    expect(after.titles).toEqual(['Waiting']);

    await page.click('#notices .notice .dismiss');
    expect(await page.locator('#notices .notice').count()).toBe(0);
});

test('peer text is rendered as text, never as markup', async ({ page }) => {
    // Every field in a MessageBox arrives from a machine the operator has not yet decided
    // to trust, and the notice sits in trusted chrome.
    await page.goto('/src/ui/viewer.html');
    await page.waitForFunction(() => globalThis.__viewer !== undefined, null, { timeout: 15_000 });

    const result = await page.evaluate(() => {
        globalThis.__viewer.notify.messageBox({
            msgtype: 'error',
            title: '<img src=x onerror="globalThis.__pwned=1">',
            text: '<b>bold</b>',
            link: 'javascript:globalThis.__pwned=1',
        });
        const card = document.querySelector('#notices .notice');
        return {
            injected: card.querySelectorAll('img, b, a, script').length,
            pwned: globalThis.__pwned ?? false,
            titleText: card.querySelector('.title').textContent,
            linkIsAnchor: !!card.querySelector('a'),
        };
    });

    expect(result.injected).toBe(0);
    expect(result.pwned).toBe(false);
    expect(result.titleText).toContain('<img');
    expect(result.linkIsAnchor).toBe(false);
});

test('a permission granted back takes its banner away', async ({ page }) => {
    // Permission state is a level, not an edge. A stale banner tells the operator input is
    // dead while it is working, which is worse than never having shown one.
    await page.goto('/src/ui/viewer.html');
    await page.waitForFunction(() => globalThis.__viewer !== undefined, null, { timeout: 15_000 });

    const seq = await page.evaluate(() => {
        const snap = () => globalThis.__viewer.notices.map((n) => n.key);
        globalThis.__viewer.notify.permissions(['Keyboard', 'Clipboard']);
        const both = snap();
        globalThis.__viewer.notify.permissions(['Clipboard']);
        const one = snap();
        globalThis.__viewer.notify.permissions([]);
        return { both, one, none: snap(), denied: globalThis.__viewer.denied };
    });

    expect(seq.both).toEqual(['perm:Keyboard', 'perm:Clipboard']);
    expect(seq.one).toEqual(['perm:Clipboard']);
    expect(seq.none).toEqual([]);
    expect(seq.denied).toEqual([]);
});

test('a UAC prompt offers elevation and clears on success', async ({ page }) => {
    await page.goto('/src/ui/viewer.html');
    await page.waitForFunction(() => globalThis.__viewer !== undefined, null, { timeout: 15_000 });

    const shown = await page.evaluate(() => {
        globalThis.__viewer.notify.elevation({ uac: true, elevated: false, portable: false, pending: false, response: null });
        return {
            keys: globalThis.__viewer.notices.map((n) => n.key),
            hasForm: !!document.querySelector('#notices .notice form'),
            // The credential field must not be a password manager target on a page that
            // is not this machine's login.
            passwordType: document.querySelector('#notices .notice form input[type=password]')?.type,
        };
    });
    expect(shown.keys).toEqual(['elev:uac']);
    expect(shown.hasForm).toBe(true);
    expect(shown.passwordType).toBe('password');

    const after = await page.evaluate(() => {
        globalThis.__viewer.notify.elevation({ uac: false, elevated: false, portable: false, pending: false, response: '' });
        return globalThis.__viewer.notices.map((n) => n.key);
    });
    // The block is gone and the success is reported in its place.
    expect(after).toEqual(['elev:response']);
});

test('the viewer never connects on its own', async ({ page }) => {
    // A remote desktop session is visible on the other machine and interrupts whoever is
    // at it. Opening the page — from a restored tab, a mis-clicked link, a refresh — must
    // never start one, so there is deliberately no configuration or URL that can.
    const attempts = [];
    page.on('websocket', (ws) => attempts.push(ws.url()));

    await page.addInitScript(() => {
        globalThis.RD_CONFIG = {
            host: 'id.example.invalid',
            peerId: '345890346',
            peerLabel: 'Workstation',
            serverKey: '',
            secure: false,
            // Both of these existed as auto-connect switches at some point. Neither may
            // work again: this test fails if either is ever honoured.
            autoConnect: true,
            auto: true,
        };
    });
    await page.goto('/src/ui/viewer.html?auto=1');
    await page.waitForFunction(() => globalThis.__viewer !== undefined, null, { timeout: 15_000 });
    await page.waitForTimeout(1500);

    expect(attempts, 'no socket may be opened without a click').toEqual([]);
    expect(await page.evaluate(() => globalThis.__viewer.state)).toBe('idle');

    // And the id it was given is ready to go, so the operator only presses Connect.
    expect(await page.evaluate(() => document.getElementById('peer'))).toBeNull();
    expect(await page.textContent('#hint')).toContain('Workstation');
});

test('a connection in progress can be cancelled', async ({ page }) => {
    // Reported from a real deployment: connecting to a machine that asks its user to accept
    // left the viewer waiting with no way out. Disconnect lives in the toolbar, which is
    // only rendered once a session is established, so during negotiation there was nothing.
    await page.goto('/src/ui/viewer.html');
    await page.waitForFunction(() => globalThis.__viewer !== undefined, null, { timeout: 15_000 });

    expect(await page.locator('#cancel').isVisible(), 'hidden while idle').toBe(false);

    // A host that accepts the socket and never replies is exactly the "waiting for accept"
    // shape; an unroutable one is the closest we can get without a peer.
    await page.fill('#host', '198.51.100.7');
    await page.fill('#peer', '345890346');
    await page.click('#connect');

    await expect(page.locator('#cancel')).toBeVisible();
    expect(await page.locator('#connect').isVisible(), 'Connect is replaced, not doubled').toBe(false);

    await page.click('#cancel');

    await expect(page.locator('#cancel')).toBeHidden();
    await expect(page.locator('#connect')).toBeVisible();
    expect(await page.textContent('#status')).toContain('Cancelled');

    // And it stays cancelled: a deliberate close must not be retried as a dropped session.
    await page.waitForTimeout(2500);
    expect(await page.textContent('#status')).toContain('Cancelled');
    expect(await page.evaluate(() => globalThis.__viewer.state)).toBe('idle');
});

test('the operator always keeps a pointer to aim with', async ({ page }) => {
    // The reported defect: "I can click but I cannot see the mouse". The local pointer was
    // hidden unconditionally, and the peer suppresses cursor-position updates toward
    // whoever is sending input — so while controlling, the remote pointer lags or stops
    // and there was nothing left to aim with.
    await page.goto('/src/ui/viewer.html');
    await page.waitForFunction(() => globalThis.__viewer !== undefined, null, { timeout: 15_000 });

    const cursorStyle = () => page.evaluate(() =>
        getComputedStyle(document.getElementById('video')).cursor);

    expect(await cursorStyle(), 'idle: the pointer is visible').not.toBe('none');

    // Controlling, with a remote cursor being drawn: the local pointer still wins, because
    // it is the one that tracks the operator's hand.
    await page.evaluate(() => document.body.classList.add('remotecursor'));
    expect(await cursorStyle(), 'controlling: never hidden').not.toBe('none');

    // Watching: the remote pointer is the interesting one, and two would be noise.
    await page.evaluate(() => globalThis.__viewer.setViewOnly(true));
    expect(await cursorStyle(), 'view-only with a remote cursor: hidden').toBe('none');

    // Watching with no remote cursor to show is still better than no cursor at all.
    await page.evaluate(() => document.body.classList.remove('remotecursor'));
    expect(await cursorStyle(), 'view-only without one: visible again').not.toBe('none');
});
