/**
 * Media-layer conformance.
 *
 * These modules were previously verified only by running the viewer by hand. Most of what
 * can go wrong in them is logic, not rendering — key-frame gating, codec switching,
 * letterbox coordinate mapping, cursor cache keying — and all of that is reachable in
 * Node with injected fakes. What genuinely needs a browser is narrow: real WebCodecs
 * decoding and real compositing.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import zlib from 'node:zlib';

import { VideoStreamDecoder } from '../../src/media/decoder.js';
import { VideoSurface } from '../../src/render/surface.js';

/* -------------------------------------------------------------------------- */
/* Fakes                                                                      */
/* -------------------------------------------------------------------------- */

/** Records configure/decode calls in place of a real VideoDecoder. */
function fakeDecoderClass() {
    const instances = [];
    class FakeVideoDecoder {
        constructor({ output, error }) {
            this.output = output;
            this.error = error;
            this.state = 'unconfigured';
            this.configs = [];
            this.chunks = [];
            this.decodeQueueSize = 0;
            this.closed = false;
            instances.push(this);
        }

        configure(cfg) { this.configs.push(cfg); this.state = 'configured'; }

        decode(chunk) {
            if (this.throwOnDecode) throw new Error('decode rejected');
            this.chunks.push(chunk);
        }

        close() { this.closed = true; this.state = 'closed'; }
    }
    return { FakeVideoDecoder, instances };
}

// EncodedVideoChunk is a browser global the decoder constructs directly.
globalThis.EncodedVideoChunk = class {
    constructor({ type, timestamp, data }) {
        this.type = type;
        this.timestamp = timestamp;
        this.data = data;
    }
};

const unit = (key, byte = 1) => ({ data: new Uint8Array([byte]), key });
const frame = (codec, key, units) => ({ codec, key, units: units ?? [unit(key)] });

/** Minimal canvas stand-in: records draws and reports its own size. */
function fakeCanvas() {
    const calls = [];
    return {
        width: 0,
        height: 0,
        calls,
        getContext() {
            return {
                drawImage: (...a) => calls.push(a),
                clearRect: () => {},
                putImageData: () => {},
            };
        },
    };
}

/** A VideoFrame stand-in that records whether it was closed. */
function fakeFrame(w, h) {
    return { displayWidth: w, displayHeight: h, codedWidth: w, codedHeight: h, closed: false, close() { this.closed = true; } };
}

/* -------------------------------------------------------------------------- */
/* Decoder — key-frame gating                                                 */
/* -------------------------------------------------------------------------- */

test('deltas before the first key frame are dropped', () => {
    // A decoder configured and then fed a delta produces a permanently black screen:
    // the reference frames it needs were never decoded.
    const { FakeVideoDecoder, instances } = fakeDecoderClass();
    const d = new VideoStreamDecoder({ onFrame: () => {}, decoderClass: FakeVideoDecoder });

    assert.equal(d.decode(frame('h264', false)), false);
    assert.equal(d.decode(frame('h264', false)), false);
    assert.equal(d.stats().dropped, 2);
    assert.equal(instances[0].chunks.length, 0, 'nothing may reach the decoder yet');

    assert.equal(d.decode(frame('h264', true)), true);
    assert.equal(instances[0].chunks.length, 1);
    assert.equal(instances[0].chunks[0].type, 'key');
});

test('deltas flow once a key frame has been seen', () => {
    const { FakeVideoDecoder, instances } = fakeDecoderClass();
    const d = new VideoStreamDecoder({ onFrame: () => {}, decoderClass: FakeVideoDecoder });
    d.decode(frame('vp9', true));
    d.decode(frame('vp9', false));
    assert.deepEqual(instances[0].chunks.map((c) => c.type), ['key', 'delta']);
});

test('the decoder config omits description for in-band parameter sets', () => {
    const { FakeVideoDecoder, instances } = fakeDecoderClass();
    const d = new VideoStreamDecoder({ onFrame: () => {}, decoderClass: FakeVideoDecoder });
    d.decode(frame('h264', true));
    const cfg = instances[0].configs[0];
    assert.equal(cfg.codec, 'avc1.640028');
    assert.ok(!('description' in cfg), 'a description makes the decoder await parameter sets that never arrive separately');
    assert.equal(cfg.optimizeForLatency, true);
});

test('every access unit in a repeated frames array is decoded, in order', () => {
    // Skipping one corrupts the reference chain until the next key frame.
    const { FakeVideoDecoder, instances } = fakeDecoderClass();
    const d = new VideoStreamDecoder({ onFrame: () => {}, decoderClass: FakeVideoDecoder });
    d.decode(frame('vp9', true, [unit(true, 10), unit(false, 11), unit(false, 12)]));
    assert.deepEqual(instances[0].chunks.map((c) => c.data[0]), [10, 11, 12]);
    assert.deepEqual(instances[0].chunks.map((c) => c.type), ['key', 'delta', 'delta']);
});

test('timestamps increase monotonically even though pts is unusable', () => {
    // pts restarts near zero on refresh, codec change and display change, but WebCodecs
    // requires increasing timestamps — so they are synthesised.
    const { FakeVideoDecoder, instances } = fakeDecoderClass();
    const d = new VideoStreamDecoder({ onFrame: () => {}, decoderClass: FakeVideoDecoder });
    d.decode(frame('vp9', true, [unit(true), unit(false), unit(false)]));
    const ts = instances[0].chunks.map((c) => c.timestamp);
    for (let i = 1; i < ts.length; i++) assert.ok(ts[i] > ts[i - 1], `timestamp ${i} must increase`);
});

/* -------------------------------------------------------------------------- */
/* Decoder — codec switching and recovery                                     */
/* -------------------------------------------------------------------------- */

test('a codec change rebuilds the decoder and re-arms key-frame gating', () => {
    // The oneof tag is the codec identifier and can change mid-session, so switching is
    // driven by what arrives rather than by what was requested.
    const { FakeVideoDecoder, instances } = fakeDecoderClass();
    const d = new VideoStreamDecoder({ onFrame: () => {}, decoderClass: FakeVideoDecoder });
    d.decode(frame('vp9', true));
    assert.equal(instances.length, 1);

    assert.equal(d.decode(frame('h265', false)), false, 'must wait for a key frame in the new codec');
    assert.equal(instances.length, 2);
    assert.equal(instances[0].closed, true, 'the previous decoder is closed');
    assert.equal(instances[1].configs[0].codec, 'hev1.1.6.L93.B0');

    assert.equal(d.decode(frame('h265', true)), true);
});

test('a decode throw re-arms gating and asks for a key frame', () => {
    const { FakeVideoDecoder, instances } = fakeDecoderClass();
    let refreshes = 0;
    const errors = [];
    const d = new VideoStreamDecoder({
        onFrame: () => {},
        onError: (e, c) => errors.push(c),
        onKeyFrameNeeded: () => { refreshes++; },
        decoderClass: FakeVideoDecoder,
    });

    d.decode(frame('vp8', true));
    instances[0].throwOnDecode = true;
    assert.equal(d.decode(frame('vp8', false)), false);

    assert.equal(refreshes, 1);
    assert.deepEqual(errors, ['vp8']);
    assert.equal(d.stats().awaitingKeyFrame, true);
});

test('an async decoder error re-arms gating', () => {
    const { FakeVideoDecoder, instances } = fakeDecoderClass();
    let refreshes = 0;
    const d = new VideoStreamDecoder({
        onFrame: () => {}, onKeyFrameNeeded: () => { refreshes++; }, decoderClass: FakeVideoDecoder,
    });
    d.decode(frame('av1', true));
    instances[0].error(new Error('hardware reset'));
    assert.equal(d.stats().awaitingKeyFrame, true);
    assert.equal(refreshes, 1);
});

test('reset re-arms gating without tearing down the decoder', () => {
    const { FakeVideoDecoder, instances } = fakeDecoderClass();
    const d = new VideoStreamDecoder({ onFrame: () => {}, decoderClass: FakeVideoDecoder });
    d.decode(frame('vp9', true));
    d.reset();
    assert.equal(d.decode(frame('vp9', false)), false);
    assert.equal(instances.length, 1, 'no rebuild — a refresh only needs a new key frame');
});

test('decoding without WebCodecs is a no-op rather than a throw', () => {
    const d = new VideoStreamDecoder({ onFrame: () => {}, decoderClass: undefined });
    assert.equal(d.decode(frame('vp9', true)), false);
});

/* -------------------------------------------------------------------------- */
/* Surface — frame lifetime and coordinate mapping                            */
/* -------------------------------------------------------------------------- */

test('every drawn frame is closed, including the rejected ones', () => {
    // VideoFrame holds a GPU buffer from a small pool; leaking a handful stalls decoding
    // permanently, so every path must close.
    const s = new VideoSurface(fakeCanvas());
    const good = fakeFrame(1920, 1080);
    s.draw(good);
    assert.equal(good.closed, true);

    // A 0x0 frame must not be drawn: those dimensions feed input coordinate mapping, and
    // a divide-by-zero there surfaces as dead input rather than as a video fault.
    const bad = fakeFrame(0, 0);
    s.draw(bad);
    assert.equal(bad.closed, true, 'still closed even though it was not drawn');
    assert.equal(s.stats().painted, 1);
});

test('the canvas resizes to the frame and reports it once', () => {
    const canvas = fakeCanvas();
    const s = new VideoSurface(canvas);
    const sizes = [];
    s.onResize = (w, h) => sizes.push([w, h]);

    s.draw(fakeFrame(1920, 1080));
    s.draw(fakeFrame(1920, 1080));
    s.draw(fakeFrame(2560, 1440));

    assert.deepEqual(sizes, [[1920, 1080], [2560, 1440]], 'only on change');
    assert.equal(canvas.width, 2560);
});

test('letterboxed mapping puts the image centre at the element centre', () => {
    // object-fit: contain centres the image, so mapping against the element box instead
    // of the drawn image is the usual cause of a constant cursor offset.
    const s = new VideoSurface(fakeCanvas());
    s.draw(fakeFrame(1920, 1080)); // 16:9

    // A 1000x1000 element: the image is 1000x562.5, centred vertically.
    const centre = s.toRemote(500, 500, 1000, 1000);
    assert.equal(centre.x, 960);
    assert.equal(centre.y, 540);
});

test('mapping returns null outside the letterboxed image', () => {
    const s = new VideoSurface(fakeCanvas());
    s.draw(fakeFrame(1920, 1080));
    // Top of a 1000x1000 box is empty bar, not remote pixels — suppress rather than clamp,
    // or the pointer jumps to a row the user never aimed at.
    assert.equal(s.toRemote(500, 5, 1000, 1000), null);
    assert.equal(s.toRemote(500, 995, 1000, 1000), null);
    assert.notEqual(s.toRemote(500, 500, 1000, 1000), null);
});

test('mapping covers the full image without gaps at the edges', () => {
    const s = new VideoSurface(fakeCanvas());
    s.draw(fakeFrame(1920, 1080));
    // Exactly matching aspect ratio: no letterbox, so corners map to corners.
    const tl = s.toRemote(0, 0, 1920, 1080);
    const br = s.toRemote(1919, 1079, 1920, 1080);
    assert.deepEqual(tl, { x: 0, y: 0 });
    assert.deepEqual(br, { x: 1919, y: 1079 });
});

test('mapping is null before any frame has sized the surface', () => {
    const s = new VideoSurface(fakeCanvas());
    assert.equal(s.toRemote(10, 10, 800, 600), null);
});

/* -------------------------------------------------------------------------- */
/* Cursor — real zstd, cache keyed by 64-bit id                               */
/* -------------------------------------------------------------------------- */

const zstdAvailable = typeof zlib.zstdCompressSync === 'function';

test('cursor shapes decompress with real zstd and cache by 64-bit id', { skip: !zstdAvailable }, async () => {
    // ImageData is a browser global; createImageBitmap is deliberately absent so the
    // ImageData fallback path is the one under test.
    globalThis.ImageData = class {
        constructor(data, width, height) { this.data = data; this.width = width; this.height = height; }
    };
    const { CursorLayer } = await import('../../src/render/cursor.js');

    const layer = new CursorLayer(fakeCanvas());
    const rgba = Buffer.alloc(32 * 32 * 4, 0x7f);
    const colors = new Uint8Array(zlib.zstdCompressSync(rgba));

    // Two ids that collide when narrowed through Number — the reason the cache keys on
    // the string form.
    const a = 0x0123456789abcdefn;
    const b = 0x0123456789abcdeen;
    assert.equal(Number(a), Number(b), 'these really do collide as Numbers');

    await layer.setShape({ id: a, width: 32, height: 32, hotx: 1, hoty: 2, colors });
    await layer.setShape({ id: b, width: 32, height: 32, hotx: 3, hoty: 4, colors });

    assert.equal(layer.stats().cached, 2, 'distinct ids must not overwrite each other');
    assert.equal(layer.stats().decoded, 2);

    // A previously described shape is replayed as a bare id; there is no way to
    // re-request the bitmap, so a miss means the pointer freezes.
    assert.equal(layer.useShape(a), true);
    assert.equal(layer.current.hotx, 1);
    assert.equal(layer.useShape(b), true);
    assert.equal(layer.current.hotx, 3);
    assert.equal(layer.stats().missing, 0);

    assert.equal(layer.useShape(0xdeadbeefn), false);
    assert.equal(layer.stats().missing, 1);
});

test('a corrupt cursor payload keeps the previous shape rather than failing the session', { skip: !zstdAvailable }, async () => {
    globalThis.ImageData = class {
        constructor(data, width, height) { this.data = data; this.width = width; this.height = height; }
    };
    const { CursorLayer } = await import('../../src/render/cursor.js');

    const layer = new CursorLayer(fakeCanvas());
    const colors = new Uint8Array(zlib.zstdCompressSync(Buffer.alloc(16 * 16 * 4, 9)));
    await layer.setShape({ id: 1n, width: 16, height: 16, colors });
    assert.equal(layer.stats().cached, 1);

    await layer.setShape({ id: 2n, width: 16, height: 16, colors: new Uint8Array([1, 2, 3]) });
    assert.equal(layer.stats().cached, 1, 'undecodable shape is skipped');
    assert.equal(layer.current.width, 16, 'the working cursor is retained');
});

test('an embedded cursor suppresses the overlay', { skip: !zstdAvailable }, async () => {
    globalThis.ImageData = class {
        constructor(data, width, height) { this.data = data; this.width = width; this.height = height; }
    };
    const { CursorLayer } = await import('../../src/render/cursor.js');

    const layer = new CursorLayer(fakeCanvas());
    layer.setDisplay({ x: 0, y: 0, cursor_embedded: true });
    assert.equal(layer.stats().embedded, true, 'the peer already burned it into the video');
});

test('cursor position is stored in virtual-desktop space', { skip: !zstdAvailable }, async () => {
    globalThis.ImageData = class {
        constructor(data, width, height) { this.data = data; this.width = width; this.height = height; }
    };
    const { CursorLayer } = await import('../../src/render/cursor.js');

    const layer = new CursorLayer(fakeCanvas());
    layer.setDisplay({ x: 1920, y: 0 });
    layer.setPosition(2000, 300);
    assert.deepEqual(layer.stats().position, { x: 2000, y: 300 });
    assert.deepEqual(layer.origin, { x: 1920, y: 0 }, 'origin is subtracted at draw time');
});
