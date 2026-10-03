import { test, expect } from '@playwright/test';

const large = process.env.R43_LARGE_BROWSER === '1';
const password = large ? 'R43-disposable-browser-secret-123!' : 'R41-browser-unique-secret-123!';
const managerEmail = large ? 'manager@r43.example.invalid' : 'manager@r41.example.invalid';
const metric = (page, label) => page.locator('.metric-card').filter({ has: page.locator('.metric-label', { hasText: new RegExp('^' + label + '$') }) }).locator('.metric-value');
async function login(page, email = managerEmail) {
    await page.goto('/login'); await page.locator('#email').fill(email); await page.locator('#password').fill(password);
    await page.getByRole('button', { name: 'Log in', exact: true }).click(); await expect(page).not.toHaveURL(/\/login$/);
}

test('R43 server search reaches an off-page account created through the real Team UI', async ({ page }) => {
    test.skip(large, 'Clean-company UI gate uses the R41 workflow prerequisites.');
    await login(page); await page.goto('/team-management');
    for (let i = 0; i < 14; i++) {
        const email = `search${i}@r43-ui.example.invalid`;
        await page.locator('#addMemberBtn').click(); await page.locator('#memberName').fill(i === 0 ? 'पुरानो Search Target' : `R43 Search Member ${i}`);
        await page.locator('#memberEmail').fill(email); await page.locator('#memberPassword').fill(password);
        await page.locator('#memberRole').selectOption({ label: 'Team_member' }); await page.locator('#createAccountBtn').click();
        await expect(page.locator('.member-card').filter({ hasText: email })).toBeVisible();
    }
    await page.reload(); await expect(page.locator('.member-card').filter({ hasText: 'search0@r43-ui.example.invalid' })).toHaveCount(0);
    await page.locator('#teamSearch').fill('  SEARCH0@R43-UI  ');
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    const target = page.locator('.member-card').filter({ hasText: 'search0@r43-ui.example.invalid' });
    await expect(target).toBeVisible(); await expect(target).toContainText('पुरानो Search Target');
    await expect(target.locator('.edit-btn')).toBeVisible();
    await expect(page).not.toHaveURL(/page=/);
    await page.locator('#teamSearch').fill('पुरानो'); await page.getByRole('button', { name: 'Search', exact: true }).click(); await expect(target).toBeVisible();
    await page.screenshot({ path: 'output/playwright/r43-team-search.png', fullPage: true });
    await page.getByRole('link', { name: 'Clear search', exact: true }).click();
    await expect(page.locator('#teamSearch')).toHaveValue(''); await expect(page.locator('.member-card')).toHaveCount(12);
    await page.locator('#teamSearch').fill('R43 Search Member'); await page.getByRole('button', { name: 'Search', exact: true }).click();
    const next = page.locator('a[href*="page=2"]:visible').first();
    await expect(next).toHaveAttribute('href', /search=R43/); await next.click(); await expect(page).toHaveURL(/page=2/);
    await page.locator('#teamSearch').fill('no-such-user-r43'); await page.getByRole('button', { name: 'Search', exact: true }).click();
    await expect(page.locator('.member-card')).toHaveCount(0); await expect(page).not.toHaveURL(/page=/);
});

test('R43 large manager pages retain totals with bounded previews and paginated listings', async ({ page }) => {
    test.skip(!large, 'Requires the separately prepared 10,000-task performance schema.');
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await login(page); await page.goto('/manager');
    await expect(metric(page, 'Active Tasks')).toHaveText('7500');
    expect(await page.locator('.overdue-task-item').count()).toBeLessThanOrEqual(10);
    await expect(page.locator('#overdueCount')).toHaveText('2142');
    await page.screenshot({ path: 'output/playwright/r43-large-dashboard.png', fullPage: true });
    await page.goto('/projects'); await expect(page.locator('.project-card')).toHaveCount(12);
    await page.goto('/tasks'); await expect(page.locator('#tasksGrid .task-card')).toHaveCount(24);
    const firstTitles = await page.locator('#tasksGrid .task-title').allTextContents();
    await page.locator('a[href*="page=2"]:visible').first().click(); await expect(page.locator('#tasksGrid .task-card')).toHaveCount(24);
    const secondTitles = await page.locator('#tasksGrid .task-title').allTextContents(); expect(secondTitles.some(title => firstTitles.includes(title))).toBeFalsy();
    await page.goto('/analytics?dateFrom=2026-09-01&dateTo=2026-10-03');
    await expect(metric(page, 'Active Workflow')).toHaveText('7500');
    await expect(metric(page, 'Completion Rate')).toHaveText('14.3%');
    await expect(page.locator('body')).toContainText('1250 of 8750 non-cancelled cohort tasks completed');
    await expect(page.locator('canvas[aria-label="30-Day Creation Trend"]')).toBeVisible();
    await page.screenshot({ path: 'output/playwright/r43-large-analytics.png', fullPage: true });
    await page.goto('/analytics/print?dateFrom=2026-09-01&dateTo=2026-10-03'); await expect(page.locator('body')).toContainText('7500');
    await page.goto('/notifications/all'); expect(await page.locator('.notification-page-item').count()).toBeLessThanOrEqual(20);
    expect(errors).toEqual([]);
});

test('R43 large mobile member dashboard and PM search preserve scope and remain usable', async ({ page }) => {
    test.skip(!large, 'Requires the separately prepared large performance schema.');
    await page.setViewportSize({ width: 390, height: 844 });
    await login(page, 'member001@r43.example.invalid'); await page.goto('/team-dashboard');
    await expect(metric(page, "Today's Tasks")).toHaveText('192');
    expect(await page.locator('.overdue-task').count()).toBeLessThanOrEqual(10);
    expect(await page.locator('.deadline-task').count()).toBeLessThanOrEqual(10);
    await expect(page.getByRole('link', { name: 'View all tasks' }).first()).toBeVisible();
    await page.screenshot({ path: 'output/playwright/r43-large-member-mobile.png', fullPage: true });
    await page.locator('form[action$="/logout"] button').click();
    await expect(page.locator('#email')).toBeVisible();
    await login(page, 'pm@r43.example.invalid'); await page.goto('/manager');
    await expect(metric(page, 'Active Tasks')).toHaveText('3750');
    await page.goto('/team-management'); await page.locator('#teamSearch').fill('MEMBER001'); await page.getByRole('button', { name: 'Search', exact: true }).click();
    await expect(page.locator('.member-card')).toHaveCount(1); await expect(page.locator('.member-card')).toContainText('member001@r43.example.invalid');
    await expect(page.locator('.member-card .edit-btn')).toHaveCount(0);
});
