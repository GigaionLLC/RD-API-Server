/**
 * Reconnection policy conformance.
 *
 * The dangerous half of a retry policy is what it retries. Re-attempting a wrong password
 * walks the peer's failed-attempt counter toward a lockout on the operator's behalf, and
 * re-attempting a refused encryption downgrade invites whatever is doing the downgrading
 * to keep trying. So these tests care more about what is refused than what is retried.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { ReconnectPolicy, isRetryable, explain } from '../../src/session/retry.js';

/* -------------------------------------------------------------------------- */
/* Classification                                                             */
/* -------------------------------------------------------------------------- */

test('transient transport failures are retried', () => {
    assert.equal(isRetryable({ code: 'transport' }), true);
    assert.equal(isRetryable({ code: 'protocol' }), true);
    assert.equal(isRetryable({ code: 'offline' }), true);
});

test('a wrong password is never retried', () => {
    // The peer counts failed attempts and locks out. Retrying spends the operator's
    // remaining attempts for them.
    assert.equal(isRetryable({ code: 'login_failed', message: 'Wrong Password' }), false);
});

test('a refused encryption downgrade is never retried', () => {
    // Retrying would eventually be answered by whatever stripped the encryption.
    assert.equal(isRetryable({ code: 'encryption_required' }), false);
});

test('settled failures are not retried', () => {
    for (const code of ['refused', 'rendezvous_failed', 'no_relay', 'no_displays',
        'closed_by_peer', 'not_connected']) {
        assert.equal(isRetryable({ code }), false, `${code} must not retry`);
    }
});

test('a transport failure at connect time is classified, not left bare', () => {
    // TransportError carries no `code`. Letting it escape unwrapped made the single most
    // common failure — an unreachable or restarting server — look fatal, so auto-reconnect
    // never fired for the case it exists to handle.
    assert.equal(isRetryable({ name: 'TransportError', message: 'cannot connect to ws://host:21118' }), false,
        'a bare TransportError is correctly refused; the session must wrap it');
    assert.equal(isRetryable({ code: 'transport', message: 'cannot connect to ws://host:21118' }), true);
});

test('an unknown error code defaults to not retrying', () => {
    // Fail safe: a new failure mode should not silently acquire a retry loop.
    assert.equal(isRetryable({ code: 'something_new' }), false);
    assert.equal(isRetryable({}), false);
    assert.equal(isRetryable(null), false);
});

test('an explicit retryable flag overrides the classification', () => {
    assert.equal(isRetryable({ code: 'login_failed', retryable: true }), true);
    assert.equal(isRetryable({ code: 'transport', retryable: false }), false);
});

/* -------------------------------------------------------------------------- */
/* Backoff                                                                    */
/* -------------------------------------------------------------------------- */

test('delays grow exponentially and are capped', () => {
    const p = new ReconnectPolicy({ jitter: false, baseDelayMs: 1000, maxDelayMs: 8000, maxAttempts: 10 });
    assert.deepEqual(
        [p.nextDelay(), p.nextDelay(), p.nextDelay(), p.nextDelay(), p.nextDelay()],
        [1000, 2000, 4000, 8000, 8000],
    );
});

test('full jitter spreads attempts across the whole window', () => {
    // Several viewers behind one relay would otherwise reconnect in lockstep and arrive
    // together, precisely when the relay is least able to take them.
    const lo = new ReconnectPolicy({ baseDelayMs: 1000, random: () => 0 });
    const hi = new ReconnectPolicy({ baseDelayMs: 1000, random: () => 1 });
    assert.equal(lo.nextDelay(), 0);
    assert.equal(hi.nextDelay(), 1000);

    const mid = new ReconnectPolicy({ baseDelayMs: 1000, random: () => 0.5 });
    mid.nextDelay();
    assert.equal(mid.nextDelay(), 1000, 'second attempt draws from a 2000ms window');
});

test('attempts are bounded so a dead peer does not retry forever', () => {
    const p = new ReconnectPolicy({ maxAttempts: 3, jitter: false });
    const err = { code: 'transport' };
    let made = 0;
    while (p.shouldRetry(err)) { p.nextDelay(); made++; }
    assert.equal(made, 3);
    assert.equal(p.exhausted, true);
    assert.equal(p.attemptsRemaining, 0);
});

test('a fatal error is refused even with attempts remaining', () => {
    const p = new ReconnectPolicy({ maxAttempts: 5 });
    assert.equal(p.shouldRetry({ code: 'login_failed' }), false);
    assert.equal(p.attempt, 0, 'a refused retry must not consume an attempt');
});

test('reset restores the short delay after a successful reconnect', () => {
    // A session that reconnects, runs for an hour, then drops should not wait 30 seconds.
    const p = new ReconnectPolicy({ jitter: false, baseDelayMs: 1000, maxAttempts: 10 });
    p.nextDelay(); p.nextDelay(); p.nextDelay();
    assert.equal(p.attempt, 3);
    p.reset();
    assert.equal(p.attempt, 0);
    assert.equal(p.nextDelay(), 1000);
});

/* -------------------------------------------------------------------------- */
/* Human-readable failures                                                    */
/* -------------------------------------------------------------------------- */

test('errors are explained without exposing internals', () => {
    const t = explain({ code: 'transport', message: 'socket closed (code 1006)' });
    assert.equal(t.title, 'Connection lost');
    assert.ok(!/1006/.test(t.detail), 'a close code means nothing to an operator');
    assert.equal(t.retryable, true);
});

test('waiting for approval is not presented as an error', () => {
    // The peer is in click-to-accept mode; someone needs to press a button, and telling
    // the operator "sign-in refused" would send them chasing a password problem.
    const e = explain({ code: 'login_failed', message: 'No Password Access' });
    assert.match(e.detail, /accept/i);
    assert.ok(!/wrong/i.test(e.title));
});

test('a wrong password and a key mismatch are distinguished', () => {
    assert.equal(explain({ code: 'login_failed', message: 'Wrong Password' }).title, 'Wrong password');
    const mismatch = explain({ code: 'rendezvous_failed', message: 'LICENSE_MISMATCH' });
    assert.match(mismatch.detail, /server key/i);
});

test('an unknown device is named as such', () => {
    assert.equal(explain({ code: 'rendezvous_failed', message: 'ID_NOT_EXIST' }).title, 'Unknown device');
});

test('every explanation carries a title, detail and retry verdict', () => {
    for (const code of ['transport', 'offline', 'login_failed', 'encryption_required',
        'rendezvous_failed', 'refused', 'closed_by_peer', 'no_displays', 'no_relay', 'mystery']) {
        const e = explain({ code, message: 'x' });
        assert.ok(e.title && e.detail, `${code} must be explained`);
        assert.equal(typeof e.retryable, 'boolean');
    }
});
