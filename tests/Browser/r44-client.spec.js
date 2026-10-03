import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
const evidenceDir = process.env.R44_EVIDENCE_DIR || 'output/r44';
fs.mkdirSync(evidenceDir, { recursive: true });

const password = 'R41-browser-unique-secret-123!';
const manager = 'manager@r41.example.invalid';
const snapshot = () => JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', ['tests/Support/r41_browser_snapshot.php'], { encoding: 'utf8' }));
async function login(page, email = manager) {
    await page.goto('/login'); await page.locator('#email').fill(email); await page.locator('#password').fill(password);
    await page.getByRole('button', { name: 'Log in', exact: true }).click(); await expect(page).not.toHaveURL(/\/login$/);
}
const card = (page, title = 'R44 Fresh Task') => page.locator('#tasksGrid .task-card').filter({ has: page.locator('.task-title', { hasText: title }) });
async function edit(page, title, value) {
    await card(page, title).getByRole('button', { name: `Edit ${title}`, exact: true }).click(); await expect(page.locator('#taskModal')).toHaveClass(/active/);
    await page.locator('#taskTitle').fill(value); await page.locator('#taskForm button[type=submit]').click();
    await expect(card(page, value)).toBeVisible();
}
function contrast(foreground, background) {
    const luminance = color => {
        const values = color.match(/[\d.]+/g).slice(0, 3).map(Number).map(v => v / 255).map(v => v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4);
        return .2126 * values[0] + .7152 * values[1] + .0722 * values[2];
    };
    const a = luminance(foreground), b = luminance(background);
    return (Math.max(a, b) + .05) / (Math.min(a, b) + .05);
}

test('R44 UI fixture creates Unicode account and draft task', async ({ page }) => {
    await login(page); await page.goto('/team-management'); await page.locator('#addMemberBtn').click();
    await page.locator('#memberName').fill('आशा पाण्डे'); await page.locator('#memberEmail').fill('unicode@r44.example.invalid');
    await page.locator('#memberPassword').fill(password);
    await page.locator('#memberRole').selectOption(String(snapshot().users.find(u => u.email === 'a@r41.example.invalid').role_id));
    await page.locator('#createAccountBtn').click(); await expect(page.locator('.member-card').filter({ hasText: 'unicode@r44.example.invalid' })).toBeVisible();
    await page.goto('/tasks'); await page.locator('#newTaskBtn').click(); await page.locator('#taskTitle').fill('R44 Fresh Task');
    await page.locator('#taskDescription').fill('R44 actual rendered UI freshness qualification');
    await page.locator('#taskProject').selectOption({ label: 'R41 Core Project' }); await page.locator('#taskAssignee').selectOption({ label: 'R41 Member A' });
    await page.locator('#taskReviewer').selectOption({ label: 'R41 PM' }); await page.locator('#taskPriority').selectOption('High');
    const due = new Date(); due.setDate(due.getDate() + 5); await page.locator('#taskDueDate').fill(due.toISOString().slice(0, 10));
    await page.locator('#taskForm button[type=submit]').click(); await expect(card(page)).toBeVisible();
});

test('R4-020 Unicode initials preserve complete Devanagari graphemes', async ({ page }) => {
    await login(page); await page.goto('/team-management?search=unicode%40r44');
    await expect(page.locator('.member-avatar')).toHaveText('आपा');
});
test('R4-021 role presentation uses human labels', async ({ page }) => {
    await login(page); await page.goto('/team-management');
    await expect(page.locator('#memberRole option')).toHaveText(['Select Role', 'Manager', 'Project Manager', 'Team Member']);
    await page.goto('/team-management?search=pm%40r41');
    await expect(page.locator('.member-card').filter({ hasText: 'pm@r41.example.invalid' })).toContainText('Project Manager');
});
test('R4-022 contextual action and member-select accessible names', async ({ page }) => {
    await login(page); await page.goto('/team-management?search=unicode%40r44');
    await expect(page.getByRole('button', { name: 'Edit आशा पाण्डे', exact: true })).toBeVisible();
    await page.goto('/projects'); await expect(page.getByRole('combobox', { name: 'Add member to R41 Core Project', exact: true })).toBeVisible();
});
test('R4-013 task action and Team analytics operate with the keyboard', async ({ page }) => {
    await login(page); await page.goto('/tasks');
    const action = card(page).getByRole('button', { name: 'Edit R44 Fresh Task', exact: true });
    await expect(action).toBeVisible();
    await action.focus(); await page.keyboard.press('Space'); await expect(page.locator('#taskModal')).toHaveClass(/active/);
    await expect(page.locator('#taskModal')).toHaveAccessibleName('Edit Task');
    await expect(page.locator('#taskTitle')).toBeFocused();
    await page.locator('#taskForm button[type=submit]').focus(); await page.keyboard.press('Tab');
    await expect(page.locator('#taskModal .modal-close')).toBeFocused();
    await page.keyboard.press('Shift+Tab'); await expect(page.locator('#taskForm button[type=submit]')).toBeFocused();
    expect(await page.locator('header').evaluate(element=>element.inert)).toBe(true);
    await page.keyboard.press('Escape'); await expect(page.locator('#taskModal')).not.toHaveClass(/active/); await expect(action).toBeFocused();
    await page.goto('/team-management?search=unicode%40r44'); const link = page.getByRole('link', { name: 'View analytics for आशा पाण्डे' });
    await link.focus(); await page.keyboard.press('Enter'); await expect(page).toHaveURL(/\/analytics$/);
});
test('R4-024 Team secondary text meets normal-text contrast', async ({ page }) => {
    await login(page); await page.goto('/team-management');
    const colors = await page.locator('.member-info p').evaluateAll(nodes => nodes.map(node => ({ fg: getComputedStyle(node).color, bg: getComputedStyle(node.closest('.member-card')).backgroundColor })));
    const results = colors.map(c => ({ ...c, ratio: contrast(c.fg, c.bg) }));
    fs.writeFileSync(`${evidenceDir}/team-contrast.json`, JSON.stringify(results, null, 2));
    for (const result of results) expect(result.ratio).toBeGreaterThanOrEqual(4.5);
});
test('R4-012 same-profile invalidation signals another tab without destroying a draft', async ({ page, context }) => {
    await login(page); await page.goto('/tasks'); const other = await context.newPage(); await other.goto('/tasks');
    await card(other).getByRole('button', { name: 'Edit R44 Fresh Task', exact: true }).click(); await other.locator('#taskTitle').fill('R44 unsaved local draft');
    await edit(page, 'R44 Fresh Task', 'R44 Fresh Task Updated');
    await expect(other.locator('[data-client-notice]')).toBeVisible({ timeout: 10_000 });
    await expect(other.locator('[data-client-notice]')).toContainText('Newer data');
    await expect(other.locator('#taskTitle')).toHaveValue('R44 unsaved local draft');
    await other.locator('#taskForm button[type=submit]').click();
    await expect(other.locator('.swal2-title')).toHaveText('Edit Conflict');
    expect(snapshot().tasks.find(t => t.title === 'R44 Fresh Task Updated')).toBeTruthy();
    await other.close();
});
