/**
 * Input feasibility probe.
 *
 * Sends a deliberately minimal, reversible set of input events so the encoding and
 * coordinate mapping can be verified against an independent source of truth (on Windows,
 * `GetCursorPos` / `GetKeyState` read from the host itself).
 *
 * WHY SO NARROW: when the peer is the machine running this, every event lands on the
 * real desktop. A stray click activates whatever is under the pointer and cannot be
 * undone. So this tool supports:
 *
 *   --move X,Y      one absolute pointer move (harmless, and trivially reversible by
 *                   moving the mouse, or by running this again with the old coordinates)
 *   --scrolllock    tap Scroll Lock twice, toggling a keyboard LED and returning it to
 *                   its original state — verifiable, and it affects nothing else
 *
 * There is no click, no drag, no text, and no wheel. Those need a peer that is not the
 * developer's own machine.
 *
 *   node tools/integration/input-probe.mjs --host H --peer ID --key K --password P \
 *        --move 640,400 --confirm-controls-peer
 */

import { RustDeskSession } from '../../src/session/machine.js';
import { CodecCapabilities } from '../../src/media/codec.js';
import { MouseEncoder } from '../../src/input/mouse.js';
import { KeyboardEncoder } from '../../src/input/keyboard.js';
import { ControlKey } from '../../src/protocol/enums.js';

function parseArgs(argv) {
    const out = { host: '', peer: '', key: '', password: '', move: '', scrolllock: false, confirm: false };
    for (let i = 0; i < argv.length; i++) {
        const a = argv[i];
        if (a === '--scrolllock') out.scrolllock = true;
        else if (a === '--confirm-controls-peer') out.confirm = true;
        else if (a.startsWith('--')) { out[a.slice(2)] = argv[i + 1]; i++; }
    }
    if (!out.host || !out.peer) {
        console.error('usage: input-probe.mjs --host H --peer ID [--key K] [--password P] [--move X,Y] [--scrolllock] --confirm-controls-peer');
        process.exit(2);
    }
    if (!out.confirm) {
        console.error('refusing to send input without --confirm-controls-peer');
        console.error('every event lands on the real desktop of the peer.');
        process.exit(3);
    }
    return out;
}

const args = parseArgs(process.argv.slice(2));

const session = new RustDeskSession({
    host: args.host,
    peerId: args.peer,
    serverKey: args.key,
    password: args.password,
    myId: 'web-client-probe',
    myName: 'input probe',
    codecs: new CodecCapabilities(['vp8', 'vp9', 'av1', 'h264']),
});

const sendRaw = (bytes) => session.socket.send(session.stream.encrypt(bytes));
const mouse = new MouseEncoder(sendRaw);
const keyboard = new KeyboardEncoder(sendRaw);

const sleep = (ms) => new Promise((r) => { setTimeout(r, ms); });

session.onClose = (err) => {
    console.error(`✗ ${err.code}: ${err.message}`);
    process.exit(1);
};

try {
    const info = await session.connect();
    console.log(`connected: ${info.hostname} ${info.platform} v${info.version}`);

    // Displays are in virtual-desktop space; report the layout so a caller can pick a
    // coordinate knowingly rather than guessing.
    info.displays.forEach((d, i) => {
        console.log(`  display ${i}: ${d.width}x${d.height} at (${d.x ?? 0},${d.y ?? 0})` +
            `${i === (info.current_display ?? 0) ? ' [current]' : ''}`);
    });

    if (!session.permissions.allows('Keyboard')) {
        // The peer's Keyboard permission gates mouse AND keyboard injection.
        console.error('✗ peer denies the Keyboard permission — input would be ignored');
        session.close();
        process.exit(1);
    }

    // Let the stream settle so our events are not racing the first key frame.
    await sleep(700);

    if (args.move) {
        const [x, y] = args.move.split(',').map((n) => Number(n.trim()));
        if (!Number.isFinite(x) || !Number.isFinite(y)) throw new Error(`bad --move ${args.move}`);
        console.log(`→ mouse move to virtual-desktop (${x}, ${y})`);
        mouse.move(x, y);
        await sleep(500);
    }

    if (args.scrolllock) {
        // Toggle and restore: the LED changes twice and nothing else is affected.
        console.log('→ Scroll Lock tap x2 (toggle and restore)');
        keyboard.tap(ControlKey.Scroll);
        await sleep(400);
        keyboard.tap(ControlKey.Scroll);
        await sleep(400);
    }

    console.log(`sent ${mouse.sent} mouse, ${keyboard.sent} key event(s)`);
    session.close();
    process.exit(0);
} catch (err) {
    console.error(`✗ ${err.code ?? 'error'}: ${err.message}`);
    process.exit(1);
}
