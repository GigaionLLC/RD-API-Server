/**
 * Binds DOM input events to the protocol encoders.
 *
 * Spec: docs/spec/04-media-input.md §6.
 *
 * Deliberate choices, each fixing something a naive implementation gets wrong:
 *
 *  - Pointer Events with setPointerCapture. Without capture, dragging past the canvas
 *    edge loses the pointerup and the button stays down on the remote machine forever.
 *  - getCoalescedEvents. Browsers batch high-frequency moves; reading only the latest
 *    throws away the path, and a leading-edge throttle additionally discards the final
 *    position so the remote cursor lands short of where the user stopped.
 *  - Real deltaMode handling. Flattening every wheel event to +/-1 makes trackpads feel
 *    broken: a 3px nudge and a 300px flick become identical.
 *  - navigator.keyboard.lock(). Without it Ctrl+W closes the tab, Ctrl+T opens one, and
 *    Escape leaves fullscreen — none of which reach the peer.
 *  - Release-all on blur and visibilitychange. A modifier held while switching tabs
 *    otherwise stays down on the remote and corrupts every later keystroke.
 *
 * View-only is enforced here as well as in the encoders, so a caller cannot enable input
 * by accident.
 */

import { MouseEncoder, buttonFor, modifiersOf, toVirtualDesktop, wheelNotches } from './mouse.js';
import { KeyboardEncoder, namedKey, isPrintable, modifiersFor } from './keyboard.js';
import { ControlKey } from '../protocol/enums.js';

/** Wheel deltas are normalised to notches; these approximate one notch per unit. */
const LINE_HEIGHT_PX = 16;
const PAGE_HEIGHT_PX = 800;

export class InputController {
    /**
     * @param {object} opts
     * @param {HTMLElement} opts.element The canvas the user interacts with.
     * @param {(bytes: Uint8Array) => void} opts.send Sends pre-encoded Message bytes.
     * @param {() => {width: number, height: number}} opts.remoteSize Current video size.
     * @param {() => {x?: number, y?: number, scale?: number}} opts.display Active display.
     * @param {boolean} [opts.viewOnly]
     */
    constructor({ element, send, remoteSize, display, viewOnly = false }) {
        this.element = element;
        this.remoteSize = remoteSize;
        this.display = display;
        this.viewOnly = viewOnly;
        this.mouse = new MouseEncoder(send, { viewOnly });
        this.keyboard = new KeyboardEncoder(send, { viewOnly });
        this.enabled = false;
        this.locked = false;
        this._bound = [];
    }

    /** Maps a pointer event to the peer's virtual-desktop coordinates. */
    _toRemote(ev) {
        const rect = this.element.getBoundingClientRect();
        const { width, height } = this.remoteSize();
        if (!width || !height || !rect.width || !rect.height) return null;

        // The canvas is letterboxed by object-fit: contain, so the drawn image is
        // centred and smaller than the element. Mapping against the element instead of
        // the image is the usual source of a constant cursor offset.
        const scale = Math.min(rect.width / width, rect.height / height);
        const drawW = width * scale;
        const drawH = height * scale;
        const offX = (rect.width - drawW) / 2;
        const offY = (rect.height - drawH) / 2;

        const localX = ((ev.clientX - rect.left - offX) / drawW) * width;
        const localY = ((ev.clientY - rect.top - offY) / drawH) * height;
        if (localX < 0 || localY < 0 || localX >= width || localY >= height) return null;

        return toVirtualDesktop(this.display(), localX, localY);
    }

    /**
     * @param {HTMLElement | Window | Document} target
     * @param {string} type
     * @param {(ev: any) => void} handler
     * @param {object} [opts]
     */
    _on(target, type, handler, opts) {
        target.addEventListener(type, handler, opts);
        this._bound.push(() => target.removeEventListener(type, handler, opts));
    }

    attach() {
        if (this.enabled) return;
        this.enabled = true;
        const el = this.element;

        el.tabIndex = 0;
        el.style.touchAction = 'none';
        el.style.outline = 'none';

        this._on(el, 'pointerdown', (ev) => {
            if (this.viewOnly) return;
            el.focus();
            // Capture keeps events coming after the pointer leaves the element, so the
            // matching pointerup is never lost.
            try { el.setPointerCapture(ev.pointerId); } catch { /* not capturable */ }
            const p = this._toRemote(ev);
            const button = buttonFor(ev.button);
            if (!p || button === null) return;
            ev.preventDefault();
            // The host ignores coordinates on button events, so position first.
            this.mouse.move(p.x, p.y, modifiersOf(ev));
            this.mouse.down(button, p.x, p.y, modifiersOf(ev));
        });

        this._on(el, 'pointerup', (ev) => {
            if (this.viewOnly) return;
            try { el.releasePointerCapture(ev.pointerId); } catch { /* already released */ }
            const p = this._toRemote(ev) ?? this.mouse.lastPosition;
            const button = buttonFor(ev.button);
            if (!p || button === null) return;
            ev.preventDefault();
            this.mouse.up(button, p.x, p.y, modifiersOf(ev));
        });

        this._on(el, 'pointermove', (ev) => {
            if (this.viewOnly) return;
            const mods = modifiersOf(ev);
            // Replay the coalesced path rather than only the newest sample, so fast
            // strokes and the exact endpoint both survive.
            const points = typeof ev.getCoalescedEvents === 'function' ? ev.getCoalescedEvents() : [];
            for (const point of points.length ? points : [ev]) {
                const p = this._toRemote(point);
                if (p) this.mouse.move(p.x, p.y, mods);
            }
        });

        this._on(el, 'wheel', (ev) => {
            if (this.viewOnly) return;
            ev.preventDefault();
            // deltaMode: 0 = pixels, 1 = lines, 2 = pages.
            const factor = ev.deltaMode === 1 ? LINE_HEIGHT_PX : ev.deltaMode === 2 ? PAGE_HEIGHT_PX : 1;
            const px = { deltaX: ev.deltaX * factor, deltaY: ev.deltaY * factor, deltaMode: 0 };
            const n = wheelNotches(px);
            if (n.x || n.y) this.mouse.wheel(n.x, n.y, modifiersOf(ev));
        }, { passive: false });

        this._on(el, 'contextmenu', (ev) => { if (!this.viewOnly) ev.preventDefault(); });

        this._on(el, 'keydown', (ev) => this._onKey(ev, true));
        this._on(el, 'keyup', (ev) => this._onKey(ev, false));

        // A modifier held across a focus change would otherwise stay down on the remote.
        this._on(window, 'blur', () => this.keyboard.releaseAll());
        this._on(document, 'visibilitychange', () => {
            if (document.visibilityState === 'hidden') this.keyboard.releaseAll();
        });
    }

    /**
     * @param {KeyboardEvent} ev
     * @param {boolean} down
     */
    _onKey(ev, down) {
        if (this.viewOnly) return;
        ev.preventDefault();

        const named = namedKey(ev.key);
        if (named !== null) {
            this.keyboard.key(named, down, modifiersFor(ev, named));
            return;
        }

        // Printable characters go as composed text on keydown only: `seq` is one-shot
        // text injection, and repeating it on keyup would type everything twice.
        if (down && isPrintable(ev.key)) {
            // With Ctrl or Alt held this is a shortcut, not text — the host needs the
            // key itself so its own modifier state produces the right accelerator.
            if (ev.ctrlKey || ev.altKey || ev.metaKey) {
                const code = ev.key.toUpperCase().charCodeAt(0);
                this.keyboard.emit({
                    chr: code, down: true, press: true, modifiers: modifiersFor(ev), mode: 0,
                });
            } else {
                this.keyboard.text(ev.key, modifiersFor(ev));
            }
        }
    }

    /** Requests the browser stop intercepting Ctrl+W, Ctrl+T, Escape and friends. */
    async lockKeyboard() {
        if (this.viewOnly || !navigator.keyboard?.lock) return false;
        try {
            await navigator.keyboard.lock();
            this.locked = true;
            return true;
        } catch {
            return false; // requires fullscreen in some browsers
        }
    }

    unlockKeyboard() {
        try { navigator.keyboard?.unlock?.(); } catch { /* never locked */ }
        this.locked = false;
    }

    /** Ctrl+Alt+Del is a synthetic action the host performs; it injects no key. */
    sendCtrlAltDel() {
        if (this.viewOnly) return false;
        return this.keyboard.tap(ControlKey.CtrlAltDel);
    }

    /** @param {boolean} value */
    setViewOnly(value) {
        this.viewOnly = value;
        this.mouse.viewOnly = value;
        this.keyboard.viewOnly = value;
        if (value) this.keyboard.releaseAll();
    }

    detach() {
        this.keyboard.releaseAll();
        this.unlockKeyboard();
        for (const off of this._bound.splice(0)) off();
        this.enabled = false;
    }

    stats() {
        return { mouse: this.mouse.sent, keys: this.keyboard.sent, viewOnly: this.viewOnly, locked: this.locked };
    }
}
