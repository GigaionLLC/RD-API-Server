import { test, expect, Page, APIRequestContext } from '@playwright/test';

const USER = process.env.E2E_ADMIN_USER || 'admin';
const PASS = process.env.E2E_ADMIN_PASS || 'admin123456';

async function signIn(page: Page) {
    await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
    await page.fill('#username', USER);
    await page.fill('#password', PASS);
    await Promise.all([page.waitForURL(/\/admin$/), page.click('button[type=submit]')]);
}

// jQuery/Bootstrap load from a CDN; wait for them before exercising interactivity.
async function jqueryReady(page: Page) {
    await page.waitForFunction(() => (window as any).jQuery !== undefined, null, { timeout: 15000 });
}

// Create the admin's personal address book + a peer through the real client API, so the test
// is self-sufficient (CI seeds only the admin account).
async function seedAddressBook(request: APIRequestContext) {
    const login = await request.post('/api/login', {
        data: { username: USER, password: PASS, id: 'e2e-gui', uuid: 'e2e-gui' },
    });
    const token = (await login.json()).access_token;
    const headers = { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' };
    await request.post('/api/ab/peer/add/personal', { headers, data: { id: '999111', alias: 'E2E PC' } });
}

test('strategy editor renders the client-style Settings sub-nav and switches panes', async ({ page }) => {
    await signIn(page);

    // Ensure a strategy exists, then open its editor.
    await page.goto('/admin/strategies/create', { waitUntil: 'domcontentloaded' });
    await page.fill('#name', 'E2E Policy');
    await Promise.all([
        page.waitForURL(/\/admin\/strategies$/, { waitUntil: 'domcontentloaded' }),
        page.locator('button', { hasText: 'Create strategy' }).click(),
    ]);
    await page.locator('a[href*="/edit"]').first().click();

    // Structure (server-rendered HTML + local theme CSS — no CDN needed).
    await expect(page.locator('.rd-snav', { hasText: 'General' })).toBeVisible();
    await expect(page.locator('.rd-snav', { hasText: 'Security' })).toBeVisible();
    await expect(page.locator('.rd-snav', { hasText: 'Network' })).toBeVisible();
    await expect(page.locator('.rd-spane[data-pane="general"]')).toBeVisible();
    await expect(page.locator('.rd-spane[data-pane="security"]')).toBeHidden();
    await expect(page.locator('select[name="opt[enable-keyboard]"]')).toHaveCount(1);

    // Interactivity: clicking Security reveals its Permissions section.
    await jqueryReady(page);
    await page.locator('.rd-snav', { hasText: 'Security' }).click();
    await expect(page.locator('.rd-spane[data-pane="security"]')).toBeVisible();
    await expect(page.locator('.rd-spane[data-pane="general"]')).toBeHidden();
    await expect(page.locator('.rd-sec__title', { hasText: 'Permissions' })).toBeVisible();
});

test('address book manager opens a dark Add ID dialog', async ({ page, request }) => {
    await seedAddressBook(request);
    await signIn(page);
    await page.goto('/admin/address-books', { waitUntil: 'domcontentloaded' });
    await page.locator('a:has-text("View")').first().click();

    // Structure: the client-style manager + Add ID button.
    const addBtn = page.locator('button', { hasText: 'Add ID' });
    await expect(addBtn).toBeVisible();
    await expect(page.locator('.rd-ab')).toBeVisible();

    // Interactivity: the Add ID modal opens with the ID field (Bootstrap JS).
    await jqueryReady(page);
    await addBtn.click();
    await expect(page.locator('#peerModal')).toBeVisible();
    await expect(page.locator('#peerModal input[name="rustdesk_id"]')).toBeVisible();

    // The modal must be dark-themed, not Bootstrap's default white.
    const bg = await page.locator('#peerModal .modal-content').evaluate(
        (el) => getComputedStyle(el).backgroundColor,
    );
    expect(bg).not.toBe('rgb(255, 255, 255)');
});
