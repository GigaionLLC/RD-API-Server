/**
 * Notice text conformance.
 *
 * These strings are the only account an operator gets of why a session that looks fine is
 * not working. The tests care about two things: that a state which silently swallows input
 * always produces a notice, and that a repeated peer message maps to a stable key so the
 * UI refreshes one card rather than stacking hundreds.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    describeMessageBox, describeDenials, describeElevation, describeElevationResponse,
} from '../../src/session/notices.js';

/* -------------------------------------------------------------------------- */
/* MessageBox                                                                 */
/* -------------------------------------------------------------------------- */

test('severity is read out of the hyphenated msgtype', () => {
    assert.equal(describeMessageBox({ msgtype: 'error' }).severity, 'error');
    assert.equal(describeMessageBox({ msgtype: 'custom-error-nocancel' }).severity, 'error');
    assert.equal(describeMessageBox({ msgtype: 'custom-nocancel' }).severity, 'info');
    assert.equal(describeMessageBox({ msgtype: '' }).severity, 'info');
});

test('a msgtype we have never seen still produces a readable notice', () => {
    // The peer decides this string. An unknown one must degrade to "something happened",
    // not to an empty card.
    const n = describeMessageBox({ msgtype: 'something-new', text: 'Details here' });
    assert.ok(n.title, 'a title is always present');
    assert.equal(n.detail, 'Details here');
    assert.equal(n.severity, 'info');
});

test('a password request is flagged so the UI can focus the field', () => {
    assert.equal(describeMessageBox({ msgtype: 're-input-password' }).prompt, 'password');
    assert.equal(describeMessageBox({ msgtype: 'input-password' }).prompt, 'password');
    assert.equal(describeMessageBox({ msgtype: 'custom-error' }).prompt, null);
});

test('the same box maps to the same key so it refreshes one card', () => {
    // Peers repeat their "waiting for acceptance" box for as long as they wait.
    const a = describeMessageBox({ msgtype: 'custom', title: 'Waiting', text: 'Accept?' });
    const b = describeMessageBox({ msgtype: 'custom', title: 'Waiting', text: 'Accept?' });
    assert.equal(a.key, b.key);
    assert.notEqual(a.key, describeMessageBox({ msgtype: 'custom', title: 'Other' }).key);
});

test('the peer-supplied link is carried as text, never as a target', () => {
    const n = describeMessageBox({ msgtype: 'error', link: 'javascript:alert(1)' });
    assert.equal(n.link, 'javascript:alert(1)', 'carried verbatim for display');
    // The guarantee lives in the viewer, which sets textContent. This test exists so that
    // anyone who later turns `link` into an href has to delete a test that says not to.
});

test('whitespace-only peer text does not produce a blank notice', () => {
    const n = describeMessageBox({ msgtype: 'error', title: '   ', text: '  ' });
    assert.ok(n.title.length > 3);
    assert.equal(n.detail, '');
});

/* -------------------------------------------------------------------------- */
/* Permission denials                                                         */
/* -------------------------------------------------------------------------- */

test('every permission the protocol defines has operator-facing text', () => {
    const names = ['Keyboard', 'Clipboard', 'Audio', 'File', 'Restart', 'Recording',
        'BlockInput', 'PrivacyMode'];
    for (const n of describeDenials(names)) {
        assert.ok(n.title && n.detail, `${n.key} must be explained`);
        assert.equal(n.severity, 'warn');
    }
    assert.equal(describeDenials(names).length, names.length);
});

test('a denial we do not recognise is still reported', () => {
    // Permission is an enum the peer may extend before we do.
    const [n] = describeDenials(['SomethingNew']);
    assert.match(n.title, /SomethingNew/);
});

test('denial keys are stable and distinct so banners can be reconciled', () => {
    const keys = describeDenials(['Keyboard', 'Clipboard']).map((n) => n.key);
    assert.deepEqual(keys, ['perm:Keyboard', 'perm:Clipboard']);
});

test('no denials means no notices', () => {
    assert.deepEqual(describeDenials([]), []);
    assert.deepEqual(describeDenials(), []);
});

/* -------------------------------------------------------------------------- */
/* Elevation                                                                  */
/* -------------------------------------------------------------------------- */

test('a UAC prompt is always surfaced', () => {
    // The secure desktop discards injected input entirely, so without this the operator
    // sees a dialog that ignores every click and concludes the session has frozen.
    const n = describeElevation({ uac: true });
    assert.equal(n.key, 'elev:uac');
    assert.equal(n.action, 'elevate');
    assert.match(n.detail, /secure desktop/i);
});

test('a portable service is not offered an elevation it cannot perform', () => {
    const n = describeElevation({ uac: true, portable: true });
    assert.ok(!/elevate this session/i.test(n.detail), 'portable services cannot elevate');
    assert.match(n.detail, /someone at the machine/i);
});

test('an elevated foreground window is distinguished from a UAC prompt', () => {
    // Different remedy: this one needs the session elevated before that window is usable.
    const n = describeElevation({ elevated: true });
    assert.equal(n.key, 'elev:foreground');
    assert.match(n.detail, /ignores input/i);
});

test('UAC wins when both are set', () => {
    // A consent dialog is on top of whatever raised it; naming the window underneath
    // would point the operator at the wrong thing.
    assert.equal(describeElevation({ uac: true, elevated: true }).key, 'elev:uac');
});

test('a clear elevation state produces no notice', () => {
    assert.equal(describeElevation({}), null);
    assert.equal(describeElevation(), null);
});

test('an empty elevation response means success', () => {
    // The peer answers with an error string; empty is the success case, which is easy to
    // invert and would report every successful elevation as a failure.
    assert.equal(describeElevationResponse('').severity, 'info');
    assert.equal(describeElevationResponse('   ').severity, 'info');
});

test('a refusal keeps the peer’s own wording', () => {
    // "Cancelled by the user" and "wrong credentials" lead somewhere different; a
    // paraphrase collapses them.
    const n = describeElevationResponse('logon failure: unknown user name or bad password');
    assert.equal(n.severity, 'error');
    assert.match(n.detail, /bad password/);
});
