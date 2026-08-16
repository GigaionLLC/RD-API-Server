/**
 * Audio output ring buffer, running on the audio rendering thread.
 *
 * Spec: docs/spec/04-media-input.md §5.
 *
 * The protocol gives audio NO timestamps and NO sequence numbers, so there is nothing to
 * synchronise against — playback is arrival-ordered, and this buffer absorbs jitter.
 *
 * Two properties the protocol forces:
 *
 *  - Gaps are NORMAL. The peer stops transmitting entirely after roughly 8 seconds of
 *    silence and resumes on the next non-zero sample, and it drops any frame that has sat
 *    in its queue for more than a second. A buffer that resets or reports an error on
 *    underrun would click constantly during ordinary quiet stretches.
 *  - Underrun therefore fills with silence and keeps going. It is not an error condition.
 *
 * Loaded as an AudioWorklet module, so it must not import anything.
 */

const TARGET_SECONDS = 3;

class RingProcessor extends AudioWorkletProcessor {
    constructor(options) {
        super();
        const channels = options?.processorOptions?.channels ?? 2;
        // Enough to ride out a network stall without adding seconds of latency on top.
        const capacity = Math.ceil(sampleRate * TARGET_SECONDS);

        this.channels = channels;
        this.capacity = capacity;
        this.buffers = Array.from({ length: channels }, () => new Float32Array(capacity));
        this.read = 0;
        this.write = 0;
        this.available = 0;
        this.underruns = 0;
        this.dropped = 0;
        this.muted = false;

        this.port.onmessage = (ev) => {
            const msg = ev.data;
            if (msg.type === 'audio') this.enqueue(msg.planes);
            else if (msg.type === 'mute') this.muted = msg.value;
            else if (msg.type === 'stats') this.report();
        };
    }

    /** @param {Float32Array[]} planes One array per channel. */
    enqueue(planes) {
        const frames = planes[0]?.length ?? 0;
        if (!frames) return;

        // Overflow means the consumer is behind by more than the buffer holds; dropping
        // the oldest audio is the only option that keeps latency bounded.
        if (this.available + frames > this.capacity) {
            const overflow = this.available + frames - this.capacity;
            this.read = (this.read + overflow) % this.capacity;
            this.available -= overflow;
            this.dropped += overflow;
        }

        for (let ch = 0; ch < this.channels; ch++) {
            const src = planes[Math.min(ch, planes.length - 1)];
            const dst = this.buffers[ch];
            let w = this.write;
            for (let i = 0; i < frames; i++) {
                dst[w] = src[i];
                w = w + 1 === this.capacity ? 0 : w + 1;
            }
        }
        this.write = (this.write + frames) % this.capacity;
        this.available += frames;
    }

    report() {
        this.port.postMessage({
            type: 'stats',
            available: this.available,
            underruns: this.underruns,
            dropped: this.dropped,
            seconds: this.available / sampleRate,
        });
    }

    process(_inputs, outputs) {
        const out = outputs[0];
        const frames = out[0]?.length ?? 0;
        if (!frames) return true;

        const take = Math.min(frames, this.available);
        if (take < frames) this.underruns++;

        for (let ch = 0; ch < out.length; ch++) {
            const dst = out[ch];
            const src = this.buffers[Math.min(ch, this.channels - 1)];
            let r = this.read;
            for (let i = 0; i < take; i++) {
                dst[i] = this.muted ? 0 : src[r];
                r = r + 1 === this.capacity ? 0 : r + 1;
            }
            // Silence-fill the remainder rather than treating it as a fault.
            for (let i = take; i < frames; i++) dst[i] = 0;
        }

        this.read = (this.read + take) % this.capacity;
        this.available -= take;
        return true;
    }
}

registerProcessor('rd-audio-ring', RingProcessor);
