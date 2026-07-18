import { defineConfig } from '@playwright/test';

const baseURL = process.env.E2E_BASE_URL || 'http://127.0.0.1:8088';

// Full-stack E2E against a running server (php artisan serve or the Docker app).
export default defineConfig({
    testDir: './e2e',
    timeout: 60_000,
    fullyParallel: true,
    workers: 2,
    reporter: process.env.CI ? 'list' : 'line',
    use: {
        baseURL,
        headless: true,
        actionTimeout: 15_000,
    },
    projects: [
        {
            name: 'desktop-dark',
            use: { viewport: { width: 1440, height: 900 }, colorScheme: 'dark' },
        },
        {
            name: 'desktop-light',
            use: {
                viewport: { width: 1440, height: 900 },
                colorScheme: 'light',
                storageState: {
                    cookies: [],
                    origins: [{
                        origin: new URL(baseURL).origin,
                        localStorage: [{ name: 'rd_theme', value: 'light' }],
                    }],
                },
            },
        },
        {
            name: 'tablet-dark',
            use: { viewport: { width: 1024, height: 768 }, colorScheme: 'dark', hasTouch: true },
        },
        {
            name: 'mobile-dark',
            use: { viewport: { width: 390, height: 844 }, colorScheme: 'dark', hasTouch: true, isMobile: true },
        },
    ],
});
