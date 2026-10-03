import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';

const password = 'R41-browser-unique-secret-123!';
const managerEmail = 'manager@r41.example.invalid';
const email = 'member@r42.example.invalid';
const snapshot = () => JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', ['tests/Support/r41_browser_snapshot.php'], { encoding: 'utf8' }));
const metric = (page, label) => page.locator('.metric-card').filter({ has: page.locator('.metric-label', { hasText: new RegExp('^' + label + '$') }) }).locator('.metric-value');
async function login(page, identity = managerEmail) {
    await page.goto('/login'); await page.locator('#email').fill(identity); await page.locator('#password').fill(password);
    await page.getByRole('button', { name: 'Log in', exact: true }).click(); await expect(page).not.toHaveURL(/\/login$/);
}
async function fillAccount(page, name, identity) {
    await page.locator('#addMemberBtn').click(); await page.locator('#memberName').fill(name);
    await page.locator('#memberEmail').fill(identity); await page.locator('#memberPassword').fill(password);
    await page.locator('#memberRole').selectOption({ label: 'Team Member' });
}
async function saveAccount(page, identity) {
    await page.locator('#createAccountBtn').click();
    await expect(page.locator('.member-card').filter({ hasText: identity })).toBeVisible();
}

test('overlong email validation keeps Team usable and a malformed edit recovers', async ({ page }) => {
    await login(page); await page.goto('/team-management');
    await fillAccount(page, 'R42 Member', 'a'.repeat(256) + '@example.test');
    const invalid = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/team-management');
    await page.locator('#createAccountBtn').click(); expect((await invalid).status()).toBe(422);
    await expect(page.locator('#addMemberForm')).toContainText('255');
    await expect(page.locator('#addMemberModal')).toHaveClass(/active/);
    await page.locator('#memberEmail').fill(email); await saveAccount(page, email);
    const card = page.locator('.member-card').filter({ hasText: email }); await card.locator('.edit-btn').click();
    await page.locator('#editMemberName').fill('R42 Member Edited');
    await page.route('**/team-management/*', async route => {
        if (route.request().method() === 'PUT') {
            const payload = route.request().postDataJSON(); payload.role_id = [payload.role_id];
            await route.continue({ postData: JSON.stringify(payload) });
        } else await route.continue();
    });
    const edit = page.waitForResponse(r => r.request().method() === 'PUT');
    await page.locator('#editMemberForm button[type=submit]').click(); expect((await edit).status()).toBe(422);
    await expect(page.locator('#editMemberModal')).toHaveClass(/active/);
    await expect(page.locator('#editMemberName')).toHaveValue('R42 Member Edited');
    await expect(page.locator('#editMemberForm')).toContainText('positive integer');
    await page.unroute('**/team-management/*'); await page.locator('#editMemberForm button[type=submit]').click();
    await expect(card).toContainText('R42 Member Edited');
});

test('account lifecycle collision explains retained identity after actual UI deletion', async ({ page }) => {
    await login(page); await page.goto('/team-management');
    const former = 'former@r42.example.invalid';
    await fillAccount(page, 'R42 Former Member', former); await saveAccount(page, former);
    const card = page.locator('.member-card').filter({ hasText: former }); await card.locator('.delete-btn').click();
    await page.getByRole('button', { name: 'Yes, remove!' }).click();
    await page.locator('.swal2-confirm').click(); await page.reload(); await expect(card).toHaveCount(0);
    await fillAccount(page, 'R42 Rehire Attempt', former.toUpperCase());
    const response = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/team-management');
    await page.locator('#createAccountBtn').click(); expect((await response).status()).toBe(422);
    await expect(page.locator('#addMemberForm')).toContainText('prior account');
    await expect(page.locator('#addMemberForm')).toContainText('preserve its history');
    await page.screenshot({ path: 'output/playwright/r42-email-policy.png', fullPage: true });
});

test('membership retry preserves one visible member, history and notification generation', async ({ page }) => {
    await login(page); await page.goto('/projects');
    const project = page.locator('.project-card').filter({ hasText: 'R41 Core Project' });
    await project.locator('.member-add-form select').selectOption({ label: 'R42 Member Edited (Team Member)' });
    const response = page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('/add-member'));
    await project.locator('.member-add-form button').click(); const added = await response; expect(added.status()).toBe(200);
    await expect(project.locator('.member-row').filter({ hasText: 'R42 Member Edited' })).toHaveCount(1);
    const before = snapshot(); const member = before.users.find(u => u.email === email);
    const token = await page.locator('meta[name=csrf-token]').getAttribute('content');
    for (let i = 0; i < 3; i++) {
        const retry = await page.request.post(added.url(), { data: { user_id: member.id }, headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' } });
        expect(retry.status()).toBe(200);
    }
    await page.reload(); await expect(project.locator('.member-row').filter({ hasText: 'R42 Member Edited' })).toHaveCount(1);
    const after = snapshot(); expect(after.notifications).toBe(before.notifications);
    expect(after.project_history).toEqual(before.project_history); expect(after.memberships).toEqual(before.memberships);
});

test('UI-created work gives correct visible analytics, real date filtering and matching exports', async ({ page, browser }) => {
    await login(page);
    for (const title of ['R42 Past A', 'R42 Past B', 'R42 Current']) {
        await page.goto('/tasks'); await page.locator('#newTaskBtn').click(); await page.locator('#taskTitle').fill(title); await page.locator('#taskDescription').fill('R42 reporting fixture created in the real UI');
        const due = new Date(); due.setDate(due.getDate() + 5); await page.locator('#taskDueDate').fill(due.toISOString().slice(0, 10));
        await page.locator('#taskProject').selectOption({ label: 'R41 Core Project' });
        await page.locator('#taskAssignee').selectOption({ label: 'R42 Member Edited' });
        await page.locator('#taskReviewer').selectOption({ label: 'R41 Manager' });
        await page.locator('#taskPriority').selectOption('Medium'); await page.locator('#taskForm button[type=submit]').click();
        await expect(page.locator('#tasksGrid .task-card').filter({ hasText: title })).toBeVisible();
    }
    const context = await browser.newContext(); const memberPage = await context.newPage(); await login(memberPage, email);
    for (const title of ['R42 Past A', 'R42 Past B', 'R42 Current']) {
        await memberPage.goto('/tasks'); const card = memberPage.locator('#tasksGrid .task-card').filter({ hasText: title });
        const start = card.locator('[data-transition=start]'); await expect(start).toBeVisible();
        const response = memberPage.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith('/start'));
        await start.click(); await memberPage.locator('.swal2-confirm').click(); expect((await response).status()).toBe(200);
        await expect(memberPage.locator('.swal2-popup')).toBeVisible(); await memberPage.locator('.swal2-confirm').click();
    }
    execFileSync(process.env.PHP_BINARY || 'php', ['tests/Support/r42_browser_dates.php'], { encoding: 'utf8' });
    const id = snapshot().users.find(u => u.email === email).id;
    await page.goto(`/team-management/${id}/analytics`);
    await expect(metric(page, 'In Progress')).toHaveText('1'); await expect(metric(page, 'Total Tasks')).toHaveText('1');
    await expect.poll(() => page.evaluate(() => window.Chart?.getChart(document.getElementById('completionTrendChart'))?.data.labels.length)).toBe(30);
    await page.locator('#dateFrom').fill('2026-01-01'); await page.locator('#dateTo').fill('2026-01-01'); await page.getByRole('button', { name: 'Apply', exact: true }).click();
    await expect(metric(page, 'In Progress')).toHaveText('2'); await expect(metric(page, 'Total Tasks')).toHaveText('2');
    await expect(page.locator('#dateFrom')).toHaveValue('2026-01-01'); await page.reload(); await expect(metric(page, 'In Progress')).toHaveText('2');
    await page.screenshot({ path: 'output/playwright/r42-member-range.png', fullPage: true });
    await memberPage.goto(`/team-management/${id}/analytics?dateFrom=2026-01-01&dateTo=2026-01-01`); await expect(metric(memberPage, 'In Progress')).toHaveText('2');
    const currentDate = snapshot().tasks.find(t => t.title === 'R42 Current').created_at.slice(0, 10);
    const range = `?dateFrom=${currentDate}&dateTo=${currentDate}`;
    await page.goto('/analytics/print' + range); await expect(page.locator('tr').filter({ has: page.getByRole('cell', { name: 'In Progress', exact: true }) })).toContainText('1');
    const csv = await page.request.get('/analytics/export/csv' + range); expect(csv.status()).toBe(200); expect(await csv.text()).toContain('"In Progress Tasks",1');
    await page.goto('/analytics' + range); await expect(metric(page, 'Active Workflow')).toHaveText('3');
    await page.screenshot({ path: 'output/playwright/r42-analytics-oracle.png', fullPage: true });
    await context.close();
});
