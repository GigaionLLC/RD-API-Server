import { test, expect, Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const USER = process.env.E2E_ADMIN_USER || 'admin';
const PASS = process.env.E2E_ADMIN_PASS || 'admin12345678';

async function signIn(page: Page) {
    await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
    await page.fill('#username', USER);
    await page.fill('#password', PASS);
    await Promise.all([
        page.waitForURL(/\/admin$/, { waitUntil: 'commit' }),
        page.click('button[type=submit]'),
    ]);
}

async function expectNoAxeViolations(page: Page) {
    const results = await new AxeBuilder({ page }).analyze();
    const violations = results.violations.map(({ id, impact, help, nodes }) => ({
        id,
        impact,
        help,
        targets: nodes.map((node) => node.target),
    }));

    expect(violations).toEqual([]);
}

test('login is free of automatically detectable accessibility violations', async ({ page }) => {
    await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expectNoAxeViolations(page);
});

test('representative authenticated pages pass automated accessibility checks', async ({ page }) => {
    await signIn(page);
    for (const path of ['/admin', '/admin/devices', '/admin/settings', '/admin/2fa']) {
        await page.goto(path, { waitUntil: 'domcontentloaded' });
        await expect(page.locator('.rd-page-header')).toBeVisible();
        await expectNoAxeViolations(page);
    }
});
