/**
 * Regression tests for the pre-release review findings.
 *
 * Each case here corresponds to a defect that was found by review rather than by the
 * suite — the tests validated the protocol layer, which was already sound, while these
 * lived in the seams: session lifecycle, recovery targeting, and what the UI is told.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import nacl from '../../vendor/tweetnacl/nacl.js';
import { encode } from '../../src/protocol/codec.js';
import { IdPk } from '../../src/protocol/message.js';
import { negotiate } from '../../src/crypto/handshake.js';

const PEER_ID = '123456789';

/** Builds a valid server assertion plus the peer's own SignedId. */
function scenario(peerId = PEER_ID) {
    const server = nacl.sign.keyPair();
    const peerSign = nacl.sign.keyPair();
    const peerBox = nacl.box.keyPair();
    return {
        server,
        signedIdPk: nacl.sign(encode(IdPk, { id: peerId, pk: peerSign.publicKey }), server.secretKey),
        peerSignedId: nacl.sign(encode(IdPk, { id: peerId, pk: peerBox.publicKey }), peerSign.secretKey),
    };
}

/* -------------------------------------------------------------------------- */
/* Encryption downgrade must be detectable by the caller                       */
/* -------------------------------------------------------------------------- */

test('a downgrade always reports a reason the UI can surface', () => {
    // The downgrade path existed and was correct, but nothing carried the reason to the
    // operator — so a stripped session looked identical to a healthy one.
    const { peerSignedId } = scenario();

    const noKey = negotiate({
        signedIdPk: new Uint8Array(0), serverPk: new Uint8Array(32), peerSignedId, peerId: PEER_ID,
    });
    assert.equal(noKey.sessionKey, null);
    assert.ok(noKey.downgradeReason, 'a downgrade with no reason cannot be surfaced');

    const tampered = scenario();
    const bad = Uint8Array.from(tampered.signedIdPk);
    bad[0] ^= 0x01;
    const forged = negotiate({
        signedIdPk: bad, serverPk: tampered.server.publicKey, peerSignedId: tampered.peerSignedId, peerId: PEER_ID,
    });
    assert.equal(forged.sessionKey, null);
    assert.match(forged.downgradeReason, /signature mismatch/);
});

test('a successful handshake reports no downgrade reason', () => {
    const { server, signedIdPk, peerSignedId } = scenario();
    const ok = negotiate({ signedIdPk, serverPk: server.publicKey, peerSignedId, peerId: PEER_ID });
    assert.notEqual(ok.sessionKey, null);
    assert.equal(ok.downgradeReason, null, 'a healthy session must not raise a warning');
});

test('a substituted peer identity is a downgrade, not a silent success', () => {
    // hbbs vouches for identity key A; the thing on the relay signs with key B. This is
    // the attack the two-step chain exists to stop, and it must be reported as such.
    const a = scenario();
    const b = scenario();
    const r = negotiate({
        signedIdPk: a.signedIdPk, serverPk: a.server.publicKey, peerSignedId: b.peerSignedId, peerId: PEER_ID,
    });
    assert.equal(r.sessionKey, null);
    assert.match(r.downgradeReason, /does not match its asserted identity key/);
});

/* -------------------------------------------------------------------------- */
/* Refresh targeting and rate limiting                                        */
/* -------------------------------------------------------------------------- */

test('refresh rate limiting bounds a decode-error storm', async () => {
    // The decoder requests a key frame on every error, and a refresh restarts the peer's
    // capture pipeline for EVERY viewer of that display. Unbounded, a persistently bad
    // stream hammers the host.
    const { FrameQueue, MAX_REFRESHES, REFRESH_INTERVAL_MS } = await import('../../src/media/frame-queue.js');
    let now = 0;
    const q = new FrameQueue({ now: () => now });

    let allowed = 0;
    for (let i = 0; i < 100; i++) {
        if (q.mayRefresh()) { q.markRefreshed(); allowed++; }
        now += 250; // errors arriving four times a second
    }
    assert.ok(allowed < 100, 'must not permit one refresh per error');
    assert.ok(allowed <= MAX_REFRESHES, `capped at ${MAX_REFRESHES} per session`);

    // And the interval genuinely gates, rather than only the session cap.
    const q2 = new FrameQueue({ now: () => now });
    assert.equal(q2.mayRefresh(), true);
    q2.markRefreshed();
    assert.equal(q2.mayRefresh(), false);
    now += REFRESH_INTERVAL_MS;
    assert.equal(q2.mayRefresh(), true);
});
