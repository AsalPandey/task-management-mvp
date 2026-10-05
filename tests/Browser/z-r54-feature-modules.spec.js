import { test, expect, chromium, webkit } from '@playwright/test';
import { execFileSync } from 'node:child_process';

const password = 'R41-browser-unique-secret-123!';
const snapshot = () => JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', ['tests/Support/r41_browser_snapshot.php'], { encoding: 'utf8' }));
const future = () => new Date(Date.now() + 10 * 86400000).toISOString().slice(0, 10);
async function login(page, email = 'manager@r41.example.invalid') {
    await page.goto('/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(password);
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).not.toHaveURL(/\/login$/);
}

for (const [engine, launcher] of [['Chromium', chromium], ['WebKit', webkit]]) {
    test(`R54 ${engine} completed timeline handles a conflict and recovers controls`, async () => {
        const browser = await launcher.launch();
        try {
            const page = await browser.newPage({ baseURL: process.env.APP_URL });
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await login(page);
            await page.goto('/history');
            const button = page.locator('.timeline-btn').first();
            const url = await button.getAttribute('data-url');
            // WebKit worker-controlled requests bypass Playwright route interception.
            // Inject only this feature request while retaining AppClient's real fetch.
            await page.evaluate(url => {
                window.r54RealFetch = window.fetch;
                window.fetch = (input, options) => String(input) === url
                    ? Promise.resolve(new Response(JSON.stringify({ success: false, message: 'Timeline is temporarily unavailable.' }), { status: 409, headers: { 'Content-Type': 'application/json' } }))
                    : window.r54RealFetch(input, options);
            }, url);
            await button.click();
            await expect(page.locator('.swal2-popup')).toContainText('Timeline is temporarily unavailable.');
            await expect(button).toBeEnabled();
            await expect(button).toHaveText('Timeline');
            await page.locator('.swal2-confirm').click();
            await page.evaluate(() => { window.fetch = window.r54RealFetch; delete window.r54RealFetch; });
            await button.click();
            await expect(page.locator('.phase3-timeline')).toBeVisible();
            expect(await page.locator('.phase3-timeline-item').count()).toBeGreaterThan(0);
            await page.locator('.swal2-confirm').click();
            await expect(button).toBeEnabled();
            expect(errors).toEqual([]);
        } finally {
            await browser.close();
        }
    });
}

async function transition(page, title, action, reason) {
    await page.goto(`/tasks?search=${encodeURIComponent(title)}`);
    const button = page.locator('#tasksGrid .task-card').filter({ hasText: title }).locator(`[data-transition="${action}"]`);
    const url = await button.getAttribute('data-url');
    const version = Number(await button.getAttribute('data-task-version'));
    const response = page.waitForResponse(r => r.request().method() === 'POST' && r.url() === url);
    await button.click();
    if (reason) await page.locator('.swal2-textarea').fill(reason);
    await page.locator('.swal2-confirm').click();
    const result = await response;
    expect(result.request().postDataJSON().expected_version).toBe(version);
    expect(result.status(), await result.text()).toBe(200);
    await expect(page.locator('.swal2-title')).toHaveText('Success');
    const navigation = page.waitForEvent('framenavigated', frame => frame === page.mainFrame());
    await page.locator('.swal2-confirm').click();
    await navigation;
}

test('R54 UI reassigns reviewer, holds initial work, reopens approval and cancels work', async ({ page, browser }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await login(page);
    await page.goto('/tasks');
    await page.locator('#newTaskBtn').click();
    const title = `R54 Feature Actions ${Date.now()}`;
    await page.locator('#taskTitle').fill(title);
    await page.locator('#taskDescription').fill('Feature boundary qualification through the real UI.');
    await page.locator('#taskProject').selectOption({ label: 'R41 Core Project' });
    await page.locator('#taskAssignee').selectOption({ label: 'R41 Member A' });
    await page.locator('#taskReviewer').selectOption({ label: 'R41 PM' });
    await page.locator('#taskPriority').selectOption('High');
    await page.locator('#taskDueDate').fill(future());
    const created = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/tasks');
    await page.locator('#taskForm button[type=submit]').click();
    expect((await created).status()).toBe(200);
    await expect(page.locator('#tasksGrid')).toContainText(title);
    const managerId = snapshot().users.find(user => user.email === 'manager@r41.example.invalid').id;
    const task = page.locator('#tasksGrid .task-card').filter({ hasText: title });
    const reviewerButton = task.locator('[data-action="reassign-reviewer"]');
    const reassigned = page.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith('/reviewer/reassign'));
    await reviewerButton.click();
    await page.locator('.swal2-select').selectOption(String(managerId));
    await page.locator('.swal2-confirm').click();
    await page.locator('.swal2-textarea').fill('R54 explicit reviewer responsibility');
    await page.locator('.swal2-confirm').click();
    expect((await reassigned).status()).toBe(200);
    await expect(page.locator('.swal2-title')).toHaveText('Success');
    const refreshed = page.waitForEvent('framenavigated', frame => frame === page.mainFrame());
    await page.locator('.swal2-confirm').click();
    await refreshed;
    expect(snapshot().tasks.find(task => task.title === title).reviewer_id).toBe(managerId);
    const memberContext = await browser.newContext();
    const member = await memberContext.newPage();
    await login(member, 'a@r41.example.invalid');
    await transition(member, title, 'start');
    await transition(member, title, 'hold', 'R54 initial execution hold');
    await transition(member, title, 'resume');
    await transition(page, title, 'cancel', 'R54 controlled cancellation');
    expect(snapshot().tasks.find(task => task.title === title).status).toBe('cancelled');
    await memberContext.close();

    await page.goto('/history');
    const row = page.locator('tr').filter({ hasText: 'R41 Basic Task' });
    const reopen = row.locator('.reopen-revision-btn');
    const version = Number(await reopen.getAttribute('data-task-version'));
    const url = await reopen.getAttribute('data-url');
    await reopen.click();
    await page.locator('.swal2-textarea').fill('R54 private management reason');
    await page.locator('.swal2-confirm').click();
    await page.locator('.swal2-textarea').fill('R54 visible rework instruction');
    await page.locator('.swal2-confirm').click();
    await page.locator('.swal2-select').selectOption(String(managerId));
    await page.locator('.swal2-confirm').click();
    await page.locator('.swal2-input').fill(future());
    const reopened = page.waitForResponse(r => r.request().method() === 'POST' && r.url() === url);
    await page.locator('.swal2-confirm').click();
    const result = await reopened;
    expect(result.request().postDataJSON().expected_version).toBe(version);
    expect(result.status(), await result.text()).toBe(200);
    await expect(row).toHaveCount(0);
    await expect(page.locator('.swal2-title')).toHaveText('Reopened!');
    await page.locator('.swal2-confirm').click();
    expect(snapshot().tasks.find(task => task.title === 'R41 Basic Task').status).toBe('revision_requested');
    await transition(page, 'R41 Basic Task', 'cancel', 'R54 reopened workflow cancellation');
    expect(errors).toEqual([]);
});

test('R54 legacy profile remains CSP-safe and recovers modal focus after invalid deletion', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await login(page);
    await page.goto('/profile');
    await expect(page.locator('main')).toBeVisible();
    const profileForm = page.locator('form[action$="/profile"]').filter({ has: page.locator('#name') });
    const saved = page.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith('/profile'));
    await profileForm.getByRole('button', { name: 'Save', exact: true }).click();
    expect((await saved).status()).toBe(302);
    await expect(page.locator('[data-profile-saved]')).toHaveText('Saved.');
    const deleteButton = page.locator('#openAccountDeletion');
    await deleteButton.focus();
    await page.keyboard.press('Enter');
    const dialog = page.getByRole('dialog', { name: 'Are you sure you want to delete your account?' });
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('#password')).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(deleteButton).toBeFocused();
    await deleteButton.click();
    await dialog.locator('#password').fill('Deliberately wrong disposable password');
    await dialog.getByRole('button', { name: 'Delete Account', exact: true }).click();
    await expect(dialog).toBeVisible();
    await expect(dialog).toContainText('The password is incorrect.');
    await expect(dialog.locator('#password')).toBeFocused();
    await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
    expect(errors).toEqual([]);
});
