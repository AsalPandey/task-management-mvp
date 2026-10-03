import { test, expect } from '@playwright/test';
import fs from 'node:fs';

// Opt-in comparison against an unchanged, UI-created disposable company. Capture
// the old toolchain with --update-snapshots, then compare the candidate build.
test.use({ serviceWorkers: 'block', reducedMotion: 'reduce' });
test('R44A core screen toolchain visual and computed-style comparison', async ({ browser }) => {
    test.skip(!process.env.R44A_VISUAL, 'Requires the explicit pre/post migration fixture.');
    test.setTimeout(600_000);
    const phase = process.env.R44A_VISUAL;
    const contexts = [];
    const open = async (email) => {
        const context = await browser.newContext({ serviceWorkers: 'block', reducedMotion: 'reduce' });
        contexts.push(context);
        const page = await context.newPage();
        if (email) {
            await page.goto(`${process.env.APP_URL}/login`);
            await page.locator('#email').fill(email);
            await page.locator('#password').fill('R41-browser-unique-secret-123!');
            await page.getByRole('button', { name: 'Log in', exact: true }).click();
            await expect(page).not.toHaveURL(/\/login$/);
        }
        return page;
    };
    const guest = await open(), manager = await open('manager@r41.example.invalid'), member = await open('a@r41.example.invalid');
    await manager.goto(`${process.env.APP_URL}/team-management`);
    const analytics = await manager.getByRole('link', { name: /R41 Member A.*analytics|analytics.*R41 Member A/i }).getAttribute('href');
    const screens = [
        ['setup', guest, `${process.env.R44A_SETUP_URL}/setup`], ['login', guest, `${process.env.APP_URL}/login`],
        ['manager', manager, '/manager'], ['member', member, '/team-dashboard'],
        ['team', manager, '/team-management'], ['projects', manager, '/projects'], ['tasks', manager, '/tasks'],
        ['task-modal', manager, '/tasks'], ['analytics', manager, '/analytics'], ['notifications', manager, '/notifications/all'],
        ['settings', manager, '/settings'], ['member-analytics', manager, analytics],
    ];
    const results = [];
    for (const [width, height] of [[320,568],[390,844],[768,1024],[844,390],[1280,720],[1440,900]]) {
        for (const [name, page, route] of screens) {
            await page.setViewportSize({ width, height });
            await page.goto(route.startsWith('http') ? route : `${process.env.APP_URL}${route}`);
            await page.waitForLoadState('networkidle');
            await page.evaluate(async () => {
                await document.fonts.ready;
                await Promise.all(document.getAnimations().filter(a => Number.isFinite(a.effect?.getTiming().iterations)).map(a => a.finished.catch(() => {})));
            });
            if (name === 'task-modal') {
                await page.locator('#newTaskBtn').click();
                await page.locator('#taskTitle').fill('R44A Unicode आशा — stable draft');
            }
            const metrics = await page.evaluate(() => ({
                width: innerWidth, scroll: document.documentElement.scrollWidth,
                elements: [...document.querySelectorAll('main *, header *, nav *, [role=dialog] *')].filter(e => e.getClientRects().length).map(e => {
                    const s = getComputedStyle(e), r = e.getBoundingClientRect();
                    return { tag: e.tagName, id: e.id, classes: e.className?.baseVal ?? e.className,
                        box: [r.x,r.y,r.width,r.height].map(n => Math.round(n * 100) / 100),
                        css: Object.fromEntries(['display','fontSize','fontWeight','lineHeight','color','backgroundColor','borderColor','borderWidth','borderRadius','boxShadow','padding','margin','gap','outlineStyle'].map(k => [k,s[k]])) };
                }),
            }));
            results.push({ name, width, height, ...metrics });
            expect.soft(metrics.scroll, `${name} ${width} horizontal overflow`).toBeLessThanOrEqual(width + 1);
            await page.screenshot({ path: `output/playwright/r44a/${phase}/${name}-${width}.png`, fullPage: true, animations: 'disabled' });
            expect.soft(await page.screenshot({ fullPage: true, animations: 'disabled' })).toMatchSnapshot(`${name}-${width}.png`, { maxDiffPixelRatio: 0.001 });
        }
    }
    fs.mkdirSync('output/r44a', { recursive: true });
    fs.writeFileSync(`output/r44a/visual-${phase}.json`, JSON.stringify(results, null, 2));
    await Promise.all(contexts.map(context => context.close()));
});
