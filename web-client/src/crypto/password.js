/**
 * Connection password derivation.
 *
 * Spec: docs/spec/03-crypto-login.md.
 *
 *   h1 = SHA256( utf8(password) || utf8(salt) )
 *   h2 = SHA256( h1            || utf8(challenge) )
 *
 * Raw byte concatenation, no separator, no length prefix, one round each. `salt` is the
 * peer's persistent per-device value; `challenge` is regenerated every connection, which
 * is why `h1` may be cached across sessions but `h2` never may.
 *
 * SHA-256 comes from WebCrypto, which is part of why this client requires a secure
 * context — `crypto.subtle` is unavailable on plain HTTP. That is a feature rather than a
 * limitation: the alternative is hand-writing a hash function, and a padding bug there
 * fails silently for only some input lengths, which is close to undiagnosable in the
 * field.
 */

const encoder = new TextEncoder();

/**
 * @param {...Uint8Array} parts
 * @returns {Uint8Array}
 */
function concat(...parts) {
    const total = parts.reduce((n, p) => n + p.length, 0);
    const out = new Uint8Array(total);
    let at = 0;
    for (const p of parts) {
        out.set(p, at);
        at += p.length;
    }
    return out;
}

/**
 * @param {Uint8Array} bytes
 * @returns {Promise<Uint8Array>}
 */
async function sha256(bytes) {
    const digest = await crypto.subtle.digest('SHA-256', bytes);
    return new Uint8Array(digest);
}

/**
 * The stored form of a password. RustDesk persists this, never the plaintext, so a
 * client that remembers credentials should remember `h1` and discard what the user typed.
 * @param {string} password
 * @param {string} salt From the peer's Hash message. May be empty.
 * @returns {Promise<Uint8Array>} 32 bytes
 */
export async function deriveH1(password, salt) {
    return sha256(concat(encoder.encode(password), encoder.encode(salt)));
}

/**
 * The per-connection proof sent as `LoginRequest.password`.
 * @param {Uint8Array} h1 32 bytes
 * @param {string} challenge From the peer's Hash message, fresh each connection.
 * @returns {Promise<Uint8Array>} 32 bytes
 */
export async function deriveH2(h1, challenge) {
    return sha256(concat(h1, encoder.encode(challenge)));
}

/**
 * Convenience for the common path: plaintext straight to the wire value.
 * @param {string} password
 * @param {string} salt
 * @param {string} challenge
 * @returns {Promise<Uint8Array>} 32 bytes for `LoginRequest.password`
 */
export async function derivePassword(password, salt, challenge) {
    return deriveH2(await deriveH1(password, salt), challenge);
}
