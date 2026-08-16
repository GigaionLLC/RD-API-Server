/**
 * Full session bring-up against a live peer: rendezvous → relay → NaCl handshake →
 * login → PeerInfo → first video frame.
 *
 * This is the Phase 1 + Phase 2 proof. It sends NO input events — it connects, decodes,
 * reports, and disconnects. Driving input would move the remote mouse and keyboard,
 * which matters when the peer is the machine you are developing on.
 *
 *   node tools/integration/login.mjs --host <id-server> --peer <id> --key <b64> --password <pw>
 *
 * Nothing about the environment is committed; every value is a runtime argument.
 *
 * Expect a prompt on the peer if its approve-mode requires one.
 */

import { encode, decode } from '../../src/protocol/codec.js';
import { RendezvousMessage } from '../../src/protocol/rendezvous.js';
import { Message, CODEC_BY_FIELD } from '../../src/protocol/message.js';
import { NatType, ConnType, Permission } from '../../src/protocol/enums.js';
import { negotiate, decodeBase64 } from '../../src/crypto/handshake.js';
import { derivePassword } from '../../src/crypto/password.js';
import { secretboxCipher } from '../../src/crypto/cipher.js';
import { SecretStream } from '../../src/crypto/stream.js';

const OUR_VERSION = '1.4.8';
const STEP_TIMEOUT_MS = 20_000;

function parseArgs(argv) {
    const out = { host: '', peer: '', key: '', password: '', port: 21116, relayPort: 21117 };
    for (let i = 0; i < argv.length; i += 2) {
        const k = argv[i]?.replace(/^--/, '');
        if (k && k in out) out[k] = k.endsWith('ort') ? Number(argv[i + 1]) : argv[i + 1];
    }
    if (!out.host || !out.peer) {
        console.error('usage: node tools/integration/login.mjs --host <id-server> --peer <id> [--key <b64>] [--password <pw>]');
        process.exit(2);
    }
    return out;
}

const args = parseArgs(process.argv.slice(2));

/** Opens a WebSocket and resolves once it is ready. */
function openSocket(url) {
    return new Promise((resolve, reject) => {
        // No subprotocol: the server never echoes Sec-WebSocket-Protocol, and requesting
        // one fails the handshake in a browser.
        const ws = new WebSocket(url);
        ws.binaryType = 'arraybuffer';
        const t = setTimeout(() => reject(new Error(`timeout opening ${url}`)), STEP_TIMEOUT_MS);
        ws.addEventListener('open', () => { clearTimeout(t); resolve(ws); }, { once: true });
        ws.addEventListener('error', () => { clearTimeout(t); reject(new Error(`cannot open ${url}`)); }, { once: true });
    });
}

/** Queues inbound binary frames so callers can await them one at a time. */
function frameQueue(ws) {
    const pending = [];
    const waiters = [];
    let closed = null;

    ws.addEventListener('message', (ev) => {
        const bytes = new Uint8Array(ev.data);
        if (waiters.length) waiters.shift().resolve(bytes);
        else pending.push(bytes);
    });
    ws.addEventListener('close', () => {
        closed = new Error('socket closed by peer');
        while (waiters.length) waiters.shift().reject(closed);
    });

    return {
        next(label) {
            if (pending.length) return Promise.resolve(pending.shift());
            if (closed) return Promise.reject(closed);
            return new Promise((resolve, reject) => {
                const t = setTimeout(() => reject(new Error(`timeout waiting for ${label}`)), STEP_TIMEOUT_MS);
                waiters.push({
                    resolve: (v) => { clearTimeout(t); resolve(v); },
                    reject: (e) => { clearTimeout(t); reject(e); },
                });
            });
        },
    };
}

async function main() {
    /* ---- 1. Rendezvous ------------------------------------------------- */
    const idUrl = `ws://${args.host}:${args.port + 2}`;
    console.log(`[1] rendezvous  ${idUrl}`);
    const idWs = await openSocket(idUrl);
    const idQ = frameQueue(idWs);

    idWs.send(encode(RendezvousMessage, {
        punch_hole_request: {
            id: args.peer,
            nat_type: NatType.SYMMETRIC, // the actual relay trigger
            licence_key: args.key,
            conn_type: ConnType.DEFAULT_CONN,
            version: OUR_VERSION,
            force_relay: true,
        },
    }));

    const rdv = decode(RendezvousMessage, await idQ.next('RelayResponse'));
    if (rdv.$case !== 'relay_response') throw new Error(`expected relay_response, got ${rdv.$case}`);
    const rr = rdv.relay_response;
    if (rr.refuse_reason) throw new Error(`peer refused: ${rr.refuse_reason}`);
    idWs.close();
    console.log(`    uuid ${rr.uuid}  relay ${rr.relay_server}  peer v${rr.version}`);

    /* ---- 2. Relay ------------------------------------------------------ */
    const relayHost = (rr.relay_server || args.host).split(':')[0];
    const relayUrl = `ws://${relayHost}:${args.relayPort + 2}`;
    console.log(`[2] relay       ${relayUrl}`);
    const ws = await openSocket(relayUrl);
    const q = frameQueue(ws);

    ws.send(encode(RendezvousMessage, {
        request_relay: {
            id: args.peer,
            uuid: rr.uuid,          // the pairing token, byte-for-byte
            licence_key: args.key,
            conn_type: ConnType.DEFAULT_CONN,
        },
    }));
    console.log('    paired, awaiting SignedId');

    /* ---- 3. Handshake -------------------------------------------------- */
    // Still plaintext at this point: the stream only becomes secretbox after we answer
    // with PublicKey, so these first two frames must not touch the counters.
    const first = decode(Message, await q.next('SignedId'));
    if (first.$case !== 'signed_id') throw new Error(`expected signed_id, got ${first.$case}`);

    const hs = negotiate({
        signedIdPk: rr.pk,
        serverPk: args.key ? decodeBase64(args.key) : new Uint8Array(0),
        peerSignedId: first.signed_id.id,
        peerId: args.peer,
    });

    if (hs.downgradeReason) {
        console.log(`[3] handshake   UNENCRYPTED — ${hs.downgradeReason}`);
        ws.send(encode(Message, { public_key: { asymmetric_value: new Uint8Array(0), symmetric_value: new Uint8Array(0) } }));
    } else {
        console.log('[3] handshake   verified both signatures, sealing session key');
        ws.send(encode(Message, { public_key: hs.publicKeyMessage }));
    }
    const stream = new SecretStream(secretboxCipher, hs.sessionKey);

    /* ---- 4. Login ------------------------------------------------------ */
    const send = (obj) => ws.send(stream.encrypt(encode(Message, obj)));
    const recv = async (label) => decode(Message, stream.decrypt(await q.next(label)));

    let msg = await recv('Hash');
    // TestDelay can arrive at any time and must be echoed verbatim; a late or altered
    // reply clamps the host to 2 fps.
    while (msg.$case === 'test_delay') {
        send({ test_delay: msg.test_delay });
        msg = await recv('Hash');
    }
    if (msg.$case !== 'hash') throw new Error(`expected hash, got ${msg.$case}`);
    const { salt, challenge } = msg.hash;
    console.log(`[4] login       salt ${salt.length} chars, challenge ${challenge.length} chars`);

    send({
        login_request: {
            username: args.peer,     // the PEER's id, not a user name
            password: args.password ? await derivePassword(args.password, salt, challenge) : new Uint8Array(0),
            my_id: 'web-client-dev',
            my_name: 'web-client integration',
            my_platform: 'Web',
            version: OUR_VERSION,
            video_ack_required: true,
            session_id: BigInt(Date.now()) * 1000n + 7n,
            option: {
                supported_decoding: {
                    ability_vp8: 1, ability_vp9: 1, ability_av1: 1, ability_h264: 1, ability_h265: 0,
                },
            },
        },
    });

    /* ---- 5. PeerInfo and first frame ----------------------------------- */
    // Permissions are signalled negatively: absent means granted.
    const denied = [];
    const deadline = Date.now() + 45_000;
    let info = null;

    while (Date.now() < deadline) {
        const m = await recv('LoginResponse');

        if (m.$case === 'test_delay') { send({ test_delay: m.test_delay }); continue; }

        if (m.$case === 'login_response') {
            if (m.login_response.error) {
                console.error(`    ✗ ${m.login_response.error}`);
                if (/Password/i.test(m.login_response.error)) console.error('      (check --password)');
                if (/No Password Access/i.test(m.login_response.error)) console.error('      (peer is in click-to-accept mode — approve on screen)');
                process.exit(1);
            }
            info = m.login_response.peer_info;
            console.log('[5] PeerInfo    ✓ logged in');
            console.log(`    ${info.username}@${info.hostname}  ${info.platform} v${info.version}`);
            console.log(`    displays: ${info.displays.length}, current ${info.current_display ?? 0}`);
            info.displays.forEach((d, i) => {
                console.log(`      [${i}] ${d.width}x${d.height} at (${d.x ?? 0},${d.y ?? 0})` +
                    `${d.name ? ` "${d.name}"` : ''}${d.cursor_embedded ? ' cursor-embedded' : ''}`);
            });
            continue;
        }

        if (m.$case === 'misc') {
            const misc = m.misc;
            if (misc.$case === 'permission_info') {
                if (!misc.permission_info.enabled) {
                    const name = Object.keys(Permission).find((k) => Permission[k] === (misc.permission_info.permission ?? 0));
                    denied.push(name);
                }
            } else if (misc.$case === 'close_reason') {
                console.error(`    ✗ peer closed: ${misc.close_reason}`);
                process.exit(1);
            }
            continue;
        }

        if (m.$case === 'video_frame') {
            // ACK first, always — the host will not capture the next frame until it
            // arrives, so any work done before this is added to every frame interval.
            send({ misc: { video_received: true } });

            const codecField = m.video_frame.$case;
            const frames = m.video_frame[codecField]?.frames ?? [];
            const bytes = frames.reduce((n, f) => n + f.data.length, 0);
            const hasKey = frames.some((f) => f.key);
            console.log('[6] video       ✓ first frame received');
            console.log(`    codec ${CODEC_BY_FIELD[codecField]} (field ${codecField}), display ${m.video_frame.display ?? 0}`);
            // pts is int64 and the first frame's is 0, which proto3 omits — so an absent
            // field here means 0, not "missing".
            console.log(`    ${frames.length} access unit(s), ${bytes} bytes, key=${hasKey}, pts=${frames[0]?.pts ?? 0n}`);
            console.log(`    permissions denied by peer: ${denied.length ? denied.join(', ') : 'none'}`);
            console.log('\n✓ end-to-end: rendezvous → relay → handshake → login → video');
            ws.close();
            process.exit(0);
        }
    }

    if (!info) throw new Error('no LoginResponse before deadline');
    console.error('✗ logged in but no video frame arrived');
    process.exit(1);
}

main().catch((err) => {
    console.error(`✗ ${err.message}`);
    process.exit(1);
});
