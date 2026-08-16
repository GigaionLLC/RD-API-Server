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
 *   --move X,Y      one absolute pointer move
 *   --scrolllock    tap Scroll Lock twice, toggling a keyboard LED and returning it to
 *                   its original state — verifiable, and it affects nothing else
 *   --click X,Y     left press and release
 *   --drag X1,Y1,X2,Y2   press, move, release
 *   --wheel N       N notches (negative scrolls down, matching the protocol's sign)
 *   --text S        typed as composed text
 *
 * Anything beyond a move REQUIRES --confine x,y,w,h, and every coordinate is checked
 * against that rectangle before being sent. Injected input is indistinguishable from
 * physical input at the OS level, so safety comes from controlling the RECEIVER: aim
 * everything inside a scratch window that does nothing (tools/win/input-target.ps1),
 * which also records what actually arrived. Without confinement a click lands on
 * whatever happens to be under the pointer and cannot be undone.
 *
 *   node tools/integration/input-probe.mjs --host H --peer ID --key K --password P \
 *        --confine 408,231,884,461 --click 850,460 --confirm-controls-peer
 */

import { RustDeskSession } from '../../src/session/machine.js';
import { CodecCapabilities } from '../../src/media/codec.js';
import { MouseEncoder } from '../../src/input/mouse.js';
import { KeyboardEncoder } from '../../src/input/keyboard.js';
import { ControlKey, MouseButton } from '../../src/protocol/enums.js';

function parseArgs(argv) {
    const out = {
        host: '', peer: '', key: '', password: '',
        move: '', click: '', drag: '', wheel: '', text: '', confine: '',
        scrolllock: false, confirm: false,
    };
    for (let i = 0; i < argv.length; i++) {
        const a = argv[i];
        if (a === '--scrolllock') out.scrolllock = true;
        else if (a === '--confirm-controls-peer') out.confirm = true;
        else if (a.startsWith('--')) { out[a.slice(2)] = argv[i + 1]; i++; }
    }
    if (!out.host || !out.peer) {
        console.error('usage: input-probe.mjs --host H --peer ID [--key K] [--password P]');
        console.error('       [--confine x,y,w,h] [--move X,Y] [--click X,Y] [--drag X1,Y1,X2,Y2]');
        console.error('       [--wheel N] [--text S] [--scrolllock] --confirm-controls-peer');
        process.exit(2);
    }
    if (!out.confirm) {
        console.error('refusing to send input without --confirm-controls-peer');
        console.error('every event lands on the real desktop of the peer.');
        process.exit(3);
    }
    if ((out.click || out.drag || out.wheel || out.text) && !out.confine) {
        console.error('--confine x,y,w,h is required for click, drag, wheel or text.');
        console.error('aim them inside a scratch window (tools/win/input-target.ps1); a click');
        console.error('outside one activates whatever is under the pointer and cannot be undone.');
        process.exit(3);
    }
    return out;
}

const args = parseArgs(process.argv.slice(2));

/** @type {{x: number, y: number, w: number, h: number} | null} */
const confine = args.confine
    ? (([x, y, w, h]) => ({ x, y, w, h }))(args.confine.split(',').map(Number))
    : null;

/** Refuses any coordinate outside the confinement rectangle. */
function checked(x, y, what) {
    if (!confine) return { x, y };
    const inside = x >= confine.x && y >= confine.y
        && x < confine.x + confine.w && y < confine.y + confine.h;
    if (!inside) {
        throw new Error(`${what} (${x},${y}) is outside the confinement rect ` +
            `(${confine.x},${confine.y} ${confine.w}x${confine.h}) — refusing to send`);
    }
    return { x, y };
}

/** @param {string} s @returns {number[]} */
const nums = (s) => s.split(',').map((n) => Number(n.trim()));

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

    if (confine) {
        console.log(`confinement: (${confine.x},${confine.y}) ${confine.w}x${confine.h}`);
    }

    if (args.move) {
        const [x, y] = nums(args.move);
        if (!Number.isFinite(x) || !Number.isFinite(y)) throw new Error(`bad --move ${args.move}`);
        console.log(`→ move (${x}, ${y})`);
        mouse.move(x, y);
        await sleep(400);
    }

    if (args.click) {
        const { x, y } = checked(...nums(args.click), 'click');
        console.log(`→ click (${x}, ${y})`);
        // Move first: the host ignores coordinates on button events, so a click without
        // a preceding move lands wherever the pointer happens to be.
        mouse.move(x, y);
        await sleep(150);
        mouse.down(MouseButton.LEFT, x, y);
        await sleep(60);
        mouse.up(MouseButton.LEFT, x, y);
        await sleep(400);
    }

    if (args.drag) {
        const [x1, y1, x2, y2] = nums(args.drag);
        checked(x1, y1, 'drag start');
        checked(x2, y2, 'drag end');
        console.log(`→ drag (${x1},${y1}) → (${x2},${y2})`);
        mouse.move(x1, y1);
        await sleep(120);
        mouse.down(MouseButton.LEFT, x1, y1);
        // Intermediate moves matter: a press followed by a single jump reads as a click
        // at the destination in many applications, not as a drag.
        for (let i = 1; i <= 6; i++) {
            mouse.move(Math.round(x1 + ((x2 - x1) * i) / 6), Math.round(y1 + ((y2 - y1) * i) / 6));
            await sleep(40);
        }
        mouse.up(MouseButton.LEFT, x2, y2);
        await sleep(400);
    }

    if (args.wheel) {
        const n = Number(args.wheel);
        const { x, y } = checked(confine.x + Math.floor(confine.w / 2), confine.y + Math.floor(confine.h / 2), 'wheel');
        console.log(`→ wheel ${n} notch(es) at (${x}, ${y})`);
        mouse.move(x, y);
        await sleep(120);
        for (let i = 0; i < Math.abs(n); i++) {
            mouse.wheel(0, n > 0 ? 1 : -1);
            await sleep(80);
        }
        await sleep(300);
    }

    if (args.text) {
        console.log(`→ text ${JSON.stringify(args.text)}`);
        keyboard.text(args.text);
        await sleep(400);
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
