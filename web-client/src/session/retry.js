/**
 * Reconnection policy.
 *
 * Every session is relayed — a browser cannot hole-punch — so a session crosses two
 * sockets and a relay that may be shared, rate limited or simply restarted. Transient
 * failures are normal, and without this every one of them ends the session and asks the
 * operator to start again.
 *
 * What must NOT be retried is the larger half of the design. Re-attempting a wrong
 * password walks the peer's failed-attempt counter toward a lockout on the operator's
 * behalf; re-attempting a refused or unknown peer is pure noise; and re-attempting a
 * refused encryption downgrade would eventually be answered by whatever is doing the
 * downgrading. So the default is "do not retry", and only explicitly transient failures
 * opt in.
 */

/**
 * Failure classes worth another attempt.
 *
 *  - `transport`  the socket dropped, timed out, or never opened. The overwhelmingly
 *                 common case, and the one this whole module exists for.
 *  - `protocol`   an unexpected message at handshake time. Usually a half-open socket
 *                 being reused by the server; a fresh connection generally succeeds.
 *  - `offline`    the peer was not registered at that moment. It may come back, and an
 *                 operator watching a rebooting machine expects the session to catch it.
 */
const RETRYABLE_CODES = new Set(['transport', 'protocol', 'offline']);

/**
 * Failures that are settled, and where retrying is actively harmful or merely rude.
 * Listed explicitly rather than inferred, so a new error code defaults to no-retry.
 */
const FATAL_CODES = new Set([
    'login_failed', // wrong password or 2FA; retrying walks toward a peer-side lockout
    'encryption_required', // refusing a downgrade; retrying invites the downgrader to persist
    'refused', // the peer said no
    'rendezvous_failed', // unknown id, licence mismatch, licence overuse
    'no_relay', // peer offered a direct connection a browser cannot use
    'no_displays', // nothing to show
    'closed_by_peer', // the far end ended it deliberately
    'not_connected', // caller error
]);

/**
 * @param {{code?: string, retryable?: boolean}} err
 * @returns {boolean}
 */
export function isRetryable(err) {
    if (!err) return false;
    // An explicit flag on the error wins, so a caller can classify something we cannot.
    if (typeof err.retryable === 'boolean') return err.retryable;
    const code = err.code ?? '';
    if (FATAL_CODES.has(code)) return false;
    return RETRYABLE_CODES.has(code);
}

export const DEFAULT_POLICY = {
    maxAttempts: 6,
    baseDelayMs: 1_000,
    maxDelayMs: 30_000,
    // Full jitter. Several viewers watching peers behind one relay will otherwise all
    // reconnect on the same schedule and arrive together, which is exactly when the relay
    // is least able to take them.
    jitter: true,
};

export class ReconnectPolicy {
    /**
     * @param {Partial<typeof DEFAULT_POLICY> & {random?: () => number}} [opts]
     */
    constructor(opts = {}) {
        this.maxAttempts = opts.maxAttempts ?? DEFAULT_POLICY.maxAttempts;
        this.baseDelayMs = opts.baseDelayMs ?? DEFAULT_POLICY.baseDelayMs;
        this.maxDelayMs = opts.maxDelayMs ?? DEFAULT_POLICY.maxDelayMs;
        this.jitter = opts.jitter ?? DEFAULT_POLICY.jitter;
        this.random = opts.random ?? Math.random;
        this.attempt = 0;
    }

    /** Called after a successful connect, so a later failure starts from a short delay. */
    reset() {
        this.attempt = 0;
    }

    /**
     * @param {{code?: string, retryable?: boolean}} err
     * @returns {boolean} Whether another attempt should be made for this failure.
     */
    shouldRetry(err) {
        return isRetryable(err) && this.attempt < this.maxAttempts;
    }

    /**
     * Consumes an attempt and returns how long to wait before it.
     * @returns {number} milliseconds
     */
    nextDelay() {
        const exponential = Math.min(this.maxDelayMs, this.baseDelayMs * 2 ** this.attempt);
        this.attempt++;
        if (!this.jitter) return exponential;
        // Full jitter rather than a fraction: it spreads a thundering herd across the
        // whole window instead of compressing it into the tail.
        return Math.round(this.random() * exponential);
    }

    get attemptsRemaining() {
        return Math.max(0, this.maxAttempts - this.attempt);
    }

    get exhausted() {
        return this.attempt >= this.maxAttempts;
    }
}

/**
 * Turns a failure into something worth showing an operator.
 *
 * The raw values are internals — "socket closed (code 1006)", "OFFLINE", "secretbox
 * authentication failed at receive counter 7" — and every one of them has generated a
 * support question that the deployment guide already answers.
 *
 * @param {{code?: string, message?: string}} err
 * @returns {{title: string, detail: string, retryable: boolean}}
 */
export function explain(err) {
    const code = err?.code ?? '';
    const message = err?.message ?? 'Unknown error';
    const retryable = isRetryable(err);

    switch (code) {
        case 'transport':
            return {
                title: 'Connection lost',
                detail: 'The connection to the relay dropped. This is usually temporary.',
                retryable,
            };
        case 'offline':
            return {
                title: 'Device is offline',
                detail: 'The device is not connected to the server right now.',
                retryable,
            };
        case 'login_failed': {
            // Order matters: "No Password Access" contains the word "password" but is not
            // a credential failure at all — the peer is asking a human to approve the
            // session. Matching the generic password test first would send the operator
            // to chase a problem that does not exist.
            if (/no password access/i.test(message)) {
                return {
                    title: 'Waiting for approval',
                    detail: 'The device is set to ask before allowing access. Someone at the device needs to accept.',
                    retryable,
                };
            }
            if (/2fa/i.test(message)) {
                return {
                    title: 'Two-factor code required',
                    detail: 'This device requires a second factor, which this client cannot yet supply.',
                    retryable,
                };
            }
            return {
                title: /password/i.test(message) ? 'Wrong password' : 'Sign-in refused',
                detail: message,
                retryable,
            };
        }
        case 'encryption_required':
            return {
                title: 'Refused: connection is not encrypted',
                detail: 'The device could not be verified, so the session was stopped rather than '
                    + 'continue unencrypted. Check that the server key matches the device.',
                retryable,
            };
        case 'rendezvous_failed':
            return {
                title: /exist/i.test(message) ? 'Unknown device' : 'Server refused the connection',
                detail: /mismatch/i.test(message)
                    ? 'The server key configured here does not match the ID server.'
                    : message,
                retryable,
            };
        case 'refused':
            return { title: 'Device refused the connection', detail: message, retryable };
        case 'closed_by_peer':
            return { title: 'Session ended by the device', detail: message, retryable };
        case 'no_displays':
            return {
                title: 'No screen available',
                detail: 'The device reported no displays. It may be at a lock screen or headless.',
                retryable,
            };
        case 'no_relay':
            return {
                title: 'Relay unavailable',
                detail: 'The device offered a direct connection, which a browser cannot use.',
                retryable,
            };
        default:
            return { title: 'Connection failed', detail: message, retryable };
    }
}
