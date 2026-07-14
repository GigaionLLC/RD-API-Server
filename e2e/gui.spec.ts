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
    const personal = await request.post('/api/ab/personal', { headers });
    expect(personal.ok()).toBeTruthy();
    const bookId = String((await personal.json()).guid);
    const peerId = `${peerIdFor(projectName)}${String(Date.now()).slice(-5)}`;
    const addPeer = await request.post('/api/ab/peer/add/personal', {
        headers,
        data: { id: peerId, alias: `E2E ${projectName}` },
    });
    expect(addPeer.ok()).toBeTruthy();

    return { bookId, peerId };
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
        metrics.overflow,
        'content extends beyond the root viewport at ' + metrics.viewport + 'px on ' + page.url(),
    ).toBeLessThanOrEqual(1);
    expect(
        metrics.pageScrollX,
        'page-level horizontal scroll at ' + metrics.viewport + 'px on ' + page.url(),
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
    const heartbeat = await request.post('/api/heartbeat', { data: { id: deviceId, uuid: deviceId, modified_at: 0 } });
    expect(heartbeat.ok()).toBeTruthy();
    await signIn(page);
    await page.goto(`/admin/devices?q=${encodeURIComponent(deviceId)}`, { waitUntil: 'domcontentloaded' });
    await uiReady(page);

    await expect(page.locator('#bulkForm')).toBeHidden();
    const row = page.locator('tbody tr', { hasText: deviceId });
    await expect(row).toHaveCount(1);
    await row.locator('.dev-check').check();
    await expect(page.locator('#bulkForm')).toBeVisible();
    await expect(page.locator('#bulkForm')).toHaveClass(/is-visible/);
    await expect(page.locator('#bulkCount')).toContainText('selected');
    await expect(page.locator('#bulkForm select[name="field"]')).toBeVisible();

    const apply = page.getByRole('button', { name: 'Apply' });
    await apply.click();
    await expect(page.getByRole('dialog', { name: 'Apply bulk device change' })).toBeVisible();
    await page.getByRole('button', { name: 'Cancel' }).click();
    await expect(apply).toBeFocused();
    await expectNoPageOverflow(page);
});

test('address book manager opens a themed, keyboard-dismissible Add ID dialog', async ({ page, request }, testInfo) => {
    const { bookId } = await seedAddressBook(request, testInfo.project.name);
    await signIn(page);
    await page.goto(`/admin/address-books/${bookId}`, { waitUntil: 'domcontentloaded' });

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

test('address book validation restores the failed dialog and focuses its summary', async ({ page, request }, testInfo) => {
    const { bookId, peerId: duplicateId } = await seedAddressBook(request, `${testInfo.project.name}-validation`);
    await signIn(page);
    await page.goto(`/admin/address-books/${bookId}`, { waitUntil: 'domcontentloaded' });
    await uiReady(page);

    await page.getByRole('button', { name: 'Add ID' }).first().click();
    const dialog = page.locator('#peerModal');
    await dialog.locator('input[name="rustdesk_id"]').fill(duplicateId);
    await dialog.locator('input[name="alias"]').fill('Preserved alias');
    await dialog.getByRole('button', { name: 'Save' }).click();

    await expect(dialog).toBeVisible();
    await expect(dialog.locator('#peer-error-summary')).toBeFocused();
    await expect(dialog.locator('input[name="rustdesk_id"]')).toHaveValue(duplicateId);
    await expect(dialog.locator('input[name="alias"]')).toHaveValue('Preserved alias');
    await expect(dialog).toContainText('already exists in this address book');
});

test('dismissing the collaborator combobox keeps its parent dialog open', async ({ page, request }, testInfo) => {
    const { bookId } = await seedAddressBook(request, `${testInfo.project.name}-combobox`);
    await signIn(page);
    await page.goto(`/admin/address-books/${bookId}`, { waitUntil: 'domcontentloaded' });
    await uiReady(page);

    let markRequestStarted!: () => void;
    let releaseResponse!: () => void;
    let markResponseFinished!: () => void;
    const requestStarted = new Promise<void>((resolve) => { markRequestStarted = resolve; });
    const responseReleased = new Promise<void>((resolve) => { releaseResponse = resolve; });
    const responseFinished = new Promise<void>((resolve) => { markResponseFinished = resolve; });
    await page.route('**/admin/users/search**', async (route) => {
        markRequestStarted();
        await responseReleased;
        await route.fulfill({ json: [{ id: 1, text: 'Delayed result' }] }).catch(() => {});
        markResponseFinished();
    });

    await page.getByRole('button', { name: 'Share' }).click();
    const shareDialog = page.locator('#shareModal');
    const combo = shareDialog.locator('.rd-combo__input');
    await expect(shareDialog).toBeVisible();
    await combo.fill('delayed');
    await requestStarted;
    await page.keyboard.press('Escape');
    releaseResponse();
    await responseFinished;

    await expect(shareDialog).toBeVisible();
    await expect(combo).toHaveAttribute('aria-expanded', 'false');
    await expect(combo).toHaveAttribute('aria-expanded', 'false');
    await expect(shareDialog.locator('.rd-combo__menu')).not.toHaveClass(/is-open/);
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

test('closed mobile navigation is inert and restores focus after dismissal', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'mobile-dark', 'The off-canvas navigation is used below 992px.');

    await signIn(page);
    await uiReady(page);

    const sidebar = page.locator('#rdSidebar');
    const toggle = page.locator('.rd-sidebar__toggle');
    await expect(sidebar).toHaveAttribute('aria-hidden', 'true');
    await expect(sidebar).toHaveAttribute('inert', '');

    await toggle.click();
    await expect(sidebar).toHaveAttribute('aria-hidden', 'false');
    await expect(sidebar).not.toHaveAttribute('inert', '');
    const close = sidebar.getByRole('button', { name: 'Close navigation' });
    await expect(close).toBeFocused();

    await close.click();
    await expect(sidebar).toHaveAttribute('aria-hidden', 'true');
    await expect(toggle).toBeFocused();

    await toggle.click();
    await page.keyboard.press('Escape');
    await expect(sidebar).toHaveAttribute('aria-hidden', 'true');
    await expect(sidebar).toHaveAttribute('inert', '');
    await expect(toggle).toBeFocused();
});

test('navigation group preferences persist without hiding the active page', async ({ page }, testInfo) => {
    test.skip(!testInfo.project.name.startsWith('desktop'), 'Desktop coverage exercises persistent grouped navigation.');

    await signIn(page);
    await uiReady(page);

    const people = page.getByRole('button', { name: /People & access/i });
    await people.click();
    await expect(people).toHaveAttribute('aria-expanded', 'false');
    await page.reload({ waitUntil: 'domcontentloaded' });
    await uiReady(page);
    await expect(page.getByRole('button', { name: /People & access/i })).toHaveAttribute('aria-expanded', 'false');

    await page.goto('/admin/users', { waitUntil: 'domcontentloaded' });
    await uiReady(page);
    const activePeople = page.getByRole('button', { name: /People & access/i });
    await expect(activePeople).toHaveAttribute('aria-expanded', 'true');
    await expect(page.locator('.rd-nav__item[aria-current="page"]')).toContainText('Users');
    await activePeople.click();
    await expect(activePeople).toHaveAttribute('aria-expanded', 'false');
    await page.reload({ waitUntil: 'domcontentloaded' });
    await uiReady(page);
    await expect(page.getByRole('button', { name: /People & access/i })).toHaveAttribute('aria-expanded', 'true');
});

test('implicit form submission still requires the shared confirmation dialog', async ({ page }) => {
    await signIn(page);
    await uiReady(page);

    await page.evaluate(() => {
        const form = document.createElement('form');
        form.method = 'GET';
        form.action = '/admin';
        form.innerHTML = '<input name="confirmation_probe" aria-label="Confirmation probe">' +
            '<button type="submit" data-confirm="Continue with the probe?">Continue</button>';
        document.querySelector('main')?.appendChild(form);
    });

    await page.getByRole('textbox', { name: 'Confirmation probe' }).focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('dialog', { name: 'Confirm action' })).toBeVisible();
    await expect(page).not.toHaveURL(/confirmation_probe/);
    await page.getByRole('button', { name: 'Cancel' }).click();
    await expect(page.getByRole('textbox', { name: 'Confirmation probe' })).toBeFocused();
});

test('live forms retain their label and newer edits made while saving', async ({ page }) => {
    await page.route('**/admin/settings/smtp', async (route) => {
        await new Promise((resolve) => setTimeout(resolve, 350));
        await route.fulfill({ status: 200, contentType: 'application/json', body: '{}' });
    });

    await signIn(page);
    await page.goto('/admin/settings', { waitUntil: 'domcontentloaded' });
    await uiReady(page);

    const form = page.locator('form[data-url$="/admin/settings/smtp"]');
    const save = form.getByRole('button', { name: 'Save SMTP' });
    await form.locator('#host').fill('smtp-before-save.example');
    await expect(save).toHaveAttribute('data-state', 'dirty');

    const response = page.waitForResponse((candidate) => candidate.url().endsWith('/admin/settings/smtp'));
    await save.click();
    await expect(save).toHaveAttribute('data-state', 'saving');
    await form.locator('#username').fill('edited-during-save');
    await response;

    await expect(save).toHaveAttribute('data-state', 'dirty');
    await expect(save).toHaveText('Save SMTP');
});

test('clipboard fallback returns focus to the copy control', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop-dark', 'One browser project covers the shared clipboard fallback.');

    await signIn(page);
    await uiReady(page);
    await page.evaluate(() => {
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: undefined });
        document.execCommand = () => true;
        const source = document.createElement('textarea');
        source.id = 'copy-focus-source';
        source.value = 'copy probe';
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = 'Copy probe';
        button.setAttribute('data-copy', '#copy-focus-source');
        document.querySelector('main')?.append(source, button);
    });

    const copy = page.getByRole('button', { name: 'Copy probe' });
    await copy.click();
    await expect(copy).toBeFocused();
    await expect(page.locator('.rd-toast')).toContainText('Copied to clipboard');
});

test('flagship pages contain wide content without page-level overflow', async ({ page }, testInfo) => {
    await signIn(page);
    for (const path of ['/admin', '/admin/devices', '/admin/client-config', '/admin/ldap']) {
        await page.goto(path, { waitUntil: 'domcontentloaded' });
        await expect(page.locator('.rd-page-header')).toBeVisible();
        await expectNoPageOverflow(page);
    }

    if (testInfo.project.name === 'mobile-dark') {
        await page.setViewportSize({ width: 320, height: 800 });
        for (const path of ['/admin', '/admin/devices', '/admin/client-config', '/admin/ldap']) {
            await page.goto(path, { waitUntil: 'domcontentloaded' });
            await expect(page.locator('.rd-page-header')).toBeVisible();
            await expectNoPageOverflow(page);
        }
    }
});
