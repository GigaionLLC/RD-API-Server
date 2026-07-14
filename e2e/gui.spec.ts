import { test, expect, Page, APIRequestContext } from '@playwright/test';

const USER = process.env.E2E_ADMIN_USER || 'admin';
const PASS = process.env.E2E_ADMIN_PASS || 'admin123456';

async function signIn(page: Page) {
    await page.goto('/admin/login', { waitUntil: 'domcontentloaded' });
    await page.fill('#username', USER);
    await page.fill('#password', PASS);
    await Promise.all([
        page.waitForURL(/\/admin$/, { waitUntil: 'commit' }),
        page.click('button[type=submit]'),
    ]);
}

async function uiReady(page: Page) {
    await page.locator('html[data-rd-ready="true"]').waitFor({ state: 'attached', timeout: 30_000 });
}

function peerIdFor(projectName: string) {
    const checksum = [...projectName].reduce((total, character) => total + character.charCodeAt(0), 0);
    return `99${String(checksum).padStart(4, '0').slice(-4)}`;
}

// Create a personal address book and peer through the real client API. CI only seeds the admin.
async function seedAddressBook(request: APIRequestContext, projectName: string) {
    const login = await request.post('/api/login', {
        data: { username: USER, password: PASS, id: `e2e-gui-${projectName}`, uuid: `e2e-gui-${projectName}` },
    });
    expect(login.ok()).toBeTruthy();
    const token = (await login.json()).access_token;
    const headers = { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' };
    const addPeer = await request.post('/api/ab/peer/add/personal', {
        headers,
        data: { id: peerIdFor(projectName), alias: `E2E ${projectName}` },
    });
    expect(addPeer.ok()).toBeTruthy();
}

async function expectNoPageOverflow(page: Page) {
    const metrics = await page.evaluate(() => {
        const viewport = document.documentElement.clientWidth;
        const overflow = document.documentElement.scrollWidth - viewport;
        const originalX = window.scrollX;
        const originalY = window.scrollY;
        window.scrollTo(viewport * 10, originalY);
        const pageScrollX = window.scrollX;
        window.scrollTo(originalX, originalY);
        return { overflow, pageScrollX, viewport };
    });

    expect(
        metrics.pageScrollX,
        'page-level horizontal scroll at ' + metrics.viewport + 'px on ' + page.url() + ' (root scroll width delta ' + metrics.overflow + 'px)',
    ).toBeLessThanOrEqual(1);
}

test('strategy editor exposes accessible category tabs and bulk option controls', async ({ page }, testInfo) => {
    await signIn(page);

    await page.goto('/admin/strategies/create', { waitUntil: 'domcontentloaded' });
    await page.fill('#name', `E2E Policy ${testInfo.project.name} ${Date.now()}`);
    await Promise.all([
        page.waitForURL(/\/admin\/strategies$/, { waitUntil: 'domcontentloaded' }),
        page.getByRole('button', { name: 'Create strategy' }).click(),
    ]);
    await page.locator('a[href*="/edit"]').first().click();

    const generalTab = page.getByRole('tab', { name: 'General' });
    const securityTab = page.getByRole('tab', { name: 'Security' });
    await expect(generalTab).toHaveAttribute('aria-selected', 'true');
    await expect(securityTab).toHaveAttribute('aria-selected', 'false');
    await expect(page.locator('.rd-strategy-pane[data-pane="general"]')).toBeVisible();
    await expect(page.locator('.rd-strategy-pane[data-pane="security"]')).toBeHidden();
    await expect(page.locator('select[name="opt[enable-keyboard]"]')).toHaveCount(1);

    await uiReady(page);
    await generalTab.focus();
    await page.keyboard.press('ArrowRight');
    await expect(securityTab).toBeFocused();
    await expect(securityTab).toHaveAttribute('aria-selected', 'true');
    await expect(page.locator('.rd-strategy-pane[data-pane="security"]')).toBeVisible();
    await expect(page.locator('.rd-strategy-section__title', { hasText: 'Permissions' })).toBeVisible();

    await page.locator('.rd-strategy-toolbar [data-setall="Y"]').click();
    await expect(page.locator('select[name="opt[enable-keyboard]"]')).toHaveValue('Y');
    await expectNoPageOverflow(page);
});

test('devices list reveals the bulk assignment workflow after selection', async ({ page, request }, testInfo) => {
    const deviceId = `bulk-${testInfo.project.name}`;
    await request.post('/api/heartbeat', { data: { id: deviceId, uuid: deviceId, modified_at: 0 } });
    await signIn(page);
    await page.goto('/admin/devices', { waitUntil: 'domcontentloaded' });
    await uiReady(page);

    await expect(page.locator('#bulkForm')).toBeHidden();
    await page.locator('.dev-check').first().check();
    await expect(page.locator('#bulkForm')).toBeVisible();
    await expect(page.locator('#bulkForm')).toHaveClass(/is-visible/);
    await expect(page.locator('#bulkCount')).toContainText('selected');
    await expect(page.locator('#bulkForm select[name="field"]')).toBeVisible();
    await expectNoPageOverflow(page);
});

test('address book manager opens a themed, keyboard-dismissible Add ID dialog', async ({ page, request }, testInfo) => {
    await seedAddressBook(request, testInfo.project.name);
    await signIn(page);
    await page.goto('/admin/address-books', { waitUntil: 'domcontentloaded' });
    await page.locator('a:has-text("View")').first().click();

    const addButton = page.getByRole('button', { name: 'Add ID' }).first();
    await expect(addButton).toBeVisible();
    await expect(page.locator('.rd-address-book')).toBeVisible();

    await uiReady(page);
    await addButton.click();
    await expect(page.locator('#peerModal')).toBeVisible();
    await expect(page.locator('#peerModal input[name="rustdesk_id"]')).toBeVisible();

    const modalBackground = await page.locator('#peerModal .modal-content').evaluate((element) => getComputedStyle(element).backgroundColor);
    expect(modalBackground).not.toBe('rgba(0, 0, 0, 0)');

    await page.keyboard.press('Escape');
    await expect(page.locator('#peerModal')).toBeHidden();
    await expectNoPageOverflow(page);
});

test('saved theme preference is applied and can be toggled', async ({ page }, testInfo) => {
    const theme = testInfo.project.name.includes('light') ? 'light' : 'dark';
    const nextTheme = theme === 'light' ? 'dark' : 'light';
    await page.addInitScript((savedTheme) => localStorage.setItem('rd_theme', savedTheme), theme);
    await signIn(page);
    await uiReady(page);

    await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
    const toggle = page.locator('[data-theme-toggle]');
    await expect(toggle).toBeVisible();
    await toggle.click();
    await expect(page.locator('html')).toHaveAttribute('data-theme', nextTheme);
});

test('flagship pages contain wide content without page-level overflow', async ({ page }, testInfo) => {
    await signIn(page);
    for (const path of ['/admin', '/admin/devices', '/admin/client-config']) {
        await page.goto(path, { waitUntil: 'domcontentloaded' });
        await expect(page.locator('.rd-page-header')).toBeVisible();
        await expectNoPageOverflow(page);
    }

    if (testInfo.project.name === 'mobile-dark') {
        await page.setViewportSize({ width: 320, height: 800 });
        for (const path of ['/admin', '/admin/devices', '/admin/client-config']) {
            await page.goto(path, { waitUntil: 'domcontentloaded' });
            await expect(page.locator('.rd-page-header')).toBeVisible();
            await expectNoPageOverflow(page);
        }
    }
});
