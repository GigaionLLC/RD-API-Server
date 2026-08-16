/**
 * Per-display inbound video admission control.
 *
 * Spec: docs/spec/04-media-input.md §1.8.
 *
 * This is the only bounded stage between the socket and the decoder, and it exists
 * because nothing else in the pipeline pushes back. The `video_received` ACK cannot serve
 * that purpose: the peer will not capture the next frame until it arrives, so withholding
 * it collapses the stream to one frame every three seconds. The ACK therefore always goes
 * out immediately, and the bound lives HERE — on what we hand the decoder.
 *
 * Four behaviours, each load-bearing:
 *
 *  - A message containing a key frame BYPASSES the queue and clears the discard flag.
 *    Key frames are the only recovery point, so they must never queue behind stale
 *    deltas.
 *  - While discarding (after a refresh request, a decode failure, or decoder
 *    backpressure), non-key messages are dropped. Feeding the decoder deltas whose
 *    reference frames were never decoded produces either garbage or a hard error.
 *  - Live video is dropped, not buffered. A backlog of stale frames is worth less than
 *    the latency it adds, so the ring is small and overflow discards rather than drains.
 *  - Overflow means the pipeline is unrecoverably behind, and the only fix in this
 *    protocol is a refresh — there is no lightweight "resend" request.
 *
 * Refresh is expensive and GLOBAL: it restarts the peer's capture pipeline for every
 * viewer of that display, not just us. Hence the rate limit — at most 20 per display per
 * session, and no more than one per 10 seconds. The decoder asks for a key frame on every
 * error, so an unlimited path would hammer the host through a bad stream.
 */

export const DEFAULT_CAPACITY = 120;
export const MAX_REFRESHES = 20;
export const REFRESH_INTERVAL_MS = 10_000;

/**
 * @typedef {object} QueuedFrame
 * @property {number} display
 * @property {boolean} key
 * @property {Array<{data: Uint8Array, key?: boolean, pts?: bigint}>} units
 */

export class FrameQueue {
    /**
     * @param {object} [opts]
     * @param {number} [opts.capacity]
     * @param {() => number} [opts.now] Injected clock, so rate limiting is testable.
     */
    constructor({ capacity = DEFAULT_CAPACITY, now = () => Date.now() } = {}) {
        this.capacity = capacity;
        this.now = now;
        /** @type {QueuedFrame[]} */
        this.items = [];
        this.discarding = false;
        this.refreshCount = 0;
        this.lastRefreshAt = -Infinity;
        this.droppedWhileDiscarding = 0;
        this.overflowed = 0;
        this.backpressureEvents = 0;
    }

    get length() {
        return this.items.length;
    }

    /**
     * @param {QueuedFrame} frame
     * @returns {{deliver: QueuedFrame | null, needsRefresh: boolean}}
     *   `deliver` is a frame to hand the decoder immediately (key frames bypass the
     *   queue). `needsRefresh` means the caller should request one, subject to `mayRefresh`.
     */
    push(frame) {
        if (frame.key) {
            this.discarding = false;
            this.items.length = 0; // stale deltas are worthless once a key frame arrives
            return { deliver: frame, needsRefresh: false };
        }

        if (this.discarding) {
            this.droppedWhileDiscarding++;
            return { deliver: null, needsRefresh: false };
        }

        this.items.push(frame);
        if (this.items.length > this.capacity) {
            // Evicting means we are behind by more than the ring holds. Deltas are now
            // missing, so nothing in the queue can be decoded until the next key frame.
            this.items.length = 0;
            this.overflowed++;
            this.discarding = true;
            return { deliver: null, needsRefresh: true };
        }

        return { deliver: null, needsRefresh: false };
    }

    /** @returns {QueuedFrame | undefined} */
    shift() {
        return this.items.shift();
    }

    /**
     * Whether a refresh may be sent now. Refresh restarts the peer's whole capture
     * pipeline for this display and affects every viewer, so it is deliberately scarce.
     */
    mayRefresh() {
        if (this.refreshCount >= MAX_REFRESHES) return false;
        return this.now() - this.lastRefreshAt >= REFRESH_INTERVAL_MS;
    }

    /** Records that a refresh was sent, and starts discarding until the next key frame. */
    markRefreshed() {
        this.refreshCount++;
        this.lastRefreshAt = this.now();
        this.discarding = true;
        this.items.length = 0;
    }

    /** Called when a decode fails — the stream needs a new reference point. */
    markDecodeFailure() {
        this.discarding = true;
        this.items.length = 0;
    }

    /**
     * Called when the decoder is too far behind to accept more work.
     *
     * Distinct from a decode failure only in intent: nothing is broken, we are simply
     * decoding slower than the network delivers. Dropping to the next key frame is the
     * one recovery this protocol offers, since there is no way to ask for less.
     */
    markBackpressure() {
        this.backpressureEvents++;
        this.discarding = true;
        this.items.length = 0;
    }

    stats() {
        return {
            depth: this.items.length,
            discarding: this.discarding,
            refreshCount: this.refreshCount,
            overflowed: this.overflowed,
            droppedWhileDiscarding: this.droppedWhileDiscarding,
            backpressureEvents: this.backpressureEvents,
        };
    }
}
