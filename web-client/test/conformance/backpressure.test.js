/**
 * Admission-control conformance.
 *
 * The pipeline has exactly one bounded stage, and these are the rules that make it work.
 * The `video_received` ACK cannot be the bound — the peer stops capturing until it
 * arrives — so the limit is applied to what the decoder is handed, and shedding is to the
 * next key frame because the protocol offers no way to ask for less.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { FrameQueue } from '../../src/media/frame-queue.js';
import { VideoStreamDecoder } from '../../src/media/decoder.js';

// `node --test` runs each file in its own process, so browser globals must be stubbed
// per file rather than once. Without this the decoder's own try/catch swallows a
// ReferenceError and every decode silently reports failure.
globalThis.EncodedVideoChunk = class {
    constructor({ type, timestamp, data }) {
        this.type = type;
        this.timestamp = timestamp;
        this.data = data;
    }
};

const frame = (key, display = 0) => ({ display, key, units: [{ data: new Uint8Array([1]), key }] });

/* -------------------------------------------------------------------------- */
/* Shedding under decoder backpressure                                        */
/* -------------------------------------------------------------------------- */

test('backpressure sheds to the next key frame rather than buffering', () => {
    // Buffering a backlog of live video trades latency for frames nobody wants to see.
    const q = new FrameQueue();
    q.push(frame(false));
    q.push(frame(false));

    q.markBackpressure();
    assert.equal(q.length, 0, 'queued deltas are discarded, not drained later');
    assert.equal(q.discarding, true);
    assert.equal(q.stats().backpressureEvents, 1);

    assert.equal(q.push(frame(false)).deliver, null, 'still shedding');
    assert.equal(q.push(frame(true)).deliver?.key, true, 'a key frame is the recovery point');
    assert.equal(q.discarding, false);
});

test('backpressure is distinguishable from a decode failure in the stats', () => {
    // Same recovery, different cause: one means the stream is broken, the other means we
    // are simply slower than the network. Operators and bug reports need to tell them
    // apart.
    const a = new FrameQueue();
    a.markDecodeFailure();
    assert.equal(a.stats().backpressureEvents, 0);

    const b = new FrameQueue();
    b.markBackpressure();
    assert.equal(b.stats().backpressureEvents, 1);
    assert.equal(a.discarding, b.discarding, 'both shed until the next key frame');
});

test('a key frame is never shed, however far behind the decoder is', () => {
    // Shedding the one frame that could recover the stream would strand it permanently.
    const q = new FrameQueue();
    q.markBackpressure();
    const r = q.push(frame(true));
    assert.equal(r.deliver?.key, true);
    assert.equal(q.discarding, false);
});

/* -------------------------------------------------------------------------- */
/* Codec configure must not kill the session                                   */
/* -------------------------------------------------------------------------- */

test('a codec the browser cannot configure retires it instead of throwing', () => {
    // configure() throws synchronously for an unsupported codec. Unguarded that
    // propagates through the session's message pump and closes the connection, when the
    // right answer is to retire the codec and let the peer re-encode.
    class ThrowingDecoder {
        constructor({ error }) { this.error = error; this.state = 'unconfigured'; this.decodeQueueSize = 0; }
        configure() { throw new DOMExceptionLike('codec not supported'); }
        decode() { throw new Error('should never be reached'); }
        close() { this.state = 'closed'; }
    }
    class DOMExceptionLike extends Error {}

    const errors = [];
    const d = new VideoStreamDecoder({
        onFrame: () => {},
        onError: (err, codec) => errors.push(codec),
        decoderClass: ThrowingDecoder,
    });

    let threw = false;
    let result;
    try {
        result = d.decode({ codec: 'h265', key: true, units: [{ data: new Uint8Array([1]), key: true }] });
    } catch {
        threw = true;
    }

    assert.equal(threw, false, 'the session must survive an unsupported codec');
    assert.equal(result, false);
    assert.deepEqual(errors, ['h265'], 'the failure is reported so the codec can be retired');
    assert.equal(d.stats().awaitingKeyFrame, true);
});

test('after a failed configure a different codec still works', () => {
    // The retire-and-readvertise path only helps if the decoder is usable afterwards.
    let failNext = true;
    const decoded = [];
    class SelectiveDecoder {
        constructor({ output }) { this.output = output; this.state = 'unconfigured'; this.decodeQueueSize = 0; }
        configure(cfg) {
            if (failNext) throw new Error(`unsupported: ${cfg.codec}`);
            this.state = 'configured';
        }
        decode(chunk) { decoded.push(chunk.type); }
        close() { this.state = 'closed'; }
    }

    const d = new VideoStreamDecoder({ onFrame: () => {}, decoderClass: SelectiveDecoder });
    assert.equal(d.decode({ codec: 'av1', key: true, units: [{ data: new Uint8Array([1]), key: true }] }), false);

    failNext = false;
    assert.equal(d.decode({ codec: 'vp9', key: true, units: [{ data: new Uint8Array([2]), key: true }] }), true);
    assert.deepEqual(decoded, ['key']);
});

/* -------------------------------------------------------------------------- */
/* Refresh remains rate limited under sustained failure                        */
/* -------------------------------------------------------------------------- */

test('sustained backpressure does not produce a refresh per frame', () => {
    // A refresh restarts capture for EVERY viewer of the display, so a 30fps stream in
    // trouble must not become a 30Hz refresh storm.
    let now = 0;
    const q = new FrameQueue({ now: () => now });
    let refreshes = 0;

    for (let i = 0; i < 300; i++) {
        q.markBackpressure();
        if (q.mayRefresh()) { q.markRefreshed(); refreshes++; }
        now += 33; // ~30fps
    }

    assert.ok(refreshes <= 2, `10s of frames may request at most ~1 refresh, got ${refreshes}`);
    assert.equal(q.stats().backpressureEvents, 300, 'every event is still counted');
});
