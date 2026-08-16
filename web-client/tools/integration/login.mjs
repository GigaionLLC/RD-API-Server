/**
 * Full session bring-up against a live peer, driven entirely through the library —
 * rendezvous → relay → NaCl handshake → login → PeerInfo → first video frames.
 *
 * Doubles as the acceptance test for src/session/machine.js: if this reports the same
 * result as the hand-rolled version it replaced, the refactor preserved behaviour.
 *
 * Sends NO input events. Driving input would move the remote mouse and keyboard, which
 * matters when the peer is the machine you are developing on.
 *
 *   node tools/integration/login.mjs --host <id-server> --peer <id> --key <b64> --password <pw>
 *
 * Nothing about the environment is committed; every value is a runtime argument.
 * Expect a prompt on the peer if its approve-mode requires one.
 */

import { RustDeskSession } from '../../src/session/machine.js';
import { CodecCapabilities } from '../../src/media/codec.js';

function parseArgs(argv) {
    const out = { host: '', peer: '', key: '', password: '', frames: 5, secure: '' };
    for (let i = 0; i < argv.length; i += 2) {
        const k = argv[i]?.replace(/^--/, '');
        if (k && k in out) out[k] = k === 'frames' ? Number(argv[i + 1]) : argv[i + 1];
    }
    if (!out.host || !out.peer) {
        console.error('usage: node tools/integration/login.mjs --host <id-server> --peer <id> [--key <b64>] [--password <pw>] [--frames N]');
        process.exit(2);
    }
    return out;
}

const args = parseArgs(process.argv.slice(2));

// Node has no WebCodecs, so claim what a Chromium target would and let the peer pick.
// The point here is to exercise negotiation, not to decode.
const codecs = new CodecCapabilities(['vp8', 'vp9', 'av1', 'h264']);

const session = new RustDeskSession({
    host: args.host,
    peerId: args.peer,
    serverKey: args.key,
    password: args.password,
    myId: 'web-client-dev',
    myName: 'web-client integration',
    secure: args.secure === 'true',
    codecs,
});

let frames = 0;
let bytes = 0;
const started = Date.now();

session.onState = (s) => console.log(`[state] ${s}`);

session.onPeerInfo = (info) => {
    console.log(`\n${info.username}@${info.hostname}  ${info.platform} v${info.version}`);
    console.log(`encryption: ${session.encrypted ? 'on' : `OFF — ${session.downgradeReason}`}`);
    console.log(`displays: ${info.displays.length}, current ${info.current_display ?? 0}`);
    info.displays.forEach((d, i) => {
        console.log(`  [${i}] ${d.width}x${d.height} at (${d.x ?? 0},${d.y ?? 0})` +
            `${d.name ? ` "${d.name}"` : ''}${d.cursor_embedded ? ' cursor-embedded' : ''}` +
            `${d.online === false ? ' offline' : ''}`);
    });
    if (info.features) {
        console.log(`features: privacy_mode=${!!info.features.privacy_mode} terminal=${!!info.features.terminal}`);
    }
    console.log('');
};

session.onVideoFrame = (f) => {
    frames++;
    const n = f.units.reduce((sum, u) => sum + u.data.length, 0);
    bytes += n;
    console.log(`[video] #${frames} display ${f.display} ${f.codec} ` +
        `${f.units.length} unit(s) ${n}B key=${f.key}`);

    if (frames >= args.frames) {
        const secs = (Date.now() - started) / 1000;
        console.log(`\n✓ ${frames} frames, ${(bytes / 1024).toFixed(1)} KiB in ${secs.toFixed(1)}s`);
        console.log(`  permissions denied: ${session.permissions.denied().join(', ') || 'none'}`);
        if (session.lastDelayMs !== undefined) {
            console.log(`  peer-reported RTT ${session.lastDelayMs}ms, target ${session.targetBitrateKbps}kbps`);
        }
        session.close();
        process.exit(0);
    }
};

session.onCursor = (c) => {
    if (c.type === 'shape') console.log(`[cursor] shape id=${c.id} ${c.width}x${c.height} hot(${c.hotx ?? 0},${c.hoty ?? 0}) ${c.colors.length}B zstd`);
    else if (c.type === 'id') console.log(`[cursor] reuse id=${c.id}`);
};

session.onAudioFormat = (f) => console.log(`[audio] ${f.sample_rate}Hz x${f.channels}`);
session.onChat = (t) => console.log(`[chat] ${t}`);
session.onDisplaySwitch = (d) => console.log(`[display] switched to ${d.display} ${d.width}x${d.height}`);
session.onPermissions = (p) => console.log(`[perm] denied: ${p.denied().join(', ') || 'none'}`);

session.onClose = (err) => {
    console.error(`\n✗ ${err.code}: ${err.message}`);
    process.exit(1);
};

session.connect().catch((err) => {
    console.error(`✗ ${err.code ?? 'error'}: ${err.message}`);
    if (/Password/i.test(err.message)) console.error('  (check --password)');
    if (/No Password Access/i.test(err.message)) console.error('  (peer is in click-to-accept mode — approve on screen)');
    process.exit(1);
});
