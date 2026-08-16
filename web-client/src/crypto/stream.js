/**
 * Secretbox stream framing: nonce construction and the send/receive counters.
 *
 * Spec: docs/spec/03-crypto-login.md; summarised in 06-schema.md §1.5.
 *
 * The cipher itself is injected rather than imported, so this module — which holds all
 * the state that can desynchronise a session — is testable without the vendored crypto
 * bundle, and so the audited implementation stays at the edge of the system.
 *
 * Three rules, each of which silently breaks the session if missed:
 *
 *   1. Counters are PRE-incremented. The first message in each direction uses nonce
 *      counter 1, not 0.
 *   2. Send and receive counters are independent.
 *   3. Payloads of length <= 1 bypass the cipher entirely AND do not advance the
 *      receive counter. That is how the zero-byte heartbeat is carried; treating one as
 *      ciphertext desynchronises everything after it.
 *
 * There is no rekeying and no nonce on the wire, so a single dropped or reordered
 * payload is unrecoverable. Callers must not retry or reorder.
 */

export const NONCE_BYTES = 24;
export const KEY_BYTES = 32;

/**
 * Builds the 24-byte nonce for a counter value: 8 bytes little-endian, zero-padded.
 * @param {bigint} counter
 * @returns {Uint8Array}
 */
export function nonceFor(counter) {
    if (counter <= 0n) throw new RangeError('nonce counter is pre-incremented and starts at 1');
    const nonce = new Uint8Array(NONCE_BYTES);
    let v = BigInt.asUintN(64, counter);
    for (let i = 0; i < 8; i++) {
        nonce[i] = Number(v & 0xffn);
        v >>= 8n;
    }
    return nonce;
}

/**
 * A payload short enough to bypass the cipher. A zero-length frame is the protocol's
 * heartbeat, and anything of length <= 1 passes through untouched without advancing the
 * receive counter.
 * @param {Uint8Array} payload
 */
export function isPassthrough(payload) {
    return payload.length <= 1;
}

/**
 * @typedef {object} SecretboxCipher
 * @property {(plain: Uint8Array, nonce: Uint8Array, key: Uint8Array) => Uint8Array} seal
 * @property {(boxed: Uint8Array, nonce: Uint8Array, key: Uint8Array) => Uint8Array | null} open
 */

/**
 * Bidirectional secretbox stream over one relay connection.
 *
 * Construct only after the PublicKey message has been sent; everything before that is
 * plaintext. `enabled` is false for sessions that fell back to unencrypted, so callers
 * can use one code path either way.
 */
export class SecretStream {
    /**
     * @param {SecretboxCipher} cipher
     * @param {Uint8Array | null} key 32-byte session key, or null for an unencrypted session.
     */
    constructor(cipher, key) {
        if (key !== null && key.length !== KEY_BYTES) {
            throw new RangeError(`session key must be ${KEY_BYTES} bytes, got ${key.length}`);
        }
        this.cipher = cipher;
        this.key = key;
        this.sendCounter = 0n;
        this.recvCounter = 0n;
    }

    get enabled() {
        return this.key !== null;
    }

    /**
     * @param {Uint8Array} plain
     * @returns {Uint8Array} The payload to put on the wire.
     */
    encrypt(plain) {
        if (!this.enabled) return plain;
        // Passthrough is a receive-side rule only: we never originate a <=1 byte payload
        // except a deliberate heartbeat, which callers send via `heartbeat()`.
        this.sendCounter += 1n;
        return this.cipher.seal(plain, nonceFor(this.sendCounter), /** @type {Uint8Array} */(this.key));
    }

    /**
     * @param {Uint8Array} payload As received from the wire.
     * @returns {Uint8Array} The plaintext.
     * @throws {Error} On authentication failure — fatal, the stream cannot resynchronise.
     */
    decrypt(payload) {
        if (!this.enabled) return payload;
        if (isPassthrough(payload)) return payload; // counter deliberately not advanced
        this.recvCounter += 1n;
        const plain = this.cipher.open(payload, nonceFor(this.recvCounter), /** @type {Uint8Array} */(this.key));
        if (plain === null) {
            throw new Error(
                `secretbox authentication failed at receive counter ${this.recvCounter}; ` +
                'the stream is desynchronised and cannot recover',
            );
        }
        return plain;
    }

    /** @returns {Uint8Array} The zero-length heartbeat payload, which bypasses the cipher. */
    // eslint-disable-next-line class-methods-use-this
    heartbeat() {
        return new Uint8Array(0);
    }
}
