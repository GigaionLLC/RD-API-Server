/**
 * Clipboard synchronisation.
 *
 * Spec: docs/spec/06-schema.md §4.8.
 *
 * The protocol is push-only in both directions — there is no "request clipboard" message.
 * Peers poll their own clipboard and push on change; we push when the user pastes.
 *
 * Three constraints the browser imposes, none of which the protocol anticipates:
 *
 *  - Writing the clipboard needs a transient user activation. An incoming clipboard
 *    therefore cannot be applied the moment it arrives; it is buffered and flushed on the
 *    next user gesture. `flush()` must be called from a real event handler.
 *  - There is no clipboard-change event, so outbound sync is paste-driven rather than
 *    polled. A copy on this side is not seen until the user pastes into the viewer.
 *  - `Rtf` and `ImageSvg` have no ClipboardItem MIME type, and `Special` is opaque
 *    platform data. All three are dropped on receipt rather than mangled.
 *
 * Loop suppression: peers mark clipboard content they themselves applied with a synthetic
 * entry that a browser cannot write. Instead we remember a digest of what we last applied
 * and refuse to send it back, which stops the two sides trading the same text forever.
 */

import { decompress } from '../vendor/fzstd/index.js';
import { ClipboardFormat } from './protocol/enums.js';
import { supportsMultiClipboard } from './protocol/version.js';

const decoder = new TextDecoder();
const encoder = new TextEncoder();

/** MIME types a browser ClipboardItem can carry, mapped to protocol formats. */
const MIME_BY_FORMAT = {
    [ClipboardFormat.Text]: 'text/plain',
    [ClipboardFormat.Html]: 'text/html',
    [ClipboardFormat.ImagePng]: 'image/png',
};

/** @param {Uint8Array} bytes */
function digestOf(bytes) {
    // FNV-1a: enough to recognise "we just applied this", and cheap enough to run on
    // every clipboard event without touching WebCrypto's async API.
    let h = 0x811c9dc5;
    for (let i = 0; i < bytes.length; i++) {
        h ^= bytes[i];
        h = Math.imul(h, 0x01000193) >>> 0;
    }
    return `${bytes.length}:${h.toString(16)}`;
}

export class ClipboardSync {
    /**
     * @param {object} opts
     * @param {(msg: object) => void} opts.send Sends a `Message`.
     * @param {() => object | null} opts.peerInfo For the version gate.
     * @param {boolean} [opts.enabled]
     */
    constructor({ send, peerInfo, enabled = true }) {
        this.send = send;
        this.peerInfo = peerInfo;
        this.enabled = enabled;
        /** @type {{items: Record<string, Blob>, text: string} | null} */
        this.pending = null;
        /** Digest of the last content applied locally, to break the echo loop. */
        this.lastApplied = '';
        this.received = 0;
        this.sent = 0;
        this.dropped = 0;
    }

    /**
     * Handles an inbound `Clipboard` or `MultiClipboards`.
     *
     * @param {Array<object>} entries
     * @returns {boolean} True when something was buffered for the next gesture.
     */
    receive(entries) {
        if (!this.enabled || !entries?.length) return false;

        /** @type {Record<string, Blob>} */
        const items = {};
        let text = '';

        for (const entry of entries) {
            const format = entry.format ?? ClipboardFormat.Text;
            const mime = MIME_BY_FORMAT[format];
            if (!mime) {
                // Rtf, ImageSvg and Special have no browser representation.
                this.dropped++;
                continue;
            }

            let content = entry.content ?? new Uint8Array(0);
            if (entry.compress) {
                try {
                    content = decompress(content);
                } catch {
                    this.dropped++;
                    continue;
                }
            }
            if (!content.length) continue;

            if (format === ClipboardFormat.Text) text = decoder.decode(content);
            // Copy: `content` is a view into the receive buffer, which is reused.
            items[mime] = new Blob([new Uint8Array(content)], { type: mime });
        }

        if (Object.keys(items).length === 0) return false;

        this.pending = { items, text };
        this.lastApplied = digestOf(encoder.encode(text));
        this.received++;
        return true;
    }

    /**
     * Writes buffered content to the system clipboard.
     *
     * MUST be called from a user gesture — a click, keypress or paste handler. Browsers
     * reject a clipboard write without transient activation, and the rejection is a
     * promise failure rather than anything visible.
     *
     * @returns {Promise<boolean>}
     */
    async flush() {
        if (!this.pending) return false;
        const { items, text } = this.pending;

        try {
            if (typeof ClipboardItem !== 'undefined' && navigator.clipboard?.write) {
                await navigator.clipboard.write([new ClipboardItem(items)]);
            } else if (text && navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(text);
            } else {
                return false;
            }
            this.pending = null;
            return true;
        } catch {
            // Denied permission or no activation. Keep the buffer: the next gesture may
            // succeed, and discarding it would silently lose the peer's clipboard.
            return false;
        }
    }

    /**
     * Sends local clipboard content to the peer, from a `paste` event.
     *
     * @param {ClipboardEvent} event
     * @returns {boolean}
     */
    sendFromPaste(event) {
        if (!this.enabled) return false;
        const data = event.clipboardData;
        if (!data) return false;

        const text = data.getData('text/plain') ?? '';
        const html = data.getData('text/html') ?? '';
        if (!text && !html) return false;

        // Refuse to echo what the peer just sent us, or the two sides trade it forever.
        if (text && digestOf(encoder.encode(text)) === this.lastApplied) return false;

        /** @type {object[]} */
        const clipboards = [];
        if (text) {
            clipboards.push({ format: ClipboardFormat.Text, content: encoder.encode(text), compress: false });
        }
        if (html && supportsMultiClipboard(this.peerInfo())) {
            clipboards.push({ format: ClipboardFormat.Html, content: encoder.encode(html), compress: false });
        }
        if (!clipboards.length) return false;

        // Older peers understand only a single Clipboard, and only its text entry.
        if (supportsMultiClipboard(this.peerInfo())) {
            this.send({ multi_clipboards: { clipboards } });
        } else {
            const textEntry = clipboards.find((c) => c.format === ClipboardFormat.Text);
            if (!textEntry) return false;
            this.send({ clipboard: textEntry });
        }

        this.sent++;
        return true;
    }

    /**
     * Sends arbitrary text, for a toolbar action rather than a paste.
     * @param {string} text
     */
    sendText(text) {
        if (!this.enabled || !text) return false;
        const entry = { format: ClipboardFormat.Text, content: encoder.encode(text), compress: false };
        if (supportsMultiClipboard(this.peerInfo())) this.send({ multi_clipboards: { clipboards: [entry] } });
        else this.send({ clipboard: entry });
        this.sent++;
        return true;
    }

    /** @param {boolean} value */
    setEnabled(value) {
        this.enabled = value;
        if (!value) this.pending = null;
    }

    stats() {
        return {
            enabled: this.enabled,
            received: this.received,
            sent: this.sent,
            dropped: this.dropped,
            pending: this.pending !== null,
        };
    }
}
