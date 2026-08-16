/**
 * WebCodecs video decoding.
 *
 * Spec: docs/spec/04-media-input.md §1.
 *
 * Three rules the protocol imposes, each of which produces a permanently black screen
 * rather than an error if broken:
 *
 *  1. The first chunk after `configure()` MUST be a key frame. Deltas reference frames
 *     the decoder never saw.
 *  2. H.264/H.265 are Annex-B with SPS/PPS/VPS in-band, repeated on key frames. There is
 *     no `description` field anywhere in the protocol, so the config must omit it.
 *  3. `EncodedVideoFrames.frames` is repeated and every entry must be decoded in order.
 *     Skipping one corrupts the reference chain until the next key frame.
 *
 * `pts` is deliberately not used as a presentation clock: it restarts near zero on every
 * refresh, codec change, display change or new subscriber. We synthesise a monotonic
 * timestamp instead, because WebCodecs requires increasing timestamps but the protocol
 * does not supply usable ones.
 */

import { decoderConfig } from './codec.js';

/** Microseconds per synthetic frame step. The value is arbitrary; monotonicity is not. */
const TIMESTAMP_STEP_US = 1000;

export class VideoStreamDecoder {
    /**
     * @param {object} opts
     * @param {(frame: VideoFrame) => void} opts.onFrame Receives a decoded frame; the
     *   caller MUST call `.close()` on it or the decoder stalls on buffer exhaustion.
     * @param {(err: Error, codec: string) => void} [opts.onError]
     * @param {() => void} [opts.onKeyFrameNeeded]
     * @param {typeof VideoDecoder} [opts.decoderClass] Injectable for testing.
     */
    constructor({ onFrame, onError, onKeyFrameNeeded, decoderClass = globalThis.VideoDecoder }) {
        this.onFrame = onFrame;
        this.onError = onError;
        this.onKeyFrameNeeded = onKeyFrameNeeded;
        this.DecoderClass = decoderClass;

        /** @type {VideoDecoder | null} */
        this.decoder = null;
        /** @type {string | null} */
        this.codec = null;
        /** Until a key frame arrives after (re)configure, deltas are unusable. */
        this.awaitingKeyFrame = true;
        this.timestamp = 0;
        this.decoded = 0;
        this.dropped = 0;
    }

    get queueSize() {
        return this.decoder?.decodeQueueSize ?? 0;
    }

    /**
     * @param {string} codec One of the CODEC_FAMILIES.
     */
    _configure(codec) {
        this._teardown();
        const decoder = new this.DecoderClass({
            output: (frame) => {
                this.decoded++;
                this.onFrame(frame);
            },
            error: (err) => {
                // A decoder error is terminal for this instance; recovery is a fresh
                // configure plus a key frame, which means asking the peer to refresh.
                this.awaitingKeyFrame = true;
                this.onError?.(err instanceof Error ? err : new Error(String(err)), codec);
                this.onKeyFrameNeeded?.();
            },
        });
        decoder.configure(decoderConfig(codec));
        this.decoder = decoder;
        this.codec = codec;
        this.awaitingKeyFrame = true;
    }

    /**
     * Feeds one `VideoFrame` message.
     *
     * @param {{codec: string, key: boolean, units: Array<{data: Uint8Array, key?: boolean}>}} frame
     * @returns {boolean} False when the message was dropped (no key frame yet).
     */
    decode(frame) {
        if (!this.DecoderClass) return false;

        // The oneof tag is the codec identifier and can change mid-session, so switching
        // is driven by what arrives rather than by what we requested.
        if (frame.codec !== this.codec) this._configure(frame.codec);

        if (this.awaitingKeyFrame) {
            if (!frame.key) {
                this.dropped++;
                return false;
            }
            this.awaitingKeyFrame = false;
        }

        for (const unit of frame.units) {
            // Per-unit `key` is authoritative; a message can carry a key frame followed
            // by deltas produced in the same capture tick.
            const type = unit.key ? 'key' : 'delta';
            this.timestamp += TIMESTAMP_STEP_US;
            try {
                this.decoder.decode(new EncodedVideoChunk({
                    type,
                    timestamp: this.timestamp,
                    data: unit.data,
                }));
            } catch (err) {
                this.awaitingKeyFrame = true;
                this.onError?.(err instanceof Error ? err : new Error(String(err)), frame.codec);
                this.onKeyFrameNeeded?.();
                return false;
            }
        }
        return true;
    }

    /** Marks the stream as needing a key frame, e.g. after requesting a refresh. */
    reset() {
        this.awaitingKeyFrame = true;
    }

    _teardown() {
        if (!this.decoder) return;
        try {
            if (this.decoder.state !== 'closed') this.decoder.close();
        } catch {
            // Closing an already-errored decoder throws; nothing to recover.
        }
        this.decoder = null;
    }

    close() {
        this._teardown();
        this.codec = null;
        this.awaitingKeyFrame = true;
    }

    stats() {
        return {
            codec: this.codec,
            decoded: this.decoded,
            dropped: this.dropped,
            queueSize: this.queueSize,
            awaitingKeyFrame: this.awaitingKeyFrame,
        };
    }
}
