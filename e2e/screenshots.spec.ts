import { test, Page } from '@playwright/test';

/**
 * Capture the admin-console screenshots used in the README + docs gallery.
 *
 * Run against a server seeded with DemoShowcaseSeeder (see docs/screenshots/README.md).
 * Images are written to docs/screenshots/. This is a capture utility, not an assertion test —
 * run it explicitly, it is excluded from the CI gate by grep in the npm script.
 *
 *   E2E_BASE_URL=http://127.0.0.1:8088 npx playwright test screenshots.spec.ts
 */

const USER = process.env.E2E_ADMIN_USER || 'admin';
const PASS = process.env.E2E_ADMIN_PASS || 'admin123456';
const OUT = 'docs/screenshots';

async function signIn(page: Page) {
    await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
    await page.fill('#username', USER);
    await page.fill('#password', PASS);
    await Promise.all([page.waitForURL(/\/admin$/), page.click('button[type=submit]')]);
}

// Wait for the page 'load' event (bounded) + a fixed beat for fonts/icons/ApexCharts to paint.
// Deliberately NOT 'networkidle' — with CDN assets it can stall ~30s/page and blow the budget.
async function settle(page: Page) {
    await page.waitForLoadState('load').catch(() => {});
    await page.waitForTimeout(1200);
}

async function shot(page: Page, url: string, name: string) {
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 20_000 }).catch(() => {});
    await settle(page);
    await page.screenshot({ path: `${OUT}/${name}.png`, fullPage: true });
}

test('capture admin console screenshots', async ({ page }) => {
    test.setTimeout(240_000);
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

    // The signature Strategy (Security-Settings) editor — open the first strategy's editor.
    await page.goto('/admin/strategies', { waitUntil: 'domcontentloaded' });
    const edit = page.locator('a[href*="/edit"]').first();
    if (await edit.count()) {
        await edit.click();
        await page.waitForLoadState('domcontentloaded');
        await settle(page);
        await page.screenshot({ path: `${OUT}/strategy-editor.png`, fullPage: true });
    }
});
