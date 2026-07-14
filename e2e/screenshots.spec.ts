import { test, expect, Page } from '@playwright/test';

/**
 * Capture the admin-console gallery and responsive flagship previews.
 *
 * Run against a server seeded with DemoShowcaseSeeder (see docs/screenshots/README.md).
 * The desktop-dark project keeps the canonical gallery filenames. Other projects capture
 * responsive/theme previews with their project name as a suffix.
 *
 *   CAPTURE_SCREENSHOTS=1 E2E_BASE_URL=http://127.0.0.1:8088 npx playwright test screenshots.spec.ts --project=desktop-dark
 */

const USER = process.env.E2E_ADMIN_USER || 'admin';
const PASS = process.env.E2E_ADMIN_PASS || 'admin123456';
const OUT = 'docs/screenshots';

async function signIn(page: Page) {
    await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
    await page.fill('#username', USER);
    await page.fill('#password', PASS);
    await Promise.all([
        page.waitForURL(/\/admin$/, { waitUntil: 'commit' }),
        page.click('button[type=submit]'),
    ]);
}

async function settle(page: Page) {
    await page.waitForLoadState('load');
    await page.locator('html[data-rd-ready="true"]').waitFor({ state: 'attached', timeout: 30_000 });
    await page.evaluate(async () => {
        if (document.fonts) { await document.fonts.ready; }
    });
    await page.waitForFunction(() => {
        const chart = document.querySelector('#connChart');
        return !chart || chart.querySelector('.apexcharts-canvas') || !(window as Window & { ApexCharts?: unknown }).ApexCharts;
    }).catch(() => {});
    await page.waitForTimeout(300);
}

async function shot(page: Page, url: string, name: string) {
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60_000 });
    await settle(page);
    await page.screenshot({ path: `${OUT}/${name}.png`, fullPage: true });
}

async function strategyEditorShot(page: Page, name: string) {
    await page.goto('/admin/strategies', { waitUntil: 'domcontentloaded' });
    const edit = page.locator('a[href*="/edit"]').first();
    await expect(edit, 'The seeded gallery requires at least one editable strategy.').toHaveCount(1);
    await edit.click();
    await page.waitForLoadState('domcontentloaded');
    await settle(page);
    await page.screenshot({ path: `${OUT}/${name}.png`, fullPage: true });
}

test('capture admin console screenshots', async ({ page }, testInfo) => {
    test.skip(process.env.CAPTURE_SCREENSHOTS !== '1', 'Set CAPTURE_SCREENSHOTS=1 to write the screenshot gallery.');
    test.skip(testInfo.project.name !== 'desktop-dark', 'The canonical gallery is captured at the desktop-dark viewport.');
    test.setTimeout(600_000);
    await signIn(page);

    await shot(page, '/admin', 'dashboard');
    await shot(page, '/admin/devices', 'devices');
    await shot(page, '/admin/strategies', 'strategies');
    await shot(page, '/admin/address-books', 'address-books');
    await shot(page, '/admin/audit/connections', 'connection-logs');
    await shot(page, '/admin/webhooks', 'webhooks');
    await shot(page, '/admin/api-keys', 'api-keys');
    await shot(page, '/admin/alarms', 'alarms');
    await shot(page, '/admin/sessions', 'sessions');
    await shot(page, '/admin/users', 'users');
    await shot(page, '/admin/client-config', 'client-config');
    await shot(page, '/admin/settings', 'settings');
    await strategyEditorShot(page, 'strategy-editor');
});
