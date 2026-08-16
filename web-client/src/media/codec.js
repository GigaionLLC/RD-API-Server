/**
 * Codec capability advertisement and WebCodecs configuration.
 *
 * Spec: docs/spec/04-media-input.md §2.
 *
 * Negotiation is asymmetric: we advertise what we can DECODE, and the peer decides what
 * to ENCODE. Two consequences shape this module.
 *
 * A codec is usable only if EVERY viewer attached to that peer can decode it, so
 * advertising a codec we cannot actually handle degrades other people's sessions too.
 * We therefore probe rather than assume.
 *
 * And `isConfigSupported` is not trustworthy on its own — some builds report AV1 as
 * supported and then fail at decode time. Anything we advertise should have survived an
 * actual keyframe decode, which is why `markUnsupported` exists: on repeated failure we
 * re-advertise without that codec and the peer switches encoder.
 */

import { PreferCodec } from '../protocol/enums.js';

/** WebCodecs config strings for each family the protocol can carry. */
const WEBCODECS_CONFIG = {
    // Baseline: always usable, no host-side gate. Never advertise 0 for this.
    vp9: 'vp09.00.10.08',
    vp8: 'vp8',
    av1: 'av01.0.04M.08',
    h264: 'avc1.640028',
    h265: 'hev1.1.6.L93.B0',
};

export const CODEC_FAMILIES = Object.keys(WEBCODECS_CONFIG);

/**
 * @param {string} family
 * @returns {VideoDecoderConfig}
 */
export function decoderConfig(family) {
    const codec = WEBCODECS_CONFIG[family];
    if (!codec) throw new Error(`unknown codec family: ${family}`);
    return {
        codec,
        // H.264/H.265 arrive as Annex-B with SPS/PPS in-band, repeated on key frames.
        // There is no `description` anywhere in the protocol, and supplying one (or
        // omitting Annex-B handling) is why a naive client shows a permanent black
        // screen: the decoder waits for parameter sets it will never receive separately.
        optimizeForLatency: true,
    };
}

/**
 * Probes which families this browser can actually decode.
 * @param {{isConfigSupported: (c: VideoDecoderConfig) => Promise<{supported?: boolean}>}} [decoder]
 * @returns {Promise<Set<string>>}
 */
export async function probeDecodable(decoder = globalThis.VideoDecoder) {
    /** @type {Set<string>} */
    const ok = new Set();
    if (!decoder?.isConfigSupported) return ok;
    for (const family of CODEC_FAMILIES) {
        try {
            const res = await decoder.isConfigSupported(decoderConfig(family));
            if (res?.supported) ok.add(family);
        } catch {
            // An unknown codec string throws rather than returning unsupported.
        }
    }
    return ok;
}

/**
 * Tracks what we can decode and produces the `SupportedDecoding` we advertise.
 */
export class CodecCapabilities {
    /**
     * @param {Iterable<string>} [decodable]
     * @param {string} [prefer] One of CODEC_FAMILIES, or undefined for Auto.
     */
    constructor(decodable = [], prefer = undefined) {
        this.decodable = new Set(decodable);
        this.prefer = prefer;
        /** @type {Map<string, number>} */
        this.failures = new Map();
        // VP9 is the peer's universal fallback and has no host-side gate, so it is added
        // when the probe found nothing — a viewer advertising no codec at all cannot be
        // sent anything. It is NOT forced when the probe ran and deliberately excluded
        // it: claiming a codec this browser cannot decode would produce a connect-and-die
        // loop, and would also force every other viewer of the same peer onto it.
        if (this.decodable.size === 0) this.decodable.add('vp9');
    }

    /**
     * Records a decode failure. Three consecutive failures retire the codec — enough to
     * rule out a transient glitch without persisting with a decoder that cannot work.
     * @param {string} family
     * @returns {boolean} True if the codec was just retired and we should re-advertise.
     */
    markFailure(family) {
        const n = (this.failures.get(family) ?? 0) + 1;
        this.failures.set(family, n);
        if (n < 3 || !this.decodable.has(family)) return false;
        this.decodable.delete(family);
        return true;
    }

    /** @param {string} family */
    markSuccess(family) {
        this.failures.delete(family);
    }

    /** @param {string} family */
    supports(family) {
        return this.decodable.has(family);
    }

    /**
     * @returns {object} A `SupportedDecoding` for LoginRequest.option or Misc.option.
     */
    toSupportedDecoding() {
        const has = (f) => (this.decodable.has(f) ? 1 : 0);
        return {
            ability_vp8: has('vp8'),
            ability_vp9: has('vp9'),
            ability_av1: has('av1'),
            ability_h264: has('h264'),
            ability_h265: has('h265'),
            prefer: this.prefer ? (PreferCodec[this.prefer.toUpperCase()] ?? PreferCodec.Auto) : PreferCodec.Auto,
        };
    }
}

/**
 * Image quality is sent either as a preset enum or as a custom percentage, and the two
 * are mutually exclusive — sending both means the custom value is silently dropped.
 *
 * The wire encoding is `percent << 8`; forgetting the shift yields a value the peer
 * reads as a garbage ratio (or, below 5, as a preset).
 *
 * @param {number} percent 10..100 normally; up to 2000 is accepted by the peer.
 * @returns {{custom_image_quality: number}}
 */
export function customQuality(percent) {
    const p = Math.max(10, Math.min(2000, Math.round(percent)));
    return { custom_image_quality: p << 8 };
}

/**
 * @param {number} fps Values outside 1..120 are silently ignored by the peer, so clamp
 *   rather than send something that does nothing.
 * @returns {{custom_fps: number}}
 */
export function fpsLimit(fps) {
    return { custom_fps: Math.max(1, Math.min(120, Math.round(fps))) };
}
