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
 * Modifiers currently held, in the order the peer expects them.
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
 * Maps a point on the video into the peer's virtual-desktop coordinates.
 *
 * This is the exact inverse of how the cursor layer draws the peer's pointer, and that is
 * the whole point: the two used to be derived independently — the cursor from the measured
 * ratio between the video and the display's reported size, input from `DisplayInfo.scale` —
 * and they agree only when `scale` happens to equal `display.width / video.width`. When
 * they disagreed, the pointer was drawn in one place and the click landed in another, with
 * nothing on screen to explain the gap.
 *
 * Deriving both from the same measured ratio makes that impossible by construction:
 * wherever the remote pointer appears under yours, a click there arrives there. It also
 * needs no assumption about what `scale` means on a given platform, and collapses to a
 * plain offset when the video and the display report the same dimensions, which is the
 * common case.
 *
 * @param {{x?: number, y?: number, width?: number, height?: number, scale?: number}} display
 *   From PeerInfo.displays — geometry in the peer's own units.
 * @param {number} localX Pixels within the decoded video.
 * @param {number} localY
 * @param {{width: number, height: number}} [video] The decoded video size. Omitting it
 *   falls back to `scale`, which is all that is available before the first frame.
 * @returns {{x: number, y: number}}
 */
export function toVirtualDesktop(display, localX, localY, video = undefined) {
    const originX = display.x ?? 0;
    const originY = display.y ?? 0;

    if (video && video.width > 0 && video.height > 0 && display.width && display.height) {
        return {
            x: Math.round(originX + localX * (display.width / video.width)),
            y: Math.round(originY + localY * (display.height / video.height)),
        };
    }

    const scale = display.scale && display.scale > 0 ? display.scale : 1;

    return {
        x: Math.round(originX + localX / scale),
        y: Math.round(originY + localY / scale),
    };
}

/**
 * Browser wheel delta → protocol notch counts.
 *
 * The sign is inverted relative to the DOM: `deltaY > 0` means scrolling down, which the
 * protocol expresses as -1. A dominant-axis lock avoids spurious horizontal scroll from
 * trackpad noise.
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
 * One wheel notch, in the pixels a browser reports for it.
 *
 * Chrome reports 100 per notch in pixel mode; Firefox uses line mode instead, which the
 * caller has already converted. The exact number only decides how far a notch scrolls,
 * not whether scrolling works.
 */
export const NOTCH_PX = 100;

/** Below this, a pixel-mode delta is a trackpad rather than a wheel. See `ScrollRouter`. */
const TRACKPAD_MAX_PX = 40;

/**
 * Turns a stream of browser wheel events into protocol scroll messages.
 *
 * Two problems, both of which a naive `±1 per event` gets wrong in opposite directions:
 *
 *  - A mouse wheel produces large discrete deltas. Sending one notch per event is
 *    correct, but a fast flick that the browser coalesces into a single 300px event has
 *    to become three notches, or scrolling a long document takes three times the wrist.
 *  - A trackpad produces a continuous stream of small deltas — often under 10px. Rounding
 *    each to a full notch turns a 3px nudge into a page jump; discarding them makes the
 *    trackpad feel dead. Those go out as TRACKPAD, which the host applies as pixels
 *    without the ×120 wheel multiplication.
 *
 * Remainders are carried between events, so a slow drag accumulates instead of being
 * repeatedly truncated to zero.
 */
export class ScrollRouter {
    /**
     * @param {object} [opts]
     * @param {number} [opts.notchPx]
     * @param {boolean} [opts.allowTrackpad] Whether the peer accepts TRACKPAD. Defaults
     *   to off: an older host ignores a mouse type it does not know, which would leave
     *   scrolling silently dead rather than merely coarse. The caller gates this on the
     *   peer version — see protocol/version.js.
     */
    constructor({ notchPx = NOTCH_PX, allowTrackpad = false } = {}) {
        this.notchPx = notchPx;
        this.allowTrackpad = allowTrackpad;
        this._wheel = { x: 0, y: 0 };
        this._pixels = { x: 0, y: 0 };
    }

    /**
     * @param {{deltaX: number, deltaY: number, deltaMode?: number}} ev Already converted
     *   to pixels by the caller, which knows the browser's line and page heights.
     * @param {boolean} [precise] The event came from a precise device. Callers pass the
     *   original `deltaMode === 0`; line and page modes are only ever produced by a wheel.
     * @returns {{kind: 'wheel'|'trackpad', x: number, y: number} | null}
     */
    push({ deltaX = 0, deltaY = 0 }, precise = true) {
        if (!deltaX && !deltaY) return null;

        // A wheel in pixel mode still arrives in notch-sized steps; a trackpad does not.
        // Taking the larger axis avoids classifying the near-zero cross-axis of a diagonal
        // trackpad gesture as a wheel event on its own.
        const magnitude = Math.max(Math.abs(deltaX), Math.abs(deltaY));
        const isTrackpad = this.allowTrackpad && precise && magnitude < TRACKPAD_MAX_PX;

        if (isTrackpad) {
            // Both axes: a trackpad scrolls diagonally on purpose, and the axis lock that
            // suits a wheel would make a diagonal gesture stutter between the two.
            this._pixels.x += deltaX;
            this._pixels.y += deltaY;
            const x = Math.trunc(this._pixels.x);
            const y = Math.trunc(this._pixels.y);
            this._pixels.x -= x;
            this._pixels.y -= y;
            if (!x && !y) return null;
            // `|| 0` normalises negative zero, which is a valid protobuf value but makes
            // every equality check on the result surprising.
            return { kind: 'trackpad', x: -x || 0, y: -y || 0 };
        }

        // Dominant axis only. A wheel tilts rarely, and cross-axis leakage on a mouse is
        // noise rather than intent.
        const vertical = Math.abs(deltaY) >= Math.abs(deltaX);
        const axis = vertical ? 'y' : 'x';
        this._wheel[axis] += vertical ? deltaY : deltaX;
        const notches = Math.trunc(this._wheel[axis] / this.notchPx);
        if (!notches) return null;
        this._wheel[axis] -= notches * this.notchPx;
        // The other axis's residue is stale once the user has changed direction.
        this._wheel[vertical ? 'x' : 'y'] = 0;
        return vertical
            ? { kind: 'wheel', x: 0, y: -notches }
            : { kind: 'wheel', x: -notches, y: 0 };
    }

    reset() {
        this._wheel = { x: 0, y: 0 };
        this._pixels = { x: 0, y: 0 };
    }
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
