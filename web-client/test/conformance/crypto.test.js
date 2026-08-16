/**
 * Crypto conformance: password derivation, the Ed25519 verification chain, and the
 * secretbox stream driven by the real vendored cipher.
 *
 * The handshake is exercised against synthetic server and peer keypairs so the whole
 * chain — including the tampering cases — can run without a live peer.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';

import nacl from '../../vendor/tweetnacl/nacl.js';
import { encode, decode } from '../../src/protocol/codec.js';
import { IdPk } from '../../src/protocol/message.js';
import { deriveH1, deriveH2, derivePassword } from '../../src/crypto/password.js';
import {
    verifyServerAssertion, verifyPeerSignedId, createSessionKey, negotiate, decodeBase64,
} from '../../src/crypto/handshake.js';
import { secretboxCipher, signOpen, sealSessionKey, generateBoxKeyPair } from '../../src/crypto/cipher.js';
import { SecretStream } from '../../src/crypto/stream.js';

const utf8 = (s) => new TextEncoder().encode(s);

/** Cross-check against node:crypto, so the WebCrypto path is not verified by itself. */
function refSha256(...parts) {
    const h = createHash('sha256');
    for (const p of parts) h.update(Buffer.from(p));
    return new Uint8Array(h.digest());
}

/* -------------------------------------------------------------------------- */
/* Password derivation                                                        */
/* -------------------------------------------------------------------------- */

// Fixtures only. Never put a real connection password or a real deployment's ids in
// here — tests are committed, credentials are not.
const TEST_PASSWORD = 'correct horse battery staple!9';

test('h1 is SHA256(password || salt) with no separator', async () => {
    const h1 = await deriveH1(TEST_PASSWORD, 'abc123');
    assert.deepEqual(h1, refSha256(utf8(TEST_PASSWORD), utf8('abc123')));
    assert.equal(h1.length, 32);
    // Order matters: the reverse concatenation must not collide.
    assert.notDeepEqual(h1, refSha256(utf8('abc123'), utf8(TEST_PASSWORD)));
});

test('h2 is SHA256(h1 || challenge)', async () => {
    const h1 = await deriveH1('pw', 'salt');
    const h2 = await deriveH2(h1, 'chal12');
    assert.deepEqual(h2, refSha256(h1, utf8('chal12')));
    assert.equal(h2.length, 32);
});

test('derivePassword composes both rounds', async () => {
    const direct = await derivePassword('pw', 'salt', 'chal12');
    const stepwise = await deriveH2(await deriveH1('pw', 'salt'), 'chal12');
    assert.deepEqual(direct, stepwise);
});

test('h1 is reusable across connections but h2 is not', async () => {
    // The peer regenerates `challenge` every connection, which is what makes a captured
    // h2 useless for replay while still allowing a client to cache h1.
    const h1a = await deriveH1('pw', 'salt');
    const h1b = await deriveH1('pw', 'salt');
    assert.deepEqual(h1a, h1b, 'h1 depends only on password and salt');

    const h2a = await deriveH2(h1a, 'chalAA');
    const h2b = await deriveH2(h1a, 'chalBB');
    assert.notDeepEqual(h2a, h2b, 'a fresh challenge must change the proof');
});

test('an empty salt is handled — some peers have no permanent password', async () => {
    const h1 = await deriveH1('pw', '');
    assert.deepEqual(h1, refSha256(utf8('pw')));
});

test('non-ASCII passwords hash as UTF-8 bytes', async () => {
    const h1 = await deriveH1('pässwörd☃', 'salt');
    assert.deepEqual(h1, refSha256(utf8('pässwörd☃'), utf8('salt')));
});

/* -------------------------------------------------------------------------- */
/* The Ed25519 verification chain                                             */
/* -------------------------------------------------------------------------- */

const PEER_ID = '123456789'; // fixture: a 9-digit id, matching real id shape

/** Builds a server + peer pair and the two signed blobs a real session would carry. */
function scenario(peerId = PEER_ID) {
    const server = nacl.sign.keyPair(); // hbbs identity
    const peerSign = nacl.sign.keyPair(); // peer long-term identity
    const peerBox = nacl.box.keyPair(); // peer per-connection X25519

    // hbbs signs {id, peer's long-term Ed25519 pk}
    const signedIdPk = nacl.sign(encode(IdPk, { id: peerId, pk: peerSign.publicKey }), server.secretKey);
    // the peer signs {id, its ephemeral X25519 pk}
    const peerSignedId = nacl.sign(encode(IdPk, { id: peerId, pk: peerBox.publicKey }), peerSign.secretKey);

    return { server, peerSign, peerBox, signedIdPk, peerSignedId };
}

test('signed blob length matches what a real server sends', () => {
    const { signedIdPk } = scenario();
    // Observed against a live hbbs: 109 bytes = 64-byte signature + IdPk{9-char id, 32-byte key}.
    assert.equal(signedIdPk.length, 109);
});

test('the full chain yields a session key and a PublicKey message', () => {
    const { server, signedIdPk, peerSignedId } = scenario();
    const r = negotiate({ signedIdPk, serverPk: server.publicKey, peerSignedId, peerId: PEER_ID });

    assert.equal(r.downgradeReason, null);
    assert.equal(r.sessionKey.length, 32);
    assert.equal(r.publicKeyMessage.asymmetric_value.length, 32);
    assert.equal(r.publicKeyMessage.symmetric_value.length, 48, '32-byte key + 16-byte MAC');
    assert.equal(r.peerSignPk.length, 32);
});

test('the peer can open the sealed session key', () => {
    // Proves we sealed to the right key with the right (all-zero) nonce — the peer side
    // of the exchange, which we cannot otherwise observe without a live session.
    const { peerBox } = scenario();
    const { sessionKey, asymmetricValue, symmetricValue } = createSessionKey(peerBox.publicKey);
    const opened = nacl.box.open(symmetricValue, new Uint8Array(24), asymmetricValue, peerBox.secretKey);
    assert.notEqual(opened, null, 'peer must be able to open the box');
    assert.deepEqual(opened, sessionKey);
});

test('step 1 rejects a blob signed by the wrong server key', () => {
    const { signedIdPk } = scenario();
    const impostor = nacl.sign.keyPair();
    const r = verifyServerAssertion(signedIdPk, impostor.publicKey, PEER_ID);
    assert.equal(r.ok, false);
    assert.match(r.reason, /signature mismatch/);
});

test('step 1 rejects an id that is not the peer we asked for', () => {
    const { server, signedIdPk } = scenario('999999999');
    const r = verifyServerAssertion(signedIdPk, server.publicKey, PEER_ID);
    assert.equal(r.ok, false);
    assert.match(r.reason, /but we asked for/);
});

test('step 2 rejects a SignedId not signed by the asserted identity key', () => {
    // This is the attack the chain exists to stop: hbbs vouches for identity key A, but
    // the thing on the relay signs with key B.
    const { server, signedIdPk } = scenario();
    const other = scenario();
    const r = negotiate({
        signedIdPk, serverPk: server.publicKey, peerSignedId: other.peerSignedId, peerId: PEER_ID,
    });
    assert.equal(r.sessionKey, null);
    assert.match(r.downgradeReason, /does not match its asserted identity key/);
});

test('a tampered signature byte is detected', () => {
    const { server, signedIdPk, peerSignedId } = scenario();
    const bad = Uint8Array.from(signedIdPk);
    bad[0] ^= 0x01;
    const r = negotiate({ signedIdPk: bad, serverPk: server.publicKey, peerSignedId, peerId: PEER_ID });
    assert.equal(r.sessionKey, null);
    assert.match(r.downgradeReason, /signature mismatch/);
});

test('missing server key or signed pk downgrades with a stated reason, not a throw', () => {
    const { peerSignedId } = scenario();
    const a = negotiate({
        signedIdPk: new Uint8Array(0), serverPk: new Uint8Array(32), peerSignedId, peerId: PEER_ID,
    });
    assert.equal(a.sessionKey, null);
    assert.match(a.downgradeReason, /no signed public key/);

    const { signedIdPk } = scenario();
    const b = negotiate({
        signedIdPk, serverPk: new Uint8Array(0), peerSignedId, peerId: PEER_ID,
    });
    assert.equal(b.sessionKey, null);
    assert.match(b.downgradeReason, /no usable server public key/);
});

test('signOpen refuses a short blob rather than reading out of bounds', () => {
    const { server } = scenario();
    assert.equal(signOpen(new Uint8Array(10), server.publicKey), null);
    assert.equal(signOpen(new Uint8Array(64), server.publicKey), null);
});

test('decodeBase64 reads a server key into 32 bytes', () => {
    // Synthetic key with the same encoding shape as a real one (44 base64 chars → 32 B).
    const { publicKey } = nacl.sign.keyPair();
    const b64 = Buffer.from(publicKey).toString('base64');
    assert.equal(b64.length, 44);
    assert.deepEqual(decodeBase64(b64), publicKey);
});

/* -------------------------------------------------------------------------- */
/* The stream, driven by the real cipher                                      */
/* -------------------------------------------------------------------------- */

test('secretbox round-trips through paired streams with the real cipher', () => {
    const key = nacl.randomBytes(32);
    const a = new SecretStream(secretboxCipher, key);
    const b = new SecretStream(secretboxCipher, key);

    for (let i = 0; i < 8; i++) {
        const plain = utf8(`message ${i}`);
        assert.deepEqual(b.decrypt(a.encrypt(plain)), plain, `message ${i}`);
    }
    assert.equal(a.sendCounter, 8n);
    assert.equal(b.recvCounter, 8n);
});

test('a heartbeat between real messages does not desynchronise the counters', () => {
    const key = nacl.randomBytes(32);
    const a = new SecretStream(secretboxCipher, key);
    const b = new SecretStream(secretboxCipher, key);

    const first = a.encrypt(utf8('one'));
    assert.deepEqual(b.decrypt(first), utf8('one'));
    b.decrypt(new Uint8Array(0)); // heartbeat, must not count
    const second = a.encrypt(utf8('two'));
    assert.deepEqual(b.decrypt(second), utf8('two'), 'counters must still be aligned');
});

test('a reordered frame fails authentication rather than silently decoding', () => {
    const key = nacl.randomBytes(32);
    const a = new SecretStream(secretboxCipher, key);
    const b = new SecretStream(secretboxCipher, key);
    const one = a.encrypt(utf8('one'));
    const two = a.encrypt(utf8('two'));
    assert.throws(() => b.decrypt(two), /authentication failed/, 'nonce 2 cannot open at counter 1');
    assert.ok(one.length > 0);
});

test('sealSessionKey rejects a malformed peer key', () => {
    const ours = generateBoxKeyPair();
    assert.throws(() => sealSessionKey(nacl.randomBytes(32), new Uint8Array(16), ours.secretKey));
});
