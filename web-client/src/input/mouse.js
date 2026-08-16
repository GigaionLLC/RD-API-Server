/**
 * Mouse event encoding and coordinate mapping.
 *
 * Spec: docs/spec/04-media-input.md §6.1.
 *
 * `mask = (button << 3) | type`. The host extracts the type with `mask & 0x7` and
 * dispatches the button by EXACT EQUALITY against a single constant — so a mask with two
 * button bits set silently does nothing at all. Encoders must set exactly one.
 *
 * Coordinates for MOVE are absolute in the peer's VIRTUAL DESKTOP space, which is the
 * display's origin plus the offset within it. Origins are signed: a monitor left of or
 * above the primary has negative x or y, which is why the field is sint32.
 */

import { encode } from '../protocol/codec.js';
import { Message } from '../protocol/message.js';
import { MouseType, MouseButton, ControlKey, mouseMask } from '../protocol/enums.js';

/** Browser `MouseEvent.button` → the protocol's pre-shift button bit. */
const BUTTON_BY_INDEX = {
    0: MouseButton.LEFT,
    1: MouseButton.MIDDLE,
    2: MouseButton.RIGHT,
    3: MouseButton.BACK,
    4: MouseButton.FORWARD,
};

/** @param {number} index @returns {number | null} */
export function buttonFor(index) {
    return BUTTON_BY_INDEX[index] ?? null;
}

/**
 * Modifiers currently held, in the order the reference client emits them.
 * @param {{altKey?: boolean, shiftKey?: boolean, ctrlKey?: boolean, metaKey?: boolean}} ev
 * @returns {number[]}
 */
export function modifiersOf(ev) {
    const mods = [];
    if (ev.altKey) mods.push(ControlKey.Alt);
    if (ev.shiftKey) mods.push(ControlKey.Shift);
    if (ev.ctrlKey) mods.push(ControlKey.Control);
    if (ev.metaKey) mods.push(ControlKey.Meta);
    return mods;
}

/**
 * Maps a point inside one display to the peer's virtual-desktop coordinates.
 *
 * `scale` is 1 everywhere except macOS HiDPI and some multi-display Linux, where the
 * host injects in logical points rather than physical pixels.
 *
 * @param {{x?: number, y?: number, scale?: number}} display From PeerInfo.displays.
 * @param {number} localX Pixels within the display.
 * @param {number} localY
 * @returns {{x: number, y: number}}
 */
export function toVirtualDesktop(display, localX, localY) {
    const scale = display.scale && display.scale > 0 ? display.scale : 1;
    return {
        x: Math.round((display.x ?? 0) + localX / scale),
        y: Math.round((display.y ?? 0) + localY / scale),
    };
}

/**
 * Browser wheel delta → protocol notch counts.
 *
 * The sign is inverted relative to the DOM: `deltaY > 0` means scrolling down, which the
 * protocol expresses as -1. A dominant-axis lock matches the reference client and avoids
 * spurious horizontal scroll from trackpad noise.
 *
 * @param {{deltaX: number, deltaY: number, deltaMode?: number}} ev
 * @returns {{x: number, y: number}}
 */
export function wheelNotches(ev) {
    const ax = Math.abs(ev.deltaX);
    const ay = Math.abs(ev.deltaY);
    if (ax === 0 && ay === 0) return { x: 0, y: 0 };
    if (ay >= ax) return { x: 0, y: ev.deltaY > 0 ? -1 : 1 };
    return { x: ev.deltaX > 0 ? -1 : 1, y: 0 };
}

/**
 * Builds mouse messages. Kept separate from the session so a consumer of the session
 * library cannot move a remote pointer without explicitly constructing one of these.
 */
export class MouseEncoder {
    /**
     * @param {(bytes: Uint8Array) => void} sendRaw
     * @param {object} [opts]
     * @param {boolean} [opts.viewOnly] When true, nothing is ever emitted.
     */
    constructor(sendRaw, { viewOnly = false } = {}) {
        this.sendRaw = sendRaw;
        this.viewOnly = viewOnly;
        this.sent = 0;
        this.lastPosition = null;
    }

    /**
     * @param {number} mask
     * @param {number} x
     * @param {number} y
     * @param {number[]} [modifiers]
     */
    emit(mask, x, y, modifiers = []) {
        if (this.viewOnly) return false;
        this.sendRaw(encode(Message, { mouse_event: { mask, x, y, modifiers } }));
        this.sent++;
        return true;
    }

    /** @param {number} x @param {number} y @param {number[]} [modifiers] */
    move(x, y, modifiers = []) {
        this.lastPosition = { x, y };
        return this.emit(mouseMask(MouseType.MOVE), x, y, modifiers);
    }

    /**
     * @param {number} button One of MouseButton (pre-shift).
     * @param {number} x @param {number} y @param {number[]} [modifiers]
     */
    down(button, x, y, modifiers = []) {
        return this.emit(mouseMask(MouseType.DOWN, button), x, y, modifiers);
    }

    /** @param {number} button @param {number} x @param {number} y @param {number[]} [modifiers] */
    up(button, x, y, modifiers = []) {
        return this.emit(mouseMask(MouseType.UP, button), x, y, modifiers);
    }

    /**
     * @param {number} x Horizontal notches.
     * @param {number} y Vertical notches, already sign-inverted by `wheelNotches`.
     * @param {number[]} [modifiers]
     */
    wheel(x, y, modifiers = []) {
        if (x === 0 && y === 0) return false;
        return this.emit(mouseMask(MouseType.WHEEL), x, y, modifiers);
    }

    /** Precise/pixel scrolling, delivered without the Windows x120 multiplication. */
    trackpad(x, y, modifiers = []) {
        return this.emit(mouseMask(MouseType.TRACKPAD), x, y, modifiers);
    }
}
