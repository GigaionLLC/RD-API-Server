/**
 * The two-step Ed25519 verification chain and session-key exchange.
 *
 * Spec: docs/spec/03-crypto-login.md.
 *
 * The chain exists to stop the rendezvous server impersonating a peer. Note the double
 * indirection, which is easy to get wrong:
 *
 *   1. hbbs hands us `signed_id_pk`, signed by the SERVER key. Inside is an IdPk whose
 *      `pk` is the peer's LONG-TERM Ed25519 identity key.
 *   2. The peer then hands us a `SignedId`, signed by that identity key. Inside is a
 *      second IdPk whose `pk` is the peer's EPHEMERAL X25519 key for this connection.
 *
 * Both blobs carry the peer id, and both must match the id we asked for — otherwise a
 * server could splice in another peer's material.
 *
 * Every failure here degrades to an unencrypted session rather than aborting, because a
 * peer with no registered key is a normal situation the protocol allows. Callers get the
 * reason so a policy that fails closed can be layered on top; see
 * `HandshakeResult.downgradeReason`.
 */

import { decode } from '../protocol/codec.js';
import { IdPk } from '../protocol/message.js';
import {
    signOpen, generateBoxKeyPair, randomBytes, sealSessionKey,
    SESSION_KEY_BYTES, BOX_PUBLIC_KEY_BYTES, SIGN_PUBLIC_KEY_BYTES,
} from './cipher.js';

/**
 * @typedef {object} HandshakeResult
 * @property {Uint8Array | null} sessionKey 32 bytes, or null for an unencrypted session.
 * @property {Uint8Array | null} publicKeyMessage `PublicKey` fields to send, or null.
 * @property {Uint8Array | null} peerSignPk The peer's verified long-term Ed25519 key.
 * @property {string | null} downgradeReason Why encryption was skipped, if it was.
 */

/** @param {string} b64 @returns {Uint8Array} */
export function decodeBase64(b64) {
    const bin = atob(b64);
    const out = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
    return out;
}

/**
 * Step 1 — verify what the rendezvous server told us about the peer's identity key.
 *
 * @param {Uint8Array} signedIdPk `RelayResponse.pk` / `PunchHoleResponse.pk`.
 * @param {Uint8Array} serverPk 32-byte Ed25519 key from the client config.
 * @param {string} expectedPeerId
 * @returns {{ok: true, peerSignPk: Uint8Array} | {ok: false, reason: string}}
 */
export function verifyServerAssertion(signedIdPk, serverPk, expectedPeerId) {
    if (!signedIdPk || signedIdPk.length === 0) {
        return { ok: false, reason: 'no signed public key from the rendezvous server' };
    }
    if (!serverPk || serverPk.length !== SIGN_PUBLIC_KEY_BYTES) {
        return { ok: false, reason: 'no usable server public key configured' };
    }

    const plain = signOpen(signedIdPk, serverPk);
    if (plain === null) return { ok: false, reason: 'signature mismatch on the server assertion' };

    let idPk;
    try {
        idPk = decode(IdPk, plain);
    } catch {
        return { ok: false, reason: 'server assertion is not a valid IdPk' };
    }

    if (!idPk.pk || idPk.pk.length !== SIGN_PUBLIC_KEY_BYTES) {
        return { ok: false, reason: `peer identity key is ${idPk.pk?.length ?? 0} bytes, expected 32` };
    }
    if (idPk.id !== expectedPeerId) {
        return { ok: false, reason: `server asserted id "${idPk.id}" but we asked for "${expectedPeerId}"` };
    }

    return { ok: true, peerSignPk: idPk.pk };
}

/**
 * Step 2 — verify the peer's own SignedId and extract its ephemeral X25519 key.
 *
 * @param {Uint8Array} signedIdBlob `SignedId.id` from the peer.
 * @param {Uint8Array} peerSignPk From step 1.
 * @param {string} expectedPeerId
 * @returns {{ok: true, theirBoxPk: Uint8Array} | {ok: false, reason: string}}
 */
export function verifyPeerSignedId(signedIdBlob, peerSignPk, expectedPeerId) {
    const plain = signOpen(signedIdBlob, peerSignPk);
    if (plain === null) return { ok: false, reason: 'peer signature does not match its asserted identity key' };

    let idPk;
    try {
        idPk = decode(IdPk, plain);
    } catch {
        return { ok: false, reason: 'peer SignedId is not a valid IdPk' };
    }

    if (!idPk.pk || idPk.pk.length !== BOX_PUBLIC_KEY_BYTES) {
        return { ok: false, reason: `peer ephemeral key is ${idPk.pk?.length ?? 0} bytes, expected 32` };
    }
    if (idPk.id !== expectedPeerId) {
        return { ok: false, reason: `peer signed id "${idPk.id}" but we asked for "${expectedPeerId}"` };
    }

    return { ok: true, theirBoxPk: idPk.pk };
}

/**
 * Step 3 — mint a session key and seal it to the peer.
 *
 * @param {Uint8Array} theirBoxPk From step 2.
 * @returns {{sessionKey: Uint8Array, asymmetricValue: Uint8Array, symmetricValue: Uint8Array}}
 */
export function createSessionKey(theirBoxPk) {
    const ours = generateBoxKeyPair();
    const sessionKey = randomBytes(SESSION_KEY_BYTES);
    const sealed = sealSessionKey(sessionKey, theirBoxPk, ours.secretKey);
    return {
        sessionKey,
        asymmetricValue: ours.publicKey,
        symmetricValue: sealed,
    };
}

/**
 * Runs steps 1-3 end to end.
 *
 * @param {object} opts
 * @param {Uint8Array} opts.signedIdPk
 * @param {Uint8Array} opts.serverPk
 * @param {Uint8Array} opts.peerSignedId
 * @param {string} opts.peerId
 * @returns {HandshakeResult}
 */
export function negotiate({ signedIdPk, serverPk, peerSignedId, peerId }) {
    const assertion = verifyServerAssertion(signedIdPk, serverPk, peerId);
    if (!assertion.ok) {
        return { sessionKey: null, publicKeyMessage: null, peerSignPk: null, downgradeReason: assertion.reason };
    }

    const signed = verifyPeerSignedId(peerSignedId, assertion.peerSignPk, peerId);
    if (!signed.ok) {
        return { sessionKey: null, publicKeyMessage: null, peerSignPk: assertion.peerSignPk, downgradeReason: signed.reason };
    }

    const { sessionKey, asymmetricValue, symmetricValue } = createSessionKey(signed.theirBoxPk);
    return {
        sessionKey,
        publicKeyMessage: { asymmetric_value: asymmetricValue, symmetric_value: symmetricValue },
        peerSignPk: assertion.peerSignPk,
        downgradeReason: null,
    };
}
