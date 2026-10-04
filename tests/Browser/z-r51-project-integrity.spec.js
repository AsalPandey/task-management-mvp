import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
const password = 'R41-browser-unique-secret-123!';
const snapshot = () => JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', ['tests/Support/r41_browser_snapshot.php'], { encoding: 'utf8' }));
async function login(page, email) {
    await page.goto('/login'); await page.locator('#email').fill(email); await page.locator('#password').fill(password);
    await page.getByRole('button', { name: 'Log in', exact: true }).click(); await expect(page).not.toHaveURL(/\/login$/);
}
const projectCard = (page, name) => page.locator('.project-card').filter({ has: page.locator('h3', { hasText: name }) });
async function project(page, name, pm) {
    await page.goto('/projects'); await page.locator('#newProjectBtn').click();
    await page.locator('#projectName').fill(name); await page.locator('#projectManager').selectOption({ label: pm });
    await page.locator('#projectSubmitBtn').click(); await expect(projectCard(page, name)).toBeVisible();
}

test('R5.1 clean company PM replacement and project deletion preserve access and tasks', async ({ page, browser }) => {
    const suffix = Date.now();
    const replacementName = `R51 Replacement PM ${suffix}`;
    const replacementEmail = `replacement-${suffix}@r51.example.invalid`;
    const taskTitle = `R51 Guarded Project Task ${suffix}`;
    const emptyName = `R51 Empty Delete Project ${suffix}`;
    await login(page, 'manager@r41.example.invalid'); await page.goto('/team-management');
    await page.locator('#addMemberBtn').click(); await page.locator('#memberName').fill(replacementName);
    await page.locator('#memberEmail').fill(replacementEmail); await page.locator('#memberPassword').fill(password);
    await page.locator('#memberRole').selectOption({ label: 'Project Manager' }); await page.locator('#createAccountBtn').click();
    await expect(page.locator('.member-card').filter({ hasText: replacementEmail })).toBeVisible();
    const name = `R51 Ownership Project ${suffix}`; await project(page, name, 'R41 PM');
    const oldContext = await browser.newContext(); const old = await oldContext.newPage(); await login(old, 'pm@r41.example.invalid');
    await old.goto('/projects'); await expect(projectCard(old, name)).toBeVisible();
    await projectCard(page, name).locator('.project-edit-btn').click();
    await page.locator('#projectManager').selectOption({ label: replacementName });
    const replaced = page.waitForResponse(r => r.request().method() === 'PUT' && r.url().includes('/projects/'));
    await page.locator('#projectSubmitBtn').click(); expect((await replaced).status()).toBe(200);
    await expect(projectCard(page, name)).toContainText(replacementName);
    await old.reload(); await expect(projectCard(old, name)).toHaveCount(0);
    const newContext = await browser.newContext(); const replacement = await newContext.newPage(); await login(replacement, replacementEmail);
    await replacement.goto('/projects'); await expect(projectCard(replacement, name)).toBeVisible();
    const current = projectCard(page, name);
    await current.locator('.member-add-form select').selectOption({ label: 'R41 Member A (Team Member)' });
    await current.locator('.member-add-form button').click(); await expect(current.locator('.member-row').filter({ hasText: 'R41 Member A' })).toBeVisible();
    await page.goto('/tasks'); await page.locator('#newTaskBtn').click(); await page.locator('#taskTitle').fill(taskTitle);
    await page.locator('#taskProject').selectOption({ label: name }); await page.locator('#taskAssignee').selectOption({ label: 'R41 Member A' });
    await page.locator('#taskDescription').fill('Project writer integrity acceptance.'); await page.locator('#taskDueDate').fill(new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10));
    await page.locator('#taskReviewer').selectOption({ label: replacementName }); await page.locator('#taskPriority').selectOption('High');
    const created = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/tasks');
    await page.locator('#taskForm button[type=submit]').click(); expect((await created).status()).toBe(200);
    await expect(page.locator('#tasksGrid .task-card').filter({ hasText: taskTitle })).toBeVisible();
    await page.goto('/projects'); const deletion = page.waitForResponse(r => r.request().method() === 'DELETE' && r.url().includes('/projects/'));
    await projectCard(page, name).locator('.project-delete-btn').click(); await page.locator('.swal2-confirm').click();
    expect((await deletion).status()).toBe(409); await expect(projectCard(page, name)).toBeVisible();
    await page.locator('.swal2-confirm').click();
    await project(page, emptyName, replacementName);
    const emptyDeletion = page.waitForResponse(r => r.request().method() === 'DELETE' && r.url().includes('/projects/'));
    await projectCard(page, emptyName).locator('.project-delete-btn').click(); await page.locator('.swal2-confirm').click();
    expect((await emptyDeletion).status()).toBe(200); await expect(projectCard(page, emptyName)).toHaveCount(0);
    const db = snapshot(); const parent = db.projects.find(p => p.name === name); const task = db.tasks.find(t => t.title === taskTitle);
    expect(task.project_id).toBe(parent.id); expect(parent.project_manager_id).toBe(db.users.find(u => u.email === replacementEmail).id);
    expect(db.project_history.filter(h => h.project_id === parent.id && h.action === 'deleted')).toHaveLength(0);
    await oldContext.close(); await newContext.close();
});
