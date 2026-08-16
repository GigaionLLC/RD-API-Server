/**
 * Adapter binding the vendored NaCl implementation to the interfaces the rest of the
 * client consumes.
 *
 * tweetnacl is used because its API is a 1:1 match for the primitives RustDesk speaks:
 * `sign.open` is combined-mode Ed25519 verification (signature prepended to the
 * message), `box` is crypto_box_easy, and `secretbox` is crypto_secretbox_easy — the
 * same three calls sodiumoxide makes on the peer side. The alternative considered
 * (@noble) has secretbox and x25519 but no crypto_box, which would have meant composing
 * the box construction from hsalsa + x25519 by hand. That is not a thing to hand-roll.
 *
 * If per-frame secretbox ever shows up in a profile, only `secretboxCipher` below needs
 * to change — SecretStream takes the cipher by injection, and `box`/`sign` run once per
 * session so they are irrelevant to throughput.
 */

import nacl from '../../vendor/tweetnacl/nacl.js';

/**
 * Wire the PRNG explicitly instead of relying on tweetnacl's environment sniffing.
 *
 * Its auto-detect looks for `self.crypto` or CommonJS `require`, and finds neither in an
 * ES module under Node — the failure mode is a "no PRNG" throw at first key generation.
 * WebCrypto's getRandomValues exists in both browsers (secure context) and Node 18+, so
 * binding it here makes key generation behave identically in tests and in production.
 */
nacl.setPRNG((x, n) => {
    const bytes = new Uint8Array(n);
    crypto.getRandomValues(bytes);
    x.set(bytes);
    bytes.fill(0);
});

export const BOX_PUBLIC_KEY_BYTES = 32;
export const BOX_SECRET_KEY_BYTES = 32;
export const SIGN_PUBLIC_KEY_BYTES = 32;
export const SESSION_KEY_BYTES = 32;
export const SEALED_SESSION_KEY_BYTES = 48; // 32-byte key + 16-byte MAC

/** @type {import('./stream.js').SecretboxCipher} */
export const secretboxCipher = {
    seal: (plain, nonce, key) => nacl.secretbox(plain, nonce, key),
    open: (boxed, nonce, key) => nacl.secretbox.open(boxed, nonce, key),
};

/**
 * Combined-mode Ed25519 verification: the blob is `signature(64) || message`, and the
 * message is returned only if the signature checks out.
 * @param {Uint8Array} signedBlob
 * @param {Uint8Array} publicKey 32-byte Ed25519 key
 * @returns {Uint8Array | null} The message, or null if verification fails.
 */
export function signOpen(signedBlob, publicKey) {
    if (publicKey.length !== SIGN_PUBLIC_KEY_BYTES) return null;
    if (signedBlob.length <= nacl.sign.signatureLength) return null;
    return nacl.sign.open(signedBlob, publicKey);
}

/** @returns {{publicKey: Uint8Array, secretKey: Uint8Array}} A fresh per-connection X25519 keypair. */
export function generateBoxKeyPair() {
    return nacl.box.keyPair();
}

/** @param {number} n @returns {Uint8Array} */
export function randomBytes(n) {
    return nacl.randomBytes(n);
}

/**
 * Seals the session key to the peer's ephemeral X25519 key.
 *
 * The nonce is all zeros. That is safe here — and only here — because our box keypair is
 * generated fresh for every connection, so the (key, nonce) pair is never reused.
 *
 * @param {Uint8Array} sessionKey 32 bytes
 * @param {Uint8Array} theirBoxPk 32 bytes
 * @param {Uint8Array} ourBoxSk 32 bytes
 * @returns {Uint8Array} 48 bytes
 */
export function sealSessionKey(sessionKey, theirBoxPk, ourBoxSk) {
    const zeroNonce = new Uint8Array(nacl.box.nonceLength);
    const sealed = nacl.box(sessionKey, zeroNonce, theirBoxPk, ourBoxSk);
    if (sealed.length !== SEALED_SESSION_KEY_BYTES) {
        throw new Error(`sealed session key is ${sealed.length} bytes, expected ${SEALED_SESSION_KEY_BYTES}`);
    }
    return sealed;
}
