/**
 * Playwright config for the browser-only coverage.
 *
 * Chromium only, matching the client's supported target. The static server is the same
 * tools/serve.mjs used for development, so the tests exercise the files as they ship
 * rather than a bundled variant.
 *
 * localhost is a secure context, which is what makes WebCodecs, AudioWorklet and the
 * clipboard APIs available without a certificate.
 */

import { defineConfig, devices } from '@playwright/test';

const PORT = 8799;

export default defineConfig({
    testDir: './e2e',
    testMatch: '**/*.spec.mjs',
    // Media pipelines are slower to settle than DOM assertions.
    timeout: 45_000,
    expect: { timeout: 10_000 },
    fullyParallel: false,
    workers: 1,
    reporter: process.env.CI ? 'list' : [['list']],
    use: {
        baseURL: `http://localhost:${PORT}`,
        trace: 'retain-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
                launchOptions: {
                    args: [
                        // The harness constructs VideoFrames and encodes them; a real GPU
                        // is not available in CI, so allow the software path.
                        '--use-gl=swiftshader',
                        '--enable-unsafe-swiftshader',
                        // Autoplay policy would otherwise leave the AudioContext suspended
                        // with no gesture available to a headless test.
                        '--autoplay-policy=no-user-gesture-required',
                    ],
                },
            },
        },
    ],
    webServer: {
        command: `node tools/serve.mjs --port ${PORT} --any`,
        url: `http://localhost:${PORT}/src/ui/viewer.html`,
        reuseExistingServer: !process.env.CI,
        timeout: 30_000,
    },
});
