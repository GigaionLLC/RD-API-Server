/**
 * Main-thread responsiveness probe.
 *
 * Measures the gap between consecutive animation frames. Any work that blocks the main
 * thread — decryption, protobuf decoding, video decode, compositing — shows up here as a
 * stretched gap, so this is a direct measure of what a user would perceive as jank.
 *
 * The point of the worker migration is that these numbers should stay flat as bitrate
 * rises. Running the same session with decode on the main thread and then in a worker,
 * and comparing p95, is the honest way to show that rather than assert it.
 */

export class JankProbe {
    /** @param {number} [budgetMs] Gaps above this count as dropped. 60Hz ≈ 16.7ms. */
    constructor(budgetMs = 20) {
        this.budgetMs = budgetMs;
        /** @type {number[]} */
        this.gaps = [];
        this.running = false;
        this.last = 0;
        this._tick = this._tick.bind(this);
    }

    start() {
        if (this.running) return;
        this.running = true;
        this.gaps.length = 0;
        this.last = performance.now();
        requestAnimationFrame(this._tick);
    }

    stop() {
        this.running = false;
    }

    /** @param {number} now */
    _tick(now) {
        if (!this.running) return;
        const gap = now - this.last;
        this.last = now;
        // Ignore the first frame and any gap long enough to be a tab switch rather than
        // work: those would swamp the percentiles without saying anything about load.
        if (this.gaps.length || gap < 500) this.gaps.push(gap);
        requestAnimationFrame(this._tick);
    }

    /** @param {number} p 0..1 */
    percentile(p) {
        if (!this.gaps.length) return 0;
        const sorted = [...this.gaps].sort((a, b) => a - b);
        return sorted[Math.min(sorted.length - 1, Math.floor(sorted.length * p))];
    }

    stats() {
        const n = this.gaps.length;
        if (!n) return { samples: 0 };
        const over = this.gaps.filter((g) => g > this.budgetMs).length;
        return {
            samples: n,
            p50: +this.percentile(0.5).toFixed(2),
            p95: +this.percentile(0.95).toFixed(2),
            p99: +this.percentile(0.99).toFixed(2),
            max: +Math.max(...this.gaps).toFixed(2),
            overBudget: over,
            overBudgetPct: +((100 * over) / n).toFixed(1),
        };
    }
}
