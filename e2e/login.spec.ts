import { test, expect, Page } from '@playwright/test';

const USER = process.env.E2E_ADMIN_USER || 'admin';
const PASS = process.env.E2E_ADMIN_PASS || 'admin12345678';

async function signIn(page: Page) {
    await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
    await page.fill('#username', USER);
    await page.fill('#password', PASS);
    await Promise.all([
        page.waitForURL(/\/admin$/, { waitUntil: 'domcontentloaded' }),
        page.getByRole('button', { name: 'Sign in', exact: true }).click({ noWaitAfter: true }),
    ]);
}

test('client API: version is served', async ({ request }) => {
    const res = await request.get('/api/version');
    expect(res.ok()).toBeTruthy();
    expect(await res.json()).toHaveProperty('version');
});

test('client API: heartbeat returns JSON', async ({ request }) => {
    const res = await request.post('/api/heartbeat', {
        data: { id: 'e2e-1', uuid: 'e2e-uuid-1', modified_at: 0 },
    });
    expect(res.ok()).toBeTruthy();
});

test('admin can sign in and reach the dashboard', async ({ page }) => {
    await signIn(page);
    await expect(page).toHaveURL(/\/admin$/);
    await expect(page.locator('.rd-sidebar__brand')).toContainText('RD-API-Server');
    await expect(page.getByRole('heading', { name: 'Fleet overview' })).toBeVisible();
});

test('admin can open the strategies page', async ({ page }) => {
    await signIn(page);
    await page.goto('/admin/strategies');
    await expect(page).toHaveURL(/\/admin\/strategies$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Strategies' })).toBeVisible();
});

test('admin can open the devices page', async ({ page }) => {
    await signIn(page);
    await page.goto('/admin/devices');
    await expect(page).toHaveURL(/\/admin\/devices$/);
    await expect(page.getByRole('heading', { level: 1, name: 'Devices' })).toBeVisible();
});
