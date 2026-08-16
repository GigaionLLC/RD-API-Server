/**
 * Keyboard event encoding.
 *
 * Spec: docs/spec/04-media-input.md §6.2.
 *
 * A key can be expressed five ways; a browser can honestly produce two of them:
 *
 *  - `control_key` — a named, layout-independent key (Return, F5, arrows, modifiers).
 *  - `seq` — composed text, which is what `KeyboardEvent.key` already gives us and which
 *    survives layout mismatches between the two machines.
 *
 * `chr` (Map mode) carries a POSITIONAL code in the PEER's namespace — PS/2 scan codes
 * on Windows, evdev+8 on Linux, virtual key codes on macOS. A browser has
 * `KeyboardEvent.code`, which is positional but in its own namespace, so Map mode needs
 * a per-platform translation table. That is deferred; Legacy plus Translate covers
 * everything except games and exact key-state fidelity.
 *
 * Lock keys matter more than they look: CapsLock must be reported in `modifiers` for
 * letter keys and NumLock for numpad keys, or remote typing comes out in the wrong case.
 */

import { encode } from '../protocol/codec.js';
import { Message } from '../protocol/message.js';
import { ControlKey, KeyboardMode, MODIFIER_KEYS } from '../protocol/enums.js';

/** `KeyboardEvent.key` → ControlKey, for keys with no printable representation. */
const NAMED = {
    Alt: ControlKey.Alt,
    AltGraph: ControlKey.RAlt,
    Backspace: ControlKey.Backspace,
    CapsLock: ControlKey.CapsLock,
    Control: ControlKey.Control,
    Delete: ControlKey.Delete,
    ArrowDown: ControlKey.DownArrow,
    End: ControlKey.End,
    Escape: ControlKey.Escape,
    Home: ControlKey.Home,
    ArrowLeft: ControlKey.LeftArrow,
    Meta: ControlKey.Meta,
    OS: ControlKey.Meta,
    PageDown: ControlKey.PageDown,
    PageUp: ControlKey.PageUp,
    Enter: ControlKey.Return,
    ArrowRight: ControlKey.RightArrow,
    Shift: ControlKey.Shift,
    Tab: ControlKey.Tab,
    ArrowUp: ControlKey.UpArrow,
    Insert: ControlKey.Insert,
    Pause: ControlKey.Pause,
    ScrollLock: ControlKey.Scroll,
    NumLock: ControlKey.NumLock,
    PrintScreen: ControlKey.Snapshot,
    ContextMenu: ControlKey.Apps,
    Clear: ControlKey.Clear,
    Cancel: ControlKey.Cancel,
    Help: ControlKey.Help,
    AudioVolumeMute: ControlKey.VolumeMute,
    AudioVolumeUp: ControlKey.VolumeUp,
    AudioVolumeDown: ControlKey.VolumeDown,
};

// F1-F12. The enum is alphabetically ordered upstream, so these are NOT contiguous:
// F1=9, then F10/F11/F12 at 10-12, then F2..F9 at 13-20.
for (let i = 1; i <= 12; i++) NAMED[`F${i}`] = ControlKey[`F${i}`];

/** Numpad digits, which need NumLock reported alongside them. */
for (let i = 0; i <= 9; i++) NAMED[`Numpad${i}`] = ControlKey[`Numpad${i}`];

/**
 * @param {string} key A `KeyboardEvent.key` value.
 * @returns {number | null} The ControlKey, or null if the key is printable text.
 */
export function namedKey(key) {
    return NAMED[key] ?? null;
}

/** @param {string} key @returns {boolean} A single printable character. */
export function isPrintable(key) {
    return [...key].length === 1;
}

/**
 * Modifier list for a key event.
 *
 * The modifier that IS the key being pressed must be omitted: reporting Shift as held
 * while sending Shift itself confuses the peer's state synchronisation. Lock state is
 * appended only where it is meaningful.
 *
 * @param {object} ev
 * @param {number | null} [selfKey] The ControlKey being sent, if any.
 * @returns {number[]}
 */
export function modifiersFor(ev, selfKey = null) {
    const mods = [];
    const push = (held, code) => {
        if (held && code !== selfKey) mods.push(code);
    };
    push(ev.altKey, ControlKey.Alt);
    push(ev.shiftKey, ControlKey.Shift);
    push(ev.ctrlKey, ControlKey.Control);
    push(ev.metaKey, ControlKey.Meta);

    // CapsLock only for letters, NumLock only for the numpad. Sending them elsewhere
    // makes the host toggle LED state it should have left alone.
    if (typeof ev.getModifierState === 'function') {
        const key = ev.key ?? '';
        if (ev.getModifierState('CapsLock') && /^[a-z]$/i.test(key)) mods.push(ControlKey.CapsLock);
        if (ev.getModifierState('NumLock') && /^Numpad/.test(ev.code ?? '')) mods.push(ControlKey.NumLock);
    }
    return mods;
}

/** @param {number} code @returns {boolean} */
export function isModifier(code) {
    return MODIFIER_KEYS.has(code);
}

/**
 * Builds key messages. Separate from the session for the same reason as MouseEncoder:
 * a library consumer should not be able to type on a remote machine by accident.
 */
export class KeyboardEncoder {
    /**
     * @param {(bytes: Uint8Array) => void} sendRaw
     * @param {object} [opts]
     * @param {boolean} [opts.viewOnly]
     */
    constructor(sendRaw, { viewOnly = false } = {}) {
        this.sendRaw = sendRaw;
        this.viewOnly = viewOnly;
        this.sent = 0;
        /** @type {Set<number>} Held named keys, so focus loss can release them. */
        this.held = new Set();
    }

    /** @param {object} keyEvent A `KeyEvent` shaped object. */
    emit(keyEvent) {
        if (this.viewOnly) return false;
        this.sendRaw(encode(Message, { key_event: keyEvent }));
        this.sent++;
        return true;
    }

    /**
     * A named key, with explicit down/up so held keys behave.
     * @param {number} control ControlKey value.
     * @param {boolean} down
     * @param {number[]} [modifiers]
     */
    key(control, down, modifiers = []) {
        if (down) this.held.add(control); else this.held.delete(control);
        return this.emit({ control_key: control, down, press: false, modifiers, mode: KeyboardMode.Legacy });
    }

    /**
     * A named key pressed and released as one atomic action.
     * @param {number} control @param {number[]} [modifiers]
     */
    tap(control, modifiers = []) {
        return this.emit({ control_key: control, down: true, press: true, modifiers, mode: KeyboardMode.Legacy });
    }

    /**
     * Composed text. Translate mode lets the host type the string regardless of layout
     * mismatch, and is the only path that handles characters a positional code cannot
     * express on the peer's layout.
     * @param {string} text @param {number[]} [modifiers]
     */
    text(text, modifiers = []) {
        if (!text) return false;
        return this.emit({ seq: text, down: true, press: true, modifiers, mode: KeyboardMode.Translate });
    }

    /**
     * Releases everything still held. Call on blur and visibilitychange: a modifier held
     * while the tab loses focus otherwise stays down on the remote forever, and every
     * subsequent keystroke arrives modified.
     */
    releaseAll() {
        for (const control of [...this.held]) this.key(control, false);
        this.held.clear();
    }
}
