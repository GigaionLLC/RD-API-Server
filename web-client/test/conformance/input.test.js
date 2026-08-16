/**
 * Input encoding conformance.
 *
 * The failure modes here are silent: a mask with two button bits does nothing, a wheel
 * with the wrong sign scrolls backwards, and a missing CapsLock modifier types in the
 * wrong case. All are cheap to pin and expensive to notice in the wild.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { decode } from '../../src/protocol/codec.js';
import { Message } from '../../src/protocol/message.js';
import { ControlKey, KeyboardMode, MouseButton, MouseType } from '../../src/protocol/enums.js';
import {
    MouseEncoder, ScrollRouter, buttonFor, modifiersOf, toVirtualDesktop, wheelNotches,
} from '../../src/input/mouse.js';
import { supportsTrackpadScroll } from '../../src/protocol/version.js';
import {
    KeyboardEncoder, namedKey, isPrintable, modifiersFor, isModifier,
} from '../../src/input/keyboard.js';

/** Captures encoded messages instead of sending them. */
function capture() {
    const sent = [];
    const fn = (bytes) => sent.push(decode(Message, bytes));
    return { sent, fn };
}

/* -------------------------------------------------------------------------- */
/* Mouse                                                                      */
/* -------------------------------------------------------------------------- */

test('a move carries type 0 and no button bits', () => {
    const { sent, fn } = capture();
    new MouseEncoder(fn).move(960, 540);
    assert.equal(sent[0].$case, 'mouse_event');
    const m = sent[0].mouse_event;
    assert.equal(m.mask, undefined, 'mask 0 is the proto3 default and is not emitted');
    assert.equal(m.x, 960);
    assert.equal(m.y, 540);
});

test('down and up set exactly one button bit', () => {
    const { sent, fn } = capture();
    const enc = new MouseEncoder(fn);
    enc.down(MouseButton.RIGHT, 10, 20);
    enc.up(MouseButton.RIGHT, 10, 20);

    const [down, up] = sent.map((s) => s.mouse_event.mask);
    assert.equal(down, 0x11);
    assert.equal(up, 0x12);
    // The host dispatches by exact equality, so more than one bit silently does nothing.
    assert.equal((down >> 3) & ((down >> 3) - 1), 0, 'exactly one button bit');
    assert.equal(down & 0x7, MouseType.DOWN);
    assert.equal(up & 0x7, MouseType.UP);
});

test('every browser button index maps to a distinct protocol bit', () => {
    assert.equal(buttonFor(0), MouseButton.LEFT);
    assert.equal(buttonFor(1), MouseButton.MIDDLE, 'browser 1 is middle, not right');
    assert.equal(buttonFor(2), MouseButton.RIGHT);
    assert.equal(buttonFor(3), MouseButton.BACK);
    assert.equal(buttonFor(4), MouseButton.FORWARD);
    assert.equal(buttonFor(9), null);
});

test('wheel sign is inverted relative to the DOM', () => {
    // deltaY > 0 is "scrolling down" in the DOM; the protocol expresses that as -1.
    assert.deepEqual(wheelNotches({ deltaX: 0, deltaY: 100 }), { x: 0, y: -1 });
    assert.deepEqual(wheelNotches({ deltaX: 0, deltaY: -100 }), { x: 0, y: 1 });
    assert.deepEqual(wheelNotches({ deltaX: 100, deltaY: 0 }), { x: -1, y: 0 });
    assert.deepEqual(wheelNotches({ deltaX: 0, deltaY: 0 }), { x: 0, y: 0 });
});

test('wheel locks to the dominant axis', () => {
    // Trackpads emit small orthogonal noise on every scroll; without a lock the remote
    // drifts sideways.
    assert.deepEqual(wheelNotches({ deltaX: 3, deltaY: 100 }), { x: 0, y: -1 });
    assert.deepEqual(wheelNotches({ deltaX: 100, deltaY: 3 }), { x: -1, y: 0 });
});

test('a zero wheel is not emitted', () => {
    const { sent, fn } = capture();
    assert.equal(new MouseEncoder(fn).wheel(0, 0), false);
    assert.equal(sent.length, 0);
});

/* -------------------------------------------------------------------------- */
/* Scroll routing                                                             */
/* -------------------------------------------------------------------------- */

test('a coalesced flick becomes proportional notches, not one', () => {
    // Browsers coalesce fast wheel input into a single large delta. Emitting ±1 per event
    // meant a three-notch flick scrolled one notch, so long documents took three times
    // the wrist.
    const r = new ScrollRouter();
    assert.deepEqual(r.push({ deltaX: 0, deltaY: 300 }, false), { kind: 'wheel', x: 0, y: -3 });
    assert.deepEqual(r.push({ deltaX: 0, deltaY: -100 }, false), { kind: 'wheel', x: 0, y: 1 });
});

test('sub-notch scrolling accumulates instead of being truncated away', () => {
    // A trackpad against a peer too old for TRACKPAD. Truncating each event to zero makes
    // the trackpad feel dead; rounding each up to a full notch makes a nudge jump a page.
    const r = new ScrollRouter();
    for (let i = 0; i < 9; i++) {
        assert.equal(r.push({ deltaX: 0, deltaY: 10 }, true), null, `event ${i} accumulates`);
    }
    assert.deepEqual(r.push({ deltaX: 0, deltaY: 10 }, true), { kind: 'wheel', x: 0, y: -1 });
});

test('remainders carry, so a slow drag does not lose distance', () => {
    const r = new ScrollRouter();
    assert.deepEqual(r.push({ deltaX: 0, deltaY: 150 }, false), { kind: 'wheel', x: 0, y: -1 });
    // The leftover 50 plus another 50 is the second notch, not a discarded half.
    assert.deepEqual(r.push({ deltaX: 0, deltaY: 50 }, false), { kind: 'wheel', x: 0, y: -1 });
});

test('a wheel locks to the dominant axis and drops the stale cross-axis residue', () => {
    const r = new ScrollRouter();
    assert.equal(r.push({ deltaX: 60, deltaY: 0 }, false), null, 'part of a horizontal notch');
    // Switching to vertical mid-gesture must not later emit the abandoned horizontal one.
    assert.deepEqual(r.push({ deltaX: 0, deltaY: 100 }, false), { kind: 'wheel', x: 0, y: -1 });
    assert.equal(r.push({ deltaX: 60, deltaY: 0 }, false), null, 'horizontal restarted from zero');
});

test('trackpad pixels are sent verbatim when the peer supports them', () => {
    // TRACKPAD is applied as pixels without the wheel's ×120, which is what makes a
    // trackpad feel continuous rather than stepped.
    const r = new ScrollRouter({ allowTrackpad: true });
    assert.deepEqual(r.push({ deltaX: 0, deltaY: 7 }, true), { kind: 'trackpad', x: 0, y: -7 });
    // Both axes: a diagonal gesture is deliberate on a trackpad.
    assert.deepEqual(r.push({ deltaX: 4, deltaY: -6 }, true), { kind: 'trackpad', x: -4, y: 6 });
});

test('a large pixel delta is a wheel even where trackpads are supported', () => {
    const r = new ScrollRouter({ allowTrackpad: true });
    assert.deepEqual(r.push({ deltaX: 0, deltaY: 100 }, true), { kind: 'wheel', x: 0, y: -1 });
});

test('line and page mode are never treated as a trackpad', () => {
    // Firefox reports wheels in line mode. Converted to pixels these can be small, and
    // classifying them as a precise device would send TRACKPAD to a peer that may ignore it.
    const r = new ScrollRouter({ allowTrackpad: true });
    assert.equal(r.push({ deltaX: 0, deltaY: 16 }, false), null, 'accumulates as a wheel');
    assert.deepEqual(r.push({ deltaX: 0, deltaY: 84 }, false), { kind: 'wheel', x: 0, y: -1 });
});

test('trackpad output is suppressed by default', () => {
    // The default must be the safe one: a host that predates TRACKPAD ignores it silently,
    // which is indistinguishable from broken scrolling.
    const r = new ScrollRouter();
    for (let i = 0; i < 5; i++) assert.equal(r.push({ deltaX: 0, deltaY: 5 }, true), null);
});

test('sub-pixel trackpad deltas accumulate rather than rounding to zero', () => {
    // macOS momentum scrolling ends in a tail of fractional deltas.
    const r = new ScrollRouter({ allowTrackpad: true });
    assert.equal(r.push({ deltaX: 0, deltaY: 0.4 }, true), null);
    assert.deepEqual(r.push({ deltaX: 0, deltaY: 0.7 }, true), { kind: 'trackpad', x: 0, y: -1 });
});

test('reset clears carried remainders between sessions', () => {
    const r = new ScrollRouter();
    r.push({ deltaX: 0, deltaY: 90 }, false);
    r.reset();
    assert.equal(r.push({ deltaX: 0, deltaY: 90 }, false), null, 'no carry from the old session');
});

test('a zero delta produces nothing', () => {
    assert.equal(new ScrollRouter().push({ deltaX: 0, deltaY: 0 }, true), null);
});

test('the trackpad gate is conservative about peer versions', () => {
    // Wrong in this direction is coarse scrolling; wrong in the other is none at all.
    assert.equal(supportsTrackpadScroll({ version: '1.4.0' }), true);
    assert.equal(supportsTrackpadScroll({ version: '1.2.3' }), false);
    assert.equal(supportsTrackpadScroll({}), false, 'an unknown peer gets the safe path');
});

test('coordinates map into the peer virtual desktop, including negative origins', () => {
    // Taken from a real four-monitor peer: display 0 sits left of and above the primary.
    const left = { x: -4480, y: -76, scale: 1 };
    assert.deepEqual(toVirtualDesktop(left, 0, 0), { x: -4480, y: -76 });
    assert.deepEqual(toVirtualDesktop(left, 100, 200), { x: -4380, y: 124 });

    const primary = { x: 0, y: 0, scale: 1 };
    assert.deepEqual(toVirtualDesktop(primary, 960, 540), { x: 960, y: 540 });

    const right = { x: 1920, y: 0, scale: 1 };
    assert.deepEqual(toVirtualDesktop(right, 10, 10), { x: 1930, y: 10 });
});

test('HiDPI scale divides the in-display offset', () => {
    // macOS and some Linux hosts inject in logical points, not physical pixels.
    assert.deepEqual(toVirtualDesktop({ x: 0, y: 0, scale: 2 }, 400, 300), { x: 200, y: 150 });
    // A missing or zero scale must not divide by zero.
    assert.deepEqual(toVirtualDesktop({ x: 0, y: 0 }, 400, 300), { x: 400, y: 300 });
    assert.deepEqual(toVirtualDesktop({ x: 0, y: 0, scale: 0 }, 400, 300), { x: 400, y: 300 });
});

test('modifiers are emitted in the reference order', () => {
    assert.deepEqual(
        modifiersOf({ altKey: true, shiftKey: true, ctrlKey: true, metaKey: true }),
        [ControlKey.Alt, ControlKey.Shift, ControlKey.Control, ControlKey.Meta],
    );
    assert.deepEqual(modifiersOf({}), []);
});

test('view-only mode emits nothing at all', () => {
    const { sent, fn } = capture();
    const enc = new MouseEncoder(fn, { viewOnly: true });
    assert.equal(enc.move(1, 1), false);
    assert.equal(enc.down(MouseButton.LEFT, 1, 1), false);
    assert.equal(enc.wheel(0, 1), false);
    assert.equal(sent.length, 0);
    assert.equal(enc.sent, 0);
});

/* -------------------------------------------------------------------------- */
/* Keyboard                                                                   */
/* -------------------------------------------------------------------------- */

test('F-keys map to their non-contiguous enum values', () => {
    // F1=9, then F10/F11/F12, then F2..F9 at 13..20 — alphabetical ordering upstream.
    assert.equal(namedKey('F1'), 9);
    assert.equal(namedKey('F10'), 10);
    assert.equal(namedKey('F12'), 12);
    assert.equal(namedKey('F2'), 13);
    assert.equal(namedKey('F9'), 20);
});

test('named keys cover the non-printable set', () => {
    assert.equal(namedKey('Enter'), ControlKey.Return);
    assert.equal(namedKey('ArrowLeft'), ControlKey.LeftArrow);
    assert.equal(namedKey('Escape'), ControlKey.Escape);
    assert.equal(namedKey('ScrollLock'), ControlKey.Scroll);
    assert.equal(namedKey('NumLock'), ControlKey.NumLock);
    assert.equal(namedKey('Meta'), ControlKey.Meta);
    assert.equal(namedKey('a'), null, 'printable keys go through seq, not control_key');
});

test('printable detection handles astral characters', () => {
    assert.equal(isPrintable('a'), true);
    assert.equal(isPrintable('é'), true);
    assert.equal(isPrintable('😀'), true, 'a surrogate pair is still one character');
    assert.equal(isPrintable('Enter'), false);
});

test('a key does not report itself as a held modifier', () => {
    // Reporting Shift as held while sending Shift confuses host state synchronisation.
    const ev = { shiftKey: true, ctrlKey: true, key: 'Shift' };
    assert.deepEqual(modifiersFor(ev, ControlKey.Shift), [ControlKey.Control]);
    assert.deepEqual(modifiersFor(ev, null), [ControlKey.Shift, ControlKey.Control]);
});

test('CapsLock is reported for letters only', () => {
    const withLocks = (key, code) => ({
        key, code, getModifierState: (n) => n === 'CapsLock' || n === 'NumLock',
    });
    assert.deepEqual(modifiersFor(withLocks('a', 'KeyA')), [ControlKey.CapsLock]);
    assert.deepEqual(modifiersFor(withLocks('1', 'Digit1')), [], 'not for digits');
    assert.deepEqual(modifiersFor(withLocks('Enter', 'Enter')), [], 'not for named keys');
});

test('NumLock is reported for numpad keys only', () => {
    const ev = { key: '1', code: 'Numpad1', getModifierState: (n) => n === 'NumLock' };
    assert.deepEqual(modifiersFor(ev), [ControlKey.NumLock]);
});

test('a named key round-trips as control_key in Legacy mode', () => {
    const { sent, fn } = capture();
    new KeyboardEncoder(fn).key(ControlKey.Return, true);
    const k = sent[0].key_event;
    assert.equal(sent[0].$case, 'key_event');
    assert.equal(k.$case, 'control_key');
    assert.equal(k.control_key, ControlKey.Return);
    assert.equal(k.down, true);
    assert.equal(k.press, undefined, 'press:false is the default and is not emitted');
    assert.equal(k.mode, undefined, 'Legacy is 0, the proto3 default');
});

test('tap sets press, which the host expands to a down/up pair', () => {
    const { sent, fn } = capture();
    new KeyboardEncoder(fn).tap(ControlKey.F5);
    const k = sent[0].key_event;
    assert.equal(k.control_key, ControlKey.F5);
    assert.equal(k.press, true);
});

test('text uses seq in Translate mode so layout mismatch does not matter', () => {
    const { sent, fn } = capture();
    new KeyboardEncoder(fn).text('héllo');
    const k = sent[0].key_event;
    assert.equal(k.$case, 'seq');
    assert.equal(k.seq, 'héllo');
    assert.equal(k.mode, KeyboardMode.Translate);
    assert.equal(k.press, true);
});

test('empty text is not emitted', () => {
    const { sent, fn } = capture();
    assert.equal(new KeyboardEncoder(fn).text(''), false);
    assert.equal(sent.length, 0);
});

test('releaseAll clears every held key — the fix for sticky modifiers on blur', () => {
    // Hold Shift, switch tabs, come back: without this the remote stays shifted forever
    // and every later keystroke is wrong.
    const { sent, fn } = capture();
    const enc = new KeyboardEncoder(fn);
    enc.key(ControlKey.Shift, true);
    enc.key(ControlKey.Control, true);
    assert.equal(enc.held.size, 2);

    sent.length = 0;
    enc.releaseAll();

    assert.equal(enc.held.size, 0);
    assert.equal(sent.length, 2);
    for (const s of sent) assert.equal(s.key_event.down, undefined, 'down:false is the default');
});

test('a released key leaves the held set', () => {
    const { fn } = capture();
    const enc = new KeyboardEncoder(fn);
    enc.key(ControlKey.Alt, true);
    enc.key(ControlKey.Alt, false);
    assert.equal(enc.held.size, 0);
});

test('modifier classification matches the host state-tracking set', () => {
    for (const c of [ControlKey.Alt, ControlKey.Control, ControlKey.Shift, ControlKey.Meta,
        ControlKey.RAlt, ControlKey.RControl, ControlKey.RShift, ControlKey.RWin]) {
        assert.equal(isModifier(c), true, `${c} should be a modifier`);
    }
    assert.equal(isModifier(ControlKey.Return), false);
    assert.equal(isModifier(ControlKey.CapsLock), false, 'a lock is not a held modifier');
});

test('keyboard view-only mode emits nothing', () => {
    const { sent, fn } = capture();
    const enc = new KeyboardEncoder(fn, { viewOnly: true });
    assert.equal(enc.key(ControlKey.Return, true), false);
    assert.equal(enc.text('hello'), false);
    assert.equal(sent.length, 0);
});
