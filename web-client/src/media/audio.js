/**
 * Opus audio playback.
 *
 * Spec: docs/spec/04-media-input.md §5.
 *
 * The entire audio protocol is two messages: `Misc.audio_format` announcing sample rate
 * and channels, then `Message.audio_frame` carrying one raw Opus packet each. There is
 * no container, no codec field, no timestamp and no sequence number.
 *
 * Three consequences:
 *
 *  - `AudioFormat` always precedes the first frame and is re-sent whenever the peer's
 *    audio service restarts. The decoder must be rebuilt on every one — not once, and
 *    never per frame.
 *  - WebCodecs requires monotonically increasing timestamps, but the protocol supplies
 *    none, so they are synthesised. Frames are 10ms of the device's rate.
 *  - Never assume 480 samples per frame. The rate is quantised into
 *    {8000,12000,16000,24000,48000} and the decoder's returned count is authoritative.
 *
 * Playback needs a user gesture: browsers start an AudioContext suspended, so `unlock()`
 * must be called from a click or keypress or nothing is ever heard.
 */

const WORKLET_URL = new URL('./audio-worklet.js', import.meta.url);

/** Opus frame duration in microseconds. Used only to keep timestamps increasing. */
const FRAME_DURATION_US = 10_000;

export class AudioStreamPlayer {
    /**
     * @param {object} [opts]
     * @param {boolean} [opts.muted] Start muted. Useful when the peer is the local
     *   machine, where playing its own audio back creates a feedback loop.
     * @param {typeof AudioDecoder} [opts.decoderClass]
     */
    constructor({ muted = false, decoderClass = globalThis.AudioDecoder } = {}) {
        this.DecoderClass = decoderClass;
        this.muted = muted;
        /** @type {AudioContext | null} */
        this.context = null;
        /** @type {AudioWorkletNode | null} */
        this.node = null;
        /** @type {AudioDecoder | null} */
        this.decoder = null;
        this.format = null;
        this.timestamp = 0;
        this.packets = 0;
        this.decodedFrames = 0;
        this.samples = 0;
        this.errors = 0;
        /** @type {Uint8Array[]} Packets that arrived before the graph was ready. */
        this.pending = [];
    }

    get supported() {
        return typeof this.DecoderClass !== 'undefined' && typeof AudioContext !== 'undefined';
    }

    /**
     * Applies an `AudioFormat`. Rebuilds the decoder and, if the rate changed, the whole
     * audio graph — an AudioContext's sample rate is fixed at construction.
     * @param {{sample_rate?: number, channels?: number}} format
     */
    async setFormat(format) {
        if (!this.supported) return false;

        const sampleRate = format.sample_rate || 48_000;
        const channels = format.channels || 2;
        const changed = !this.context || this.format?.sampleRate !== sampleRate
            || this.format?.channels !== channels;

        this.format = { sampleRate, channels };
        if (changed) await this._buildGraph(sampleRate, channels);
        this._buildDecoder(sampleRate, channels);

        for (const packet of this.pending.splice(0)) this.push(packet);
        return true;
    }

    /**
     * @param {number} sampleRate
     * @param {number} channels
     */
    async _buildGraph(sampleRate, channels) {
        await this.close();
        // Matching the context rate to the stream avoids a resample on every frame.
        this.context = new AudioContext({ sampleRate, latencyHint: 'interactive' });
        await this.context.audioWorklet.addModule(WORKLET_URL);
        this.node = new AudioWorkletNode(this.context, 'rd-audio-ring', {
            numberOfInputs: 0,
            outputChannelCount: [channels],
            processorOptions: { channels },
        });
        this.node.port.onmessage = (ev) => {
            if (ev.data?.type === 'stats') this.bufferStats = ev.data;
        };
        this.node.port.postMessage({ type: 'mute', value: this.muted });
        this.node.connect(this.context.destination);
    }

    /**
     * @param {number} sampleRate
     * @param {number} channels
     */
    _buildDecoder(sampleRate, channels) {
        try {
            if (this.decoder && this.decoder.state !== 'closed') this.decoder.close();
        } catch {
            // Closing an errored decoder throws; nothing to recover.
        }
        this.timestamp = 0;
        this.decoder = new this.DecoderClass({
            output: (data) => this._onData(data),
            error: () => { this.errors++; },
        });
        this.decoder.configure({ codec: 'opus', sampleRate, numberOfChannels: channels });
    }

    /** @param {AudioData} data */
    _onData(data) {
        try {
            const channels = data.numberOfChannels;
            const frames = data.numberOfFrames; // authoritative — never assume 480
            const planes = [];
            for (let ch = 0; ch < channels; ch++) {
                const plane = new Float32Array(frames);
                data.copyTo(plane, { planeIndex: ch, format: 'f32-planar' });
                planes.push(plane);
            }
            this.decodedFrames++;
            this.samples += frames;
            this.node?.port.postMessage({ type: 'audio', planes }, planes.map((p) => p.buffer));
        } catch {
            this.errors++;
        } finally {
            data.close();
        }
    }

    /**
     * Feeds one Opus packet.
     * @param {Uint8Array} packet
     */
    push(packet) {
        if (!this.supported || !packet?.length) return false;
        if (!this.decoder) {
            // A frame before its format is unusual but survivable; hold a little and
            // replay once setFormat arrives rather than dropping the start of speech.
            if (this.pending.length < 100) this.pending.push(packet);
            return false;
        }
        this.packets++;
        this.timestamp += FRAME_DURATION_US;
        try {
            // Every Opus packet is independently decodable, so all chunks are 'key'.
            this.decoder.decode(new EncodedAudioChunk({
                type: 'key', timestamp: this.timestamp, data: packet,
            }));
            return true;
        } catch {
            this.errors++;
            return false;
        }
    }

    /** Must be called from a user gesture, or nothing is ever audible. */
    async unlock() {
        if (this.context?.state === 'suspended') await this.context.resume();
        return this.context?.state ?? 'none';
    }

    /** @param {boolean} value */
    setMuted(value) {
        this.muted = value;
        this.node?.port.postMessage({ type: 'mute', value });
    }

    requestStats() {
        this.node?.port.postMessage({ type: 'stats' });
    }

    async close() {
        try {
            if (this.decoder && this.decoder.state !== 'closed') this.decoder.close();
        } catch { /* already closed */ }
        this.decoder = null;
        this.node?.disconnect();
        this.node = null;
        if (this.context) {
            await this.context.close().catch(() => {});
            this.context = null;
        }
    }

    stats() {
        return {
            format: this.format,
            packets: this.packets,
            decoded: this.decodedFrames,
            samples: this.samples,
            errors: this.errors,
            muted: this.muted,
            contextState: this.context?.state ?? 'none',
            buffer: this.bufferStats ?? null,
        };
    }
}
