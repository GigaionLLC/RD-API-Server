/**
 * Remote cursor layer.
 *
 * Spec: docs/spec/06-schema.md §4.6, and the delivery rules in 04-media-input.md §7.
 *
 * Four behaviours, each of which is a visible defect if missed:
 *
 *  - `colors` is zstd-compressed RGBA8888, top-down, no stride. Not raw bytes.
 *  - Shapes are cached by a 64-bit `id` and REPLAYED as a bare `cursor_id` message. There
 *    is no way to re-request a shape, so the cache must never evict for the life of the
 *    session. Drop one and the cursor silently freezes on the last shape drawn.
 *  - This draws on its own layer, not into the video canvas. Compositing the cursor with
 *    the video ties it to the video frame rate, and RustDesk peers only send frames when
 *    the screen changes — so on a static remote desktop the cursor would visibly stick.
 *  - The peer suppresses CursorPosition toward whoever sent input for 300ms, so a viewer
 *    that only follows CursorPosition lags its own pointer. Local echo is the caller's
 *    job; this class just renders whatever position it is given.
 *
 * When the active display reports `cursor_embedded`, the peer has already burned the
 * cursor into the video and this layer must stay hidden or the user sees two.
 */

import { decompress } from '../../vendor/fzstd/index.js';

/**
 * @typedef {object} CursorShape
 * @property {number} width
 * @property {number} height
 * @property {number} hotx
 * @property {number} hoty
 * @property {ImageBitmap | ImageData} image
 */

export class CursorLayer {
    /**
     * @param {HTMLCanvasElement | OffscreenCanvas} canvas An overlay above the video.
     */
    constructor(canvas) {
        this.canvas = canvas;
        this.ctx = canvas.getContext('2d');
        if (!this.ctx) throw new Error('2D context unavailable');

        /** @type {Map<string, CursorShape>} Keyed by the id as a string: it is a 64-bit
         * value and Number would collide across distinct shapes. */
        this.shapes = new Map();
        /** @type {CursorShape | null} */
        this.current = null;
        /** Position in the peer's virtual-desktop space. */
        this.position = { x: 0, y: 0 };
        /** Origin of the display being viewed, subtracted to get canvas-local pixels. */
        this.origin = { x: 0, y: 0 };
        this.visible = true;
        this.embedded = false;
        this.decoded = 0;
        this.missing = 0;
    }

    /**
     * Decodes and caches a shape.
     * @param {{id: bigint, width: number, height: number, hotx?: number, hoty?: number, colors: Uint8Array}} data
     * @returns {Promise<void>}
     */
    async setShape(data) {
        const width = data.width | 0;
        const height = data.height | 0;
        if (!width || !height) return;

        let rgba;
        try {
            rgba = decompress(data.colors);
        } catch {
            // A shape we cannot decode is not fatal: keep the previous cursor rather than
            // tearing down a working session over a cosmetic layer.
            return;
        }

        const expected = width * height * 4;
        if (rgba.length < expected) return;

        const imageData = new ImageData(new Uint8ClampedArray(rgba.buffer, rgba.byteOffset, expected), width, height);
        const image = typeof createImageBitmap === 'function'
            ? await createImageBitmap(imageData)
            : imageData;

        const shape = {
            width,
            height,
            // Windows grows monochrome cursors by a 1px outline and bumps the hotspot, so
            // these are authoritative — never assume 32x32 with a (0,0) hotspot.
            hotx: data.hotx ?? 0,
            hoty: data.hoty ?? 0,
            image,
        };
        this.shapes.set(String(data.id), shape);
        this.current = shape;
        this.decoded++;
        this.render();
    }

    /**
     * Selects a previously cached shape.
     * @param {bigint} id
     * @returns {boolean} False when the id is unknown — which means a shape was evicted.
     */
    useShape(id) {
        const shape = this.shapes.get(String(id));
        if (!shape) {
            this.missing++;
            return false;
        }
        this.current = shape;
        this.render();
        return true;
    }

    /**
     * @param {number} x Virtual-desktop coordinates, as the peer sends them.
     * @param {number} y
     */
    setPosition(x, y) {
        this.position = { x, y };
        this.render();
    }

    /**
     * @param {{x?: number, y?: number, cursor_embedded?: boolean}} display
     */
    setDisplay(display) {
        this.origin = { x: display.x ?? 0, y: display.y ?? 0 };
        this.embedded = display.cursor_embedded === true;
        this.render();
    }

    /** @param {number} width @param {number} height Canvas size, matching the video. */
    resize(width, height) {
        if (this.canvas.width === width && this.canvas.height === height) return;
        this.canvas.width = width;
        this.canvas.height = height;
        this.render();
    }

    render() {
        const { ctx, canvas } = this;
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        if (!this.visible || this.embedded || !this.current) return;

        const x = this.position.x - this.origin.x - this.current.hotx;
        const y = this.position.y - this.origin.y - this.current.hoty;

        // Cheap reject: a cursor on another monitor maps outside this canvas.
        if (x + this.current.width < 0 || y + this.current.height < 0
            || x > canvas.width || y > canvas.height) return;

        if (typeof ImageBitmap !== 'undefined' && this.current.image instanceof ImageBitmap) {
            ctx.drawImage(this.current.image, x, y);
        } else {
            ctx.putImageData(/** @type {ImageData} */(this.current.image), x, y);
        }
    }

    stats() {
        return {
            cached: this.shapes.size,
            decoded: this.decoded,
            missing: this.missing,
            embedded: this.embedded,
            position: this.position,
        };
    }
}
