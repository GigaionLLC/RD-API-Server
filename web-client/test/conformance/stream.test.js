/**
 * Secretbox stream conformance — PLAN.md §6 cases 8 and 9.
 *
 * These are the rules that desynchronise a session silently and irrecoverably, so they
 * are tested against a fake cipher that records the exact nonce it was handed. The real
 * cipher is vendored and audited; what is ours to get right is the counter discipline.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { SecretStream, nonceFor, isPassthrough, NONCE_BYTES } from '../../src/crypto/stream.js';

/** Records nonces and round-trips payloads without real crypto. */
function fakeCipher() {
    const sealNonces = [];
    const openNonces = [];
    return {
        sealNonces,
        openNonces,
        seal(plain, nonce) {
            sealNonces.push(Array.from(nonce));
            const out = new Uint8Array(plain.length + 1);
            out[0] = 0xaa; // stand-in for the MAC
            out.set(plain, 1);
            return out;
        },
        open(boxed, nonce) {
            openNonces.push(Array.from(nonce));
            if (boxed[0] !== 0xaa) return null;
            return boxed.subarray(1);
        },
    };
}

const KEY = new Uint8Array(32).fill(3);

/** @param {number[]} nonce */
function counterOf(nonce) {
    let v = 0n;
    for (let i = 7; i >= 0; i--) v = (v << 8n) | BigInt(nonce[i]);
    return v;
}

test('nonceFor builds 24 bytes with a little-endian counter in the first 8', () => {
    const n = nonceFor(1n);
    assert.equal(n.length, NONCE_BYTES);
    assert.equal(n[0], 1);
    assert.deepEqual(Array.from(n.subarray(1)), new Array(23).fill(0));

    const n258 = nonceFor(258n); // 0x0102
    assert.equal(n258[0], 0x02);
    assert.equal(n258[1], 0x01);
    assert.deepEqual(Array.from(n258.subarray(2)), new Array(22).fill(0));
});

test('nonceFor rejects counter 0 — counters are pre-incremented', () => {
    assert.throws(() => nonceFor(0n), RangeError);
});

test('the first message in each direction uses counter 1, not 0', () => {
    const cipher = fakeCipher();
    const s = new SecretStream(cipher, KEY);

    s.encrypt(new Uint8Array([1, 2, 3]));
    assert.equal(counterOf(cipher.sealNonces[0]), 1n, 'first send must use nonce 1');

    s.decrypt(new Uint8Array([0xaa, 9, 9]));
    assert.equal(counterOf(cipher.openNonces[0]), 1n, 'first receive must use nonce 1');
});

test('send and receive counters are independent', () => {
    const cipher = fakeCipher();
    const s = new SecretStream(cipher, KEY);

    s.encrypt(new Uint8Array([1]));
    s.encrypt(new Uint8Array([2]));
    s.encrypt(new Uint8Array([3]));
    s.decrypt(new Uint8Array([0xaa, 1]));

    assert.deepEqual(cipher.sealNonces.map(counterOf), [1n, 2n, 3n]);
    assert.deepEqual(cipher.openNonces.map(counterOf), [1n], 'receive must not follow send');
});

test('payloads of length <= 1 bypass the cipher and do NOT advance the receive counter', () => {
    // This is how the zero-byte heartbeat works. Counting it desynchronises every
    // subsequent frame — the failure appears later and looks like a corrupt stream.
    const cipher = fakeCipher();
    const s = new SecretStream(cipher, KEY);

    assert.ok(isPassthrough(new Uint8Array(0)));
    assert.ok(isPassthrough(new Uint8Array([7])));
    assert.ok(!isPassthrough(new Uint8Array([7, 8])));

    s.decrypt(new Uint8Array([0xaa, 1]));      // counter 1
    const beat = s.decrypt(new Uint8Array(0)); // heartbeat — must not count
    assert.equal(beat.length, 0);
    const single = s.decrypt(new Uint8Array([5])); // 1 byte — must not count
    assert.deepEqual(Array.from(single), [5]);
    s.decrypt(new Uint8Array([0xaa, 2]));      // counter 2, not 4

    assert.deepEqual(cipher.openNonces.map(counterOf), [1n, 2n]);
    assert.equal(cipher.openNonces.length, 2, 'heartbeats must never reach the cipher');
});

test('a heartbeat is a zero-length payload', () => {
    const s = new SecretStream(fakeCipher(), KEY);
    assert.equal(s.heartbeat().length, 0);
    assert.ok(isPassthrough(s.heartbeat()));
});

test('round-trip through two paired streams stays in lockstep', () => {
    const a = new SecretStream(fakeCipher(), KEY);
    const b = new SecretStream(fakeCipher(), KEY);
    for (let i = 0; i < 5; i++) {
        const plain = new Uint8Array([i, i + 1, i + 2]);
        const out = b.decrypt(a.encrypt(plain));
        assert.deepEqual(Array.from(out), Array.from(plain), `message ${i}`);
    }
    assert.equal(a.sendCounter, 5n);
    assert.equal(b.recvCounter, 5n);
});

test('authentication failure is fatal and names the counter', () => {
    const s = new SecretStream(fakeCipher(), KEY);
    assert.throws(
        () => s.decrypt(new Uint8Array([0x00, 1, 2])), // bad MAC stand-in
        /authentication failed at receive counter 1/,
    );
});

test('an unencrypted session passes everything through untouched', () => {
    const cipher = fakeCipher();
    const s = new SecretStream(cipher, null);
    assert.equal(s.enabled, false);
    const plain = new Uint8Array([1, 2, 3]);
    assert.deepEqual(Array.from(s.encrypt(plain)), [1, 2, 3]);
    assert.deepEqual(Array.from(s.decrypt(plain)), [1, 2, 3]);
    assert.equal(cipher.sealNonces.length, 0, 'the cipher must not be touched at all');
});

test('a wrong-sized session key is rejected at construction', () => {
    assert.throws(() => new SecretStream(fakeCipher(), new Uint8Array(16)), RangeError);
});
