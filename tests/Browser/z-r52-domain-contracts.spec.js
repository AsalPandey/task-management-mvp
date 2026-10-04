import { test, expect } from '@playwright/test';

const password = 'R41-browser-unique-secret-123!';
async function login(page, email = 'manager@r41.example.invalid') {
    await page.goto('/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(password);
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).not.toHaveURL(/\/login$/);
    await page.waitForLoadState('networkidle');
}
async function account(page, name, email, role) {
    await page.goto('/team-management');
    await page.locator('#addMemberBtn').click();
    await page.locator('#memberName').fill(name);
    await page.locator('#memberEmail').fill(email);
    await page.locator('#memberPassword').fill(password);
    await page.locator('#memberRole').selectOption({ label: role });
    await page.locator('#createAccountBtn').click();
    await expect(page.locator('.member-card').filter({ hasText: email })).toBeVisible();
}
const projectCard = (page, name) => page.locator('.project-card').filter({ has: page.locator('.project-title h3', { hasText: name }) });
const future = days => new Date(Date.now() + days * 86400000).toISOString().slice(0, 10);

test('R52 actual UI rejects unusable PM candidates and displays safe withdrawal after revocation', async ({ page, browser }) => {
    await login(page);
    await account(page, 'R52 Other PM', 'other-pm@r52.example.invalid', 'Project Manager');
    await account(page, 'R52 Withdraw Member', 'withdraw@r52.example.invalid', 'Team Member');
    await page.goto('/projects');
    await page.locator('#newProjectBtn').click();
    await page.locator('#projectName').fill('R52 Scope Contract');
    await page.locator('#projectManager').selectOption({ label: 'R41 PM' });
    await page.locator('#projectSubmitBtn').click();
    await expect(projectCard(page, 'R52 Scope Contract')).toBeVisible();
    for (const [name, role] of [['R52 Other PM', 'Project Manager'], ['R52 Withdraw Member', 'Team Member'], ['R41 Member A', 'Team Member']]) {
        await projectCard(page, 'R52 Scope Contract').locator('.member-add-form select').selectOption({ label: `${name} (${role})` });
        await projectCard(page, 'R52 Scope Contract').locator('.member-add-form button').click();
        await expect(projectCard(page, 'R52 Scope Contract').locator('.member-row').filter({ hasText: name })).toBeVisible();
    }
    const projectId = await projectCard(page, 'R52 Scope Contract').getAttribute('data-project-id');
    await page.goto('/tasks');
    await page.locator('#newTaskBtn').click();
    await page.locator('#taskProject').selectOption({ label: 'R52 Scope Contract' });
    expect(await page.locator('#taskAssignee option').allTextContents()).not.toContain('R52 Other PM');
    expect((await page.locator('#taskAssignee option').allTextContents()).some(value => value.includes('R52 Other PM'))).toBe(false);
    await page.locator('#taskTitle').fill('R52 Effective Schedule');
    await page.locator('#taskDescription').fill('UI schedule qualification');
    await page.locator('#taskAssignee').selectOption({ label: 'R41 Member A' });
    await page.locator('#taskReviewer').selectOption({ label: 'R41 Manager' });
    await page.locator('#taskStartDate').fill(future(1));
    await page.locator('#taskDueDate').fill(future(5));
    await page.locator('#taskForm button[type=submit]').click();
    const task = page.locator('#tasksGrid .task-card').filter({ hasText: 'R52 Effective Schedule' });
    await expect(task).toBeVisible();
    await task.locator('.task-open-btn').click();
    await page.locator('#taskStartDate').fill(future(6));
    const update = page.waitForResponse(response => response.request().method() === 'PUT' && response.url().includes('/tasks/'));
    await page.locator('#taskForm button[type=submit]').click();
    expect((await update).status()).toBe(422);
    await expect(page.locator('#taskModal')).toHaveClass(/active/);
    await expect(page.locator('.swal2-popup')).toContainText('start date');
    await page.locator('.swal2-confirm').click();
    await page.goto('/projects');
    await projectCard(page, 'R52 Scope Contract').locator('.member-row').filter({ hasText: 'R52 Withdraw Member' }).locator('.member-remove-btn').click();
    await page.locator('.swal2-confirm').click();
    await expect(projectCard(page, 'R52 Scope Contract').locator('.member-row').filter({ hasText: 'R52 Withdraw Member' })).toHaveCount(0);
    const context = await browser.newContext();
    const former = await context.newPage();
    await login(former, 'withdraw@r52.example.invalid');
    await former.goto('/notifications/all');
    await expect(former.locator('main .notification-page-message').filter({ hasText: 'Your membership and access to a project were removed.' })).toBeVisible();
    await expect(former.locator('main')).not.toContainText('R52 Scope Contract');
    expect((await former.request.get(`/projects/${projectId}/members`)).status()).toBe(403);
    await context.close();
});

test('R52 project duplicate and malformed date UI retain the form with controlled validation', async ({ page }) => {
    await login(page);
    await page.goto('/projects');
    await page.locator('#newProjectBtn').click();
    await page.locator('#projectName').fill('r52 scope contract');
    let response = page.waitForResponse(result => result.request().method() === 'POST' && new URL(result.url()).pathname === '/projects');
    await page.locator('#projectSubmitBtn').click();
    expect((await response).status()).toBe(422);
    await expect(page.locator('.swal2-popup')).toContainText('name');
    await page.locator('.swal2-confirm').click();
    await expect(page.locator('#projectModal')).toHaveClass(/active/);
    await page.locator('#projectName').fill('R52 Invalid Date');
    await page.locator('#projectStartDate').fill('10000-01-01');
    response = page.waitForResponse(result => result.request().method() === 'POST' && new URL(result.url()).pathname === '/projects');
    await page.locator('#projectSubmitBtn').click();
    expect((await response).status()).toBe(422);
    await expect(page.locator('.swal2-popup')).toContainText('start date');
    await page.locator('.swal2-confirm').click();
    await expect(page.locator('#projectModal')).toHaveClass(/active/);
    await expect(page.locator('#projectName')).toHaveValue('R52 Invalid Date');
});
