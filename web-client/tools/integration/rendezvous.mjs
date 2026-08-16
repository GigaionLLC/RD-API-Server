/**
 * Live rendezvous check against a real hbbs.
 *
 * Validates the WebSocket transport assumption and the codec against an actual server:
 * opens ws://<host>:21118, sends one PunchHoleRequest, and decodes whatever comes back.
 * No cryptography is involved, so this runs before the handshake exists.
 *
 * This is NOT a unit test — it needs the network and a live peer, so it is not picked up
 * by `node --test`. Run it explicitly:
 *
 *   node tools/integration/rendezvous.mjs --host <id-server> --peer <peer-id> [--key <b64>]
 *
 * Nothing here is hardcoded: no host, peer id, or server key is committed to the repo.
 *
 * What a pass proves:
 *   - stock hbbs accepts a browser-shaped WebSocket connection on PORT+2
 *   - one binary frame carries exactly one RendezvousMessage, with no length prefix
 *   - our encoder produces a PunchHoleRequest the server understands
 *   - our decoder reads the server's reply
 *   - nat_type=SYMMETRIC drives the peer down the relay path
 */

import { encode, decode } from '../../src/protocol/codec.js';
import { RendezvousMessage } from '../../src/protocol/rendezvous.js';
import { NatType, ConnType, PunchHoleFailure } from '../../src/protocol/enums.js';

const RENDEZVOUS_WS_OFFSET = 2; // 21116 -> 21118
const DEFAULT_PORT = 21116;
const REPLY_TIMEOUT_MS = 12_000;

/** @param {string[]} argv */
function parseArgs(argv) {
    const out = { host: '', peer: '', key: '', port: DEFAULT_PORT, version: '1.4.8' };
    for (let i = 0; i < argv.length; i += 2) {
        const k = argv[i]?.replace(/^--/, '');
        if (k && k in out) out[k] = k === 'port' ? Number(argv[i + 1]) : argv[i + 1];
    }
    if (!out.host || !out.peer) {
        console.error('usage: node tools/integration/rendezvous.mjs --host <id-server> --peer <peer-id> [--key <b64>] [--port 21116]');
        process.exit(2);
    }
    return out;
}

const args = parseArgs(process.argv.slice(2));
const wsPort = args.port + RENDEZVOUS_WS_OFFSET;
const url = `ws://${args.host}:${wsPort}`;

console.log(`→ ${url}  (peer ${args.peer}, key ${args.key ? 'set' : 'none'})`);

// The server never echoes Sec-WebSocket-Protocol, so requesting a subprotocol would fail
// the handshake in a browser. Match that constraint here.
const ws = new WebSocket(url);
ws.binaryType = 'arraybuffer';

const timer = setTimeout(() => {
    console.error(`✗ no reply within ${REPLY_TIMEOUT_MS}ms`);
    ws.close();
    process.exit(1);
}, REPLY_TIMEOUT_MS);

ws.addEventListener('open', () => {
    const frame = encode(RendezvousMessage, {
        punch_hole_request: {
            id: args.peer,
            // SYMMETRIC is the real relay trigger: OSS hbbs drops force_relay but does
            // forward nat_type into the PunchHole the peer receives.
            nat_type: NatType.SYMMETRIC,
            licence_key: args.key,
            conn_type: ConnType.DEFAULT_CONN,
            version: args.version,
            force_relay: true,
        },
    });
    console.log(`  socket open, sending PunchHoleRequest (${frame.length} bytes)`);
    ws.send(frame);
});

ws.addEventListener('message', (ev) => {
    clearTimeout(timer);
    const bytes = new Uint8Array(ev.data);
    console.log(`← ${bytes.length} bytes in one binary frame`);

    let msg;
    try {
        msg = decode(RendezvousMessage, bytes);
    } catch (err) {
        console.error(`✗ decode failed: ${err.message}`);
        process.exit(1);
    }

    console.log(`  member: ${msg.$case}`);

    if (msg.$case === 'relay_response') {
        const rr = msg.relay_response;
        if (rr.refuse_reason) {
            console.error(`✗ peer refused: ${rr.refuse_reason}`);
            process.exit(1);
        }
        console.log('✓ RelayResponse — the peer agreed to relay');
        console.log(`    uuid          ${rr.uuid}`);
        console.log(`    relay_server  ${rr.relay_server || '(empty — we must supply it)'}`);
        console.log(`    peer version  ${rr.version || '(unset)'}`);
        console.log(`    signed pk     ${rr.pk?.length ?? 0} bytes`);
        console.log('\nNext leg: connect ws://<relay>:21119 and send RequestRelay with this uuid.');
        process.exit(0);
    }

    if (msg.$case === 'punch_hole_response') {
        const ph = msg.punch_hole_response;
        // Empty socket_addr is the failure signal; `failure` defaults to ID_NOT_EXIST=0.
        if (ph.socket_addr.length === 0) {
            const name = Object.keys(PunchHoleFailure).find((k) => PunchHoleFailure[k] === (ph.failure ?? 0));
            console.error(`✗ failure: ${ph.other_failure || name}`);
            process.exit(1);
        }
        console.log('✓ PunchHoleResponse — peer is online and reachable');
        console.log(`    socket_addr   ${ph.socket_addr.length} bytes (mangled)`);
        console.log(`    relay_server  ${ph.relay_server || '(none)'}`);
        console.log(`    signed pk     ${ph.pk?.length ?? 0} bytes`);
        console.log('\nNote: a direct-connect address came back rather than a relay offer.');
        process.exit(0);
    }

    console.error(`✗ unexpected member: ${msg.$case}`);
    process.exit(1);
});

ws.addEventListener('error', () => {
    clearTimeout(timer);
    console.error(`✗ websocket error connecting to ${url}`);
    process.exit(1);
});

ws.addEventListener('close', (ev) => {
    // hbbs closes after one request/response, so a close after a reply is expected.
    if (ev.code !== 1000 && ev.code !== 1005) console.log(`  socket closed (${ev.code})`);
});
