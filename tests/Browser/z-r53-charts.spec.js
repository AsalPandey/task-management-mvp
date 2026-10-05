import { test, expect } from '@playwright/test';

for (const mounted of [false, true]) {
    test(`R53 chart runtime stays feature-specific${mounted ? ' under a mounted path' : ''}`, async ({ page }) => {
        test.skip(mounted && !process.env.R44_SUBPATH_URL, 'Mounted server unavailable.');
        const base = mounted ? process.env.R44_SUBPATH_URL : process.env.APP_URL;
        const errors = [];
        const requests = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('request', request => requests.push(request.url()));
        await page.goto(`${base}/login`);
        await page.locator('#email').fill('manager@r41.example.invalid');
        await page.locator('#password').fill('R41-browser-unique-secret-123!');
        await page.getByRole('button', { name: 'Log in', exact: true }).click();
        await expect(page).not.toHaveURL(/\/login$/);
        for (const route of ['/manager', '/tasks', '/projects', '/notifications/all']) {
            requests.length = 0;
            await page.goto(`${base}${route}`);
            await expect(page.locator('#main-content')).toBeVisible();
            expect(await page.evaluate(() => typeof window.Chart)).toBe('undefined');
            expect(requests.filter(url => /\/charts-[^/]+\.js/.test(url))).toEqual([]);
        }
        await page.goto(`${base}/analytics`);
        await expect.poll(() => page.evaluate(() => Object.keys(window.Chart?.instances || {}).length)).toBe(4);
        expect(requests.some(url => /\/charts-[^/]+\.js/.test(url))).toBe(true);
        await page.emulateMedia({ media: 'print' });
        await expect(page.locator('#main-content')).toBeVisible();
        await page.emulateMedia({ media: 'screen' });
        await page.goto(`${base}/team-management`);
        const analytics = page.locator('.member-analytics-link').first();
        await analytics.click();
        await expect.poll(() => page.evaluate(() => Object.keys(window.Chart?.instances || {}).length)).toBe(2);
        expect(errors).toEqual([]);
    });
}
