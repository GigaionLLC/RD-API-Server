/**
 * Video surface.
 *
 * A WebCodecs `VideoFrame` can be handed straight to `drawImage` on a 2D context; in
 * Chromium that path stays on the GPU with no manual YUV→RGB conversion and no readback.
 * A WebGL shader pipeline would add code without adding speed here, so it is not used
 * until a profile says otherwise.
 *
 * Two behaviours worth stating:
 *
 *  - Frames MUST be closed. `VideoFrame` holds a GPU buffer from a small pool, and
 *    leaking a handful stalls the decoder permanently. Every path through `draw()`
 *    closes its frame, including the early returns.
 *  - The cursor is NOT drawn here. Compositing it into the video canvas ties cursor
 *    updates to the video frame rate, so on a static remote screen the cursor visibly
 *    freezes. It belongs on a separate overlay layer.
 */

export class VideoSurface {
    /**
     * @param {HTMLCanvasElement | OffscreenCanvas} canvas
     * @param {object} [opts]
     * @param {boolean} [opts.letterbox] Preserve aspect ratio (default true).
     */
    constructor(canvas, { letterbox = true } = {}) {
        this.canvas = canvas;
        this.letterbox = letterbox;
        // `desynchronized` lets the compositor skip a frame of latency; `alpha:false`
        // avoids a per-frame blend the video never needs.
        this.ctx = canvas.getContext('2d', { alpha: false, desynchronized: true });
        if (!this.ctx) throw new Error('2D context unavailable');
        this.width = 0;
        this.height = 0;
        this.painted = 0;
        this._clearedFor = '';
    }

    /**
     * Draws and closes the frame.
     * @param {VideoFrame} frame
     */
    draw(frame) {
        try {
            const w = frame.displayWidth || frame.codedWidth;
            const h = frame.displayHeight || frame.codedHeight;

            // A 0x0 frame must never reach coordinate mapping: input scaling divides by
            // these dimensions, so a bad size surfaces as dead input rather than as a
            // video fault, which is a genuinely confusing way to debug it.
            if (!w || !h) return;

            if (w !== this.width || h !== this.height) {
                this.width = w;
                this.height = h;
                this.canvas.width = w;
                this.canvas.height = h;
                this.onResize?.(w, h);
            }

            this.ctx.drawImage(frame, 0, 0, w, h);
            this.painted++;
        } finally {
            frame.close();
        }
    }

    /**
     * Maps a pointer position on the displayed canvas to remote pixels.
     *
     * Returns null outside the letterboxed image so callers can suppress input rather
     * than send a clamped coordinate the user did not aim at.
     *
     * @param {number} clientX Relative to the canvas element's bounding box.
     * @param {number} clientY
     * @param {number} boxWidth Displayed width in CSS pixels.
     * @param {number} boxHeight
     * @returns {{x: number, y: number} | null}
     */
    toRemote(clientX, clientY, boxWidth, boxHeight) {
        if (!this.width || !this.height || !boxWidth || !boxHeight) return null;

        let drawW = boxWidth;
        let drawH = boxHeight;
        let offX = 0;
        let offY = 0;

        if (this.letterbox) {
            const scale = Math.min(boxWidth / this.width, boxHeight / this.height);
            drawW = this.width * scale;
            drawH = this.height * scale;
            offX = (boxWidth - drawW) / 2;
            offY = (boxHeight - drawH) / 2;
        }

        const x = ((clientX - offX) / drawW) * this.width;
        const y = ((clientY - offY) / drawH) * this.height;
        if (x < 0 || y < 0 || x >= this.width || y >= this.height) return null;
        return { x: Math.round(x), y: Math.round(y) };
    }

    stats() {
        return { width: this.width, height: this.height, painted: this.painted };
    }
}
