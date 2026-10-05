import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';

async function login(page, email = 'manager@r41.example.invalid') {
    await page.goto('/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill('R41-browser-unique-secret-123!');
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).not.toHaveURL(/\/login$/);
}
const fixture = (id, count) => JSON.parse(execFileSync(process.env.PHP_BINARY || 'php',
    ['tests/Support/r53_timeline_fixture.php', String(id), String(count)], { encoding: 'utf8' }));

test('R53 real timeline traverses all history, retries errors and preserves private redaction', async ({ page, browser }) => {
    test.skip(!process.env.DB_DATABASE?.startsWith('task_management_r41_browser_'), 'Requires disposable clean-company browser fixture.');
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await login(page);
    await page.goto('/tasks');
    await page.locator('#newTaskBtn').click();
    await page.locator('#taskTitle').fill('R53 Traversable History');
    await page.locator('#taskDescription').fill('Timeline qualification with synthetic supported history events');
    await page.locator('#taskProject').selectOption({ label: 'R41 Core Project' });
    await page.locator('#taskAssignee').selectOption({ label: 'R41 Member A' });
    await page.locator('#taskReviewer').selectOption({ label: 'R41 Manager' });
    await page.locator('#taskStartDate').fill(new Date().toISOString().slice(0, 10));
    await page.locator('#taskDueDate').fill(new Date(Date.now() + 5 * 86400000).toISOString().slice(0, 10));
    const created = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/tasks');
    const reloaded = page.waitForEvent('framenavigated', frame => frame === page.mainFrame());
    await page.locator('#taskForm button[type=submit]').click();
    const response = await created;
    expect(response.status()).toBe(200);
    const id = (await response.json()).task.id;
    await reloaded;
    await page.waitForLoadState('domcontentloaded');
    expect(fixture(id, 205).events).toBe(206);
    await page.goto('/tasks?search=R53%20Traversable%20History');
    await page.locator(`#tasksGrid .timeline-btn[data-url*="/tasks/${id}/timeline"]`).click();
    const items = page.locator('.phase3-timeline-item');
    await expect(items).toHaveCount(100);
    await expect(page.locator('.phase3-timeline')).toContainText('Management reason recorded');
    fixture(id, 1); // New activity must not shift the anchored older pages.
    await page.route(`**/tasks/${id}/timeline?before=*`, route => route.fulfill({ status: 503,
        contentType: 'application/json', body: JSON.stringify({ success: false, message: 'Qualification retry' }) }), { times: 1 });
    const older = page.getByRole('button', { name: 'Load older activity' });
    await older.click();
    await expect(page.getByRole('status').filter({ hasText: 'Qualification retry' })).toBeVisible();
    await expect(items).toHaveCount(100);
    await older.focus();
    await older.press('Enter');
    await expect(items).toHaveCount(200);
    await older.click();
    await expect(items).toHaveCount(206);
    await expect(older).toBeHidden();
    await expect(page.locator('.phase3-timeline')).toContainText('Beginning of activity');
    expect(await items.evaluateAll(nodes => nodes.map(node => Number(node.dataset.sequence))))
        .toEqual(Array.from({ length: 206 }, (_, i) => i + 1));
    await page.screenshot({ path: `${process.env.BROWSER_OUTPUT_DIR}/r53-complete-history.png` });
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    const context = await browser.newContext();
    const member = await context.newPage();
    await login(member, 'a@r41.example.invalid');
    await member.goto('/tasks?search=R53%20Traversable%20History');
    await member.locator(`#tasksGrid .timeline-btn[data-url*="/tasks/${id}/timeline"]`).click();
    await expect(member.locator('.phase3-timeline-item')).toHaveCount(100);
    await expect(member.locator('.phase3-timeline')).not.toContainText('Management reason recorded');
    await member.getByRole('button', { name: 'Load older activity' }).click();
    await member.getByRole('button', { name: 'Load older activity' }).click();
    await expect(member.locator('.phase3-timeline-item')).toHaveCount(207);
    await expect(member.locator('.phase3-timeline')).not.toContainText('r53-private-management-reference');
    await context.close();
    expect(errors).toEqual([]);
});
