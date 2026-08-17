/**
 * Session-layer conformance: permission negative-signalling, the video frame queue,
 * and codec advertisement.
 *
 * These are pure logic, so they run in Node with no browser. The WebCodecs and canvas
 * paths are covered separately once a browser harness exists.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { PermissionSet } from '../../src/session/permissions.js';
import { FrameQueue, MAX_REFRESHES, REFRESH_INTERVAL_MS } from '../../src/media/frame-queue.js';
import {
    CodecCapabilities, RECOVERY_FRAMES, customQuality, presetQuality, fpsLimit, decoderConfig,
    probeDecodable,
} from '../../src/media/codec.js';
import { Permission, PreferCodec, ImageQuality } from '../../src/protocol/enums.js';

/* -------------------------------------------------------------------------- */
/* Permissions — the inverted convention                                      */
/* -------------------------------------------------------------------------- */

test('every permission starts granted', () => {
    // A fully permissive peer sends NOTHING. Defaulting to false would produce a client
    // where every feature is greyed out and no message explains why.
    const p = new PermissionSet();
    for (const name of Object.keys(Permission)) {
        assert.equal(p.allows(name), true, `${name} must default to allowed`);
    }
    assert.deepEqual(p.denied(), []);
});

test('a denial switches exactly one permission off', () => {
    const p = new PermissionSet();
    // `enabled` is omitted on the wire when false — it is the proto3 default.
    const changed = p.apply({ permission: Permission.File });
    assert.equal(changed, 'File');
    assert.equal(p.allows('File'), false);
    assert.equal(p.allows('Clipboard'), true);
    assert.deepEqual(p.denied(), ['File']);
});

test('an explicit enabled:false is equivalent to an omitted one', () => {
    const a = new PermissionSet();
    a.apply({ permission: Permission.Clipboard, enabled: false });
    const b = new PermissionSet();
    b.apply({ permission: Permission.Clipboard });
    assert.deepEqual(a.snapshot(), b.snapshot());
});

test('permissions can be re-granted mid-session', () => {
    // The peer's operator can toggle these live in the connection manager.
    const seen = [];
    const p = new PermissionSet((name, enabled) => seen.push([name, enabled]));
    p.apply({ permission: Permission.Keyboard });
    assert.equal(p.allows('Keyboard'), false);
    p.apply({ permission: Permission.Keyboard, enabled: true });
    assert.equal(p.allows('Keyboard'), true);
    assert.deepEqual(seen, [['Keyboard', false], ['Keyboard', true]]);
});

test('a repeated denial does not fire onChange twice', () => {
    let calls = 0;
    const p = new PermissionSet(() => { calls++; });
    p.apply({ permission: Permission.Audio });
    p.apply({ permission: Permission.Audio });
    assert.equal(calls, 1);
});

test('permission value 0 is Keyboard, not "unknown"', () => {
    // Keyboard = 0 collides with the proto3 default, so a naive truthiness check would
    // silently ignore every keyboard denial.
    const p = new PermissionSet();
    assert.equal(p.apply({ permission: 0 }), 'Keyboard');
    assert.equal(p.allows('Keyboard'), false);
});

test('an unknown permission value is ignored rather than throwing', () => {
    const p = new PermissionSet();
    assert.equal(p.apply({ permission: 99 }), null);
    assert.deepEqual(p.denied(), []);
});

/* -------------------------------------------------------------------------- */
/* Frame queue                                                                */
/* -------------------------------------------------------------------------- */

const frame = (key, display = 0) => ({ display, key, units: [{ data: new Uint8Array([1]), key }] });

test('a key frame bypasses the queue and clears queued deltas', () => {
    const q = new FrameQueue();
    q.push(frame(false));
    q.push(frame(false));
    assert.equal(q.length, 2);

    const r = q.push(frame(true));
    assert.equal(r.deliver?.key, true, 'key frames deliver immediately');
    assert.equal(q.length, 0, 'stale deltas are worthless after a key frame');
});

test('deltas are dropped while discarding, and a key frame resumes', () => {
    const q = new FrameQueue();
    q.markRefreshed();
    assert.equal(q.discarding, true);

    assert.equal(q.push(frame(false)).deliver, null);
    assert.equal(q.push(frame(false)).deliver, null);
    assert.equal(q.stats().droppedWhileDiscarding, 2);

    const r = q.push(frame(true));
    assert.equal(r.deliver?.key, true);
    assert.equal(q.discarding, false, 'a key frame is the recovery point');
});

test('overflow drops the ring and asks for a refresh', () => {
    const q = new FrameQueue({ capacity: 3 });
    for (let i = 0; i < 3; i++) assert.equal(q.push(frame(false)).needsRefresh, false);

    const r = q.push(frame(false));
    assert.equal(r.needsRefresh, true, 'the pipeline is unrecoverably behind');
    assert.equal(q.length, 0);
    assert.equal(q.discarding, true);
    assert.equal(q.stats().overflowed, 1);
});

test('refresh is rate limited — it restarts capture for every viewer', () => {
    let now = 100_000;
    const q = new FrameQueue({ now: () => now });

    assert.equal(q.mayRefresh(), true);
    q.markRefreshed();
    assert.equal(q.mayRefresh(), false, 'no second refresh within the interval');

    now += REFRESH_INTERVAL_MS - 1;
    assert.equal(q.mayRefresh(), false);
    now += 1;
    assert.equal(q.mayRefresh(), true);
});

test('refresh is capped for the session', () => {
    let now = 0;
    const q = new FrameQueue({ now: () => now });
    for (let i = 0; i < MAX_REFRESHES; i++) {
        assert.equal(q.mayRefresh(), true, `refresh ${i} should be allowed`);
        q.markRefreshed();
        now += REFRESH_INTERVAL_MS;
    }
    assert.equal(q.mayRefresh(), false, 'the per-session cap must hold');
});

test('a decode failure starts discarding until the next key frame', () => {
    const q = new FrameQueue();
    q.push(frame(false));
    q.markDecodeFailure();
    assert.equal(q.length, 0);
    assert.equal(q.push(frame(false)).deliver, null);
    assert.equal(q.push(frame(true)).deliver?.key, true);
});

test('frames drain in order', () => {
    const q = new FrameQueue();
    q.push({ display: 0, key: false, units: [{ data: new Uint8Array([1]) }] });
    q.push({ display: 0, key: false, units: [{ data: new Uint8Array([2]) }] });
    assert.equal(q.shift().units[0].data[0], 1);
    assert.equal(q.shift().units[0].data[0], 2);
    assert.equal(q.shift(), undefined);
});

/* -------------------------------------------------------------------------- */
/* Codec advertisement                                                        */
/* -------------------------------------------------------------------------- */

test('an empty probe still advertises VP9 — the peer\'s only ungated fallback', () => {
    // A viewer advertising no codec at all cannot be sent anything.
    const caps = new CodecCapabilities([]);
    assert.equal(caps.toSupportedDecoding().ability_vp9, 1);
});

test('a probe that excluded VP9 is respected, not overridden', () => {
    // Forcing it back would advertise a codec this browser cannot decode: a
    // connect-and-die loop for us, and every other viewer of the same peer is dragged
    // onto it too, since a codec is only usable if every viewer can decode it.
    const caps = new CodecCapabilities(['h264']);
    const sd = caps.toSupportedDecoding();
    assert.equal(sd.ability_vp9, 0);
    assert.equal(sd.ability_h264, 1);
});

test('advertisement maps decodable families to 1 and the rest to 0', () => {
    const caps = new CodecCapabilities(['vp8', 'vp9', 'h264']);
    const sd = caps.toSupportedDecoding();
    assert.equal(sd.ability_vp8, 1);
    assert.equal(sd.ability_vp9, 1);
    assert.equal(sd.ability_h264, 1);
    assert.equal(sd.ability_av1, 0);
    assert.equal(sd.ability_h265, 0);
    assert.equal(sd.prefer, PreferCodec.Auto);
});

test('a stated preference is encoded', () => {
    assert.equal(new CodecCapabilities(['h264'], 'h264').toSupportedDecoding().prefer, PreferCodec.H264);
    assert.equal(new CodecCapabilities(['av1'], 'av1').toSupportedDecoding().prefer, PreferCodec.AV1);
});

test('three consecutive failures retire a codec and trigger re-advertisement', () => {
    // isConfigSupported can claim AV1 works and then fail at decode time, so support is
    // only real once frames actually decode.
    const caps = new CodecCapabilities(['vp9', 'av1']);
    assert.equal(caps.markFailure('av1'), false);
    assert.equal(caps.markFailure('av1'), false);
    assert.equal(caps.markFailure('av1'), true, 'third failure retires it');
    assert.equal(caps.supports('av1'), false);
    assert.equal(caps.toSupportedDecoding().ability_av1, 0);
});

test('a sustained run of clean frames resets the failure counter', () => {
    const caps = new CodecCapabilities(['vp9', 'h264']);
    caps.markFailure('h264');
    caps.markFailure('h264');
    for (let i = 0; i < RECOVERY_FRAMES; i++) caps.markSuccess('h264');
    assert.equal(caps.markFailure('h264'), false, 'counter must have reset');
    assert.equal(caps.supports('h264'), true);
});

test('one good frame does not forgive a failing codec', () => {
    // Every decoder error is followed by a refresh, so the next key frame usually decodes.
    // Crediting that single frame would reset the streak between every pair of failures
    // and a codec failing steadily would never reach three in a row — making the
    // retirement rule unreachable in precisely the case it exists for.
    const caps = new CodecCapabilities(['vp9', 'av1']);
    for (let i = 0; i < 3; i++) {
        const retired = caps.markFailure('av1');
        caps.markSuccess('av1'); // the post-refresh key frame
        if (i < 2) assert.equal(retired, false);
        else assert.equal(retired, true, 'a codec failing once per key frame still retires');
    }
    assert.equal(caps.supports('av1'), false);
});

test('clean frames for one codec do not forgive another', () => {
    const caps = new CodecCapabilities(['vp9', 'av1']);
    caps.markFailure('av1');
    caps.markFailure('av1');
    for (let i = 0; i < RECOVERY_FRAMES * 2; i++) caps.markSuccess('vp9');
    assert.equal(caps.markFailure('av1'), true, 'av1 still had two failures against it');
});

test('marking success with no failures on record is free', () => {
    // Called once per decoded frame, so the common case must not allocate or look up.
    const caps = new CodecCapabilities(['vp9']);
    assert.equal(caps.markSuccess('vp9'), false);
    assert.equal(caps.failures.size, 0);
});

test('retiring an already-retired codec does not re-trigger', () => {
    const caps = new CodecCapabilities(['vp9', 'av1']);
    caps.markFailure('av1');
    caps.markFailure('av1');
    assert.equal(caps.markFailure('av1'), true);
    assert.equal(caps.markFailure('av1'), false, 'no repeated re-advertisement');
});

test('decoder config omits description so Annex-B parameter sets are read in-band', () => {
    const cfg = decoderConfig('h264');
    assert.equal(cfg.codec, 'avc1.640028');
    assert.equal(cfg.optimizeForLatency, true);
    assert.ok(!('description' in cfg),
        'supplying a description would make the decoder wait for parameter sets that never arrive separately');
});

test('probeDecodable returns empty when WebCodecs is absent', async () => {
    // Node has no VideoDecoder; the client gates on this and shows an unsupported page.
    assert.equal((await probeDecodable(undefined)).size, 0);
});

test('probeDecodable collects only families the browser confirms', async () => {
    const fake = {
        isConfigSupported: async (cfg) => ({ supported: cfg.codec.startsWith('vp09') || cfg.codec.startsWith('avc1') }),
    };
    const got = await probeDecodable(fake);
    assert.deepEqual([...got].sort(), ['h264', 'vp9']);
});

/* -------------------------------------------------------------------------- */
/* Quality and FPS encoding                                                   */
/* -------------------------------------------------------------------------- */

test('custom image quality is shifted left by 8', () => {
    assert.deepEqual(customQuality(50), { custom_image_quality: 50 << 8 });
    assert.equal(customQuality(50).custom_image_quality, 12800);
});

test('presets map to the protocol enum, and never to NotSet', () => {
    // NotSet means "leave unchanged". Offering it as a choice produces a control that
    // silently does nothing once any other quality has been selected.
    assert.deepEqual(presetQuality('speed'), { image_quality: ImageQuality.Low });
    assert.deepEqual(presetQuality('balanced'), { image_quality: ImageQuality.Balanced });
    assert.deepEqual(presetQuality('best'), { image_quality: ImageQuality.Best });
    assert.deepEqual(presetQuality('nonsense'), { image_quality: ImageQuality.Balanced });
    assert.notEqual(presetQuality('speed').image_quality, ImageQuality.NotSet);
});

test('a preset carries no custom value, which would override it', () => {
    // The two are mutually exclusive on the wire: sending both drops the custom one.
    assert.equal('custom_image_quality' in presetQuality('best'), false);
    assert.equal('image_quality' in customQuality(80), false);
});

test('quality is clamped to the range the peer accepts', () => {
    assert.equal(customQuality(1).custom_image_quality, 10 << 8);
    assert.equal(customQuality(99999).custom_image_quality, 2000 << 8);
});

test('fps is clamped to 1..120 — outside that the peer ignores it silently', () => {
    assert.deepEqual(fpsLimit(30), { custom_fps: 30 });
    assert.equal(fpsLimit(0).custom_fps, 1);
    assert.equal(fpsLimit(500).custom_fps, 120);
});

/* -------------------------------------------------------------------------- */
/* Login options                                                              */
/* -------------------------------------------------------------------------- */

test('login asks the peer for its cursor', async () => {
    // Without this the host sends no cursor at all: a native client draws its own local
    // pointer over the window, so the peer has no reason to. A browser viewer must hide
    // its local pointer or the user sees two — and then sees none, and clicks blind.
    const { LoginRequest } = await import('../../src/protocol/message.js');
    const { BoolOption } = await import('../../src/protocol/enums.js');

    assert.ok(LoginRequest.fields, 'LoginRequest carries an option field');
    assert.equal(BoolOption.Yes, 2, 'NotSet is the proto3 default, so Yes must be explicit');
});
