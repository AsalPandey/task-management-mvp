import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
const evidenceDir = process.env.R44_EVIDENCE_DIR || 'output/r44';
fs.mkdirSync(evidenceDir, { recursive: true });
import { chromium, firefox, webkit } from 'playwright';

const password = 'R41-browser-unique-secret-123!';
const futureDate = () => { const date=new Date(); date.setDate(date.getDate()+8); return date.toISOString().slice(0,10); };
const manager = 'manager@r41.example.invalid', member = 'a@r41.example.invalid', pm = 'pm@r41.example.invalid';
async function login(page, email = manager) {
    await page.goto('/login'); await page.locator('#email').fill(email); await page.locator('#password').fill(password);
    await page.getByRole('button', { name: 'Log in', exact: true }).click(); await expect(page).not.toHaveURL(/\/login$/);
    await settled(page);
}
async function settled(page) {
    // Background freshness requests do not determine document readiness.
    // The load event includes scripts; each workflow asserts its visible state.
    await page.waitForLoadState('load');
    await expect(page.locator('body')).toBeVisible();
    await page.evaluate(async () => {
        await Promise.all(document.getAnimations().filter(animation=>Number.isFinite(animation.effect?.getTiming().iterations)).map(animation=>animation.finished.catch(()=>{})));
    });
}
const card = (page, title) => page.locator('#tasksGrid .task-card').filter({ has: page.locator('.task-title', { hasText: title }) });
async function create(page, title) {
    await settled(page); await page.goto('/tasks'); await settled(page); await page.locator('#newTaskBtn').click(); await page.locator('#taskTitle').fill(title);
    await page.locator('#taskDescription').fill('R44 actual clean-company multi-client scenario');
    await page.locator('#taskProject').selectOption({ label: 'R41 Core Project' }); await page.locator('#taskAssignee').selectOption({ label: 'R41 Member A' });
    await page.locator('#taskReviewer').selectOption({ label: 'R41 PM' }); await page.locator('#taskPriority').selectOption('High');
    const due = new Date(); due.setDate(due.getDate() + 8); await page.locator('#taskDueDate').fill(due.toISOString().slice(0, 10));
    await page.locator('#taskForm button[type=submit]').click(); await expect(card(page, title)).toBeVisible(); await settled(page);
}
async function fresh(page) {
    await page.bringToFront();
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await expect(page.locator('[data-client-notice]')).toContainText('Newer data', { timeout: 8000 });
    await page.locator('[data-client-notice] button').click(); await expect(page.locator('[data-client-notice]')).toBeHidden();
}
async function transition(page, title, action, prompts = []) {
    // A reload can render the card before DOMContentLoaded attaches its actions.
    await settled(page);
    const button = card(page, title).locator(`[data-transition="${action}"]`);
    await button.click();
    await expect(page.locator('.swal2-popup')).toBeVisible();
    for (const value of prompts) {
        await page.locator('.swal2-popup .swal2-input, .swal2-popup .swal2-textarea').filter({ visible: true }).fill(value);
        await page.locator('.swal2-confirm').click();
    }
    if (!prompts.length) await page.locator('.swal2-confirm').click();
    await expect(page.locator('.swal2-popup')).toContainText('Success'); await page.locator('.swal2-confirm').click();
    await settled(page);
}

test('R44 different-user polling detects creation and lifecycle focus revalidation retains safety', async ({ browser }) => {
    test.setTimeout(300_000);
    const contexts = await Promise.all([browser.newContext(), browser.newContext(), browser.newContext()]);
    const [a, b, c] = await Promise.all(contexts.map(ctx => ctx.newPage()));
    await login(a); await login(b, member); await login(c, pm);
    await b.goto('/tasks'); await c.goto('/tasks');
    const title = `R44 Multiuser Contract ${Date.now()}`; const started = Date.now(); await create(a, title);
    await expect(b.locator('[data-client-notice]')).toContainText('Newer data', { timeout: 65_000 });
    const elapsed = Date.now() - started;
    fs.writeFileSync(`${evidenceDir}/cross-user-timing.json`, JSON.stringify({ creationVisibleAfterMs: elapsed, pollingIntervalMs: 60_000 }));
    await b.locator('[data-client-notice] button').click(); await expect(card(b, title)).toBeVisible();
    await transition(b, title, 'start'); await fresh(c);
    await expect(card(c, title)).toContainText('In Progress');
    await transition(b, title, 'submit', ['R44 first submission']); await fresh(c); await expect(card(c, title)).toContainText('Submitted');
    await transition(c, title, 'review'); await fresh(b); await expect(card(b, title)).toContainText('In Review');
    const due = new Date(); due.setDate(due.getDate() + 9);
    await transition(c, title, 'revision-request', ['R44 revise details', due.toISOString().slice(0,10)]); await fresh(b); await expect(card(b, title)).toContainText('Revision Requested');
    await transition(b, title, 'revision-start'); await transition(b, title, 'resubmit', ['R44 corrected submission']); await fresh(c);
    await transition(c, title, 'review'); await fresh(b);
    await transition(c, title, 'approve', ['R44 approved']); await fresh(b); await expect(card(b, title)).toHaveCount(0);
    for (const ctx of contexts) await ctx.close();
});

test('R44 static-only service worker gives truthful offline navigation and preserves failed mutation', async ({ page, context }) => {
    await login(page); await page.goto('/tasks'); await page.evaluate(() => navigator.serviceWorker.ready);
    await expect.poll(() => page.evaluate(() => Boolean(navigator.serviceWorker.controller))).toBe(true);
    await page.locator('#newTaskBtn').click(); await page.locator('#taskTitle').fill('R44 Offline Unsaved');
    await page.locator('#taskDescription').fill('Offline mutation must preserve this draft.');
    await page.locator('#taskDueDate').fill(futureDate());
    await page.locator('#taskProject').selectOption({ label: 'R41 Core Project' }); await page.locator('#taskAssignee').selectOption({ label: 'R41 Member A' });
    await page.locator('#taskReviewer').selectOption({ label: 'R41 PM' });
    await context.setOffline(true); await page.locator('#taskForm button[type=submit]').click();
    await expect(page.locator('#taskModal')).toHaveClass(/active/); await expect(page.locator('#taskTitle')).toHaveValue('R44 Offline Unsaved');
    await expect(page.getByRole('dialog').filter({ hasText: /Error|connection|saving/i }).last()).toBeVisible();
    const other = await context.newPage(); await other.goto('/manager'); await expect(other.getByRole('heading', { name: 'You are offline' })).toBeVisible();
    const cached = await page.evaluate(async () => {
        const urls = []; for (const key of await caches.keys()) { for (const request of await (await caches.open(key)).keys()) urls.push(request.url); } return urls;
    });
    expect(cached.some(url => /\/(manager|tasks|projects|client\/freshness)(\?|$)/.test(url))).toBe(false);
    await context.setOffline(false); await other.getByRole('button', { name: 'Try again' }).click(); await expect(other.getByRole('heading', { name: 'Manager Dashboard' })).toBeVisible();
});

test('R44 installation dialog keyboard and update notice preserve unsaved input', async ({ page }) => {
    await login(page); await page.goto('/settings');
    await page.locator('.nav-item[data-tab="notifications"]').click();
    const trigger = page.locator('[data-pwa-open]'); await trigger.click();
    await expect(page.getByRole('dialog', { name: /Install Task Management/ })).toBeVisible();
    await page.keyboard.press('Escape'); await expect(trigger).toBeFocused();
    await page.goto('/tasks'); await page.locator('#newTaskBtn').click(); await page.locator('#taskTitle').fill('R44 preserved update draft');
    await page.evaluate(() => navigator.serviceWorker.ready);
    await expect.poll(() => page.evaluate(() => Boolean(navigator.serviceWorker.controller))).toBe(true);
    const path='public/service-worker.js'; const original=fs.readFileSync(path,'utf8');
    const version=original.match(/const CACHE_VERSION = `\$\{CACHE_NAMESPACE\}(v\d+)`;/)?.[1];
    expect(version, 'Worker exposes its explicit shell version').toBeTruthy();
    try {
        fs.writeFileSync(path, original.replace('${CACHE_NAMESPACE}'+version, '${CACHE_NAMESPACE}'+version+'-r44-update-probe'));
        await page.evaluate(async () => (await navigator.serviceWorker.getRegistration()).update());
        await expect(page.locator('[data-client-notice]')).toContainText('application update', { timeout: 15_000 });
        await expect(page.locator('#taskTitle')).toHaveValue('R44 preserved update draft');
        await expect.poll(async()=> (await page.evaluate(()=>caches.keys())).includes('task-management-static-%2F-'+version+'-r44-update-probe')).toBe(true);
        await expect.poll(async()=> (await page.evaluate(()=>caches.keys())).includes('task-management-static-%2F-'+version)).toBe(false);
    } finally { fs.writeFileSync(path,original); }
});

test('R44 notification transport failure recovers and successful read keeps header count coherent', async ({ page }) => {
    await login(page); await page.goto('/notifications/all');
    const button=page.locator('#markAllReadBtn'); await expect(button).toBeVisible();
    await page.route('**/notifications/read-all',route=>route.fulfill({status:503,contentType:'application/json',body:'{"message":"unavailable"}'}));
    await button.click(); await expect(page.locator('[data-client-notice]')).toContainText('could not be marked'); await expect(button).toBeEnabled();
    await expect(page.locator('.notification-page-item.unread').first()).toBeVisible();
    await page.unroute('**/notifications/read-all'); await button.click(); await expect(button).toHaveCount(0);
    await expect(page.locator('.notification-page-item.unread')).toHaveCount(0); await expect(page.locator('.notification-badge')).toHaveCount(0);
});

test('R44 project submit is single-flight and a failed save preserves input and focus', async ({ page }) => {
    await login(page); await page.goto('/projects'); await page.locator('#newProjectBtn').click();
    await page.locator('#projectName').fill('R44 preserved project draft');
    await page.locator('#projectManager').selectOption({label:'R41 PM'});
    let requests=0, release;
    const held=new Promise(resolve=>{release=resolve;});
    await page.route('**/projects',async route=>{
        if(route.request().method()!=='POST') return route.continue();
        requests++; await held; await route.fulfill({status:503,contentType:'application/json',body:'{"message":"Project could not be saved. Try again."}'});
    });
    await page.locator('#projectSubmitBtn').click(); await expect(page.locator('#projectSubmitBtn')).toBeDisabled();
    await page.locator('#projectForm').evaluate(form=>form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));
    await page.keyboard.press('Escape'); await expect(page.locator('#projectModal')).toHaveClass(/active/);
    release(); await expect(page.getByRole('dialog').filter({hasText:'Project could not be saved.'}).last()).toBeVisible();
    await page.locator('.swal2-confirm').click(); await expect(page.locator('#projectName')).toHaveValue('R44 preserved project draft');
    await expect(page.locator('#projectSubmitBtn')).toBeEnabled(); expect(requests).toBe(1);
    await page.keyboard.press('Escape'); await expect(page.locator('#newProjectBtn')).toBeFocused();
});

test('R44 freshness transport failure explains recovery without losing a draft', async ({ page }) => {
    await login(page);
    await page.route('**/client/freshness',route=>route.fulfill({status:503,contentType:'application/json',body:'{}'}));
    await page.goto('/tasks'); await expect(page.locator('[data-client-notice]')).toContainText('Unable to check');
    await page.locator('#newTaskBtn').click(); await page.locator('#taskTitle').fill('R44 connection recovery draft');
    await page.unroute('**/client/freshness'); await page.evaluate(()=>window.AppClient.check());
    await expect(page.locator('[data-client-notice]')).toBeHidden(); await expect(page.locator('#taskTitle')).toHaveValue('R44 connection recovery draft');
});

test('R44 logout and account switch clear the former account in an old open tab', async ({ page, context }) => {
    await login(page); await page.goto('/manager'); const old=await context.newPage(); await old.goto('/manager');
    await page.locator('form[action$="/logout"] button').click(); await expect(page.locator('#email')).toBeVisible();
    await login(page,member); await old.evaluate(()=>window.AppClient.check());
    await expect(old.getByRole('heading',{name:'Your session has changed'})).toBeVisible();
    await expect(old.getByText('R41 Manager',{exact:true})).toHaveCount(0);
    await old.locator('[data-client-notice] button').click(); await expect(old).toHaveURL(/\/team-dashboard$/);
});

test('R44 storage-event fallback and disabled storage still support server revalidation', async ({ browser }) => {
    const ctx=await browser.newContext(); await ctx.addInitScript(()=>{ window.BroadcastChannel=undefined; });
    const a=await ctx.newPage(), b=await ctx.newPage(); await login(a); await a.goto('/team-management?search=unicode%40r44'); await b.goto('/team-management?search=unicode%40r44');
    await a.getByRole('button',{name:'Edit आशा पाण्डे',exact:true}).click(); await a.locator('#editMemberName').fill('आशा पाण्डे Updated');
    await a.locator('#editMemberForm button[type=submit]').click(); await expect(b.locator('[data-client-notice]')).toContainText('Newer data');
    await a.getByRole('button',{name:'Edit आशा पाण्डे Updated',exact:true}).click(); await a.locator('#editMemberName').fill('आशा पाण्डे'); await a.locator('#editMemberForm button[type=submit]').click();
    await expect(a.getByRole('button',{name:'Edit आशा पाण्डे',exact:true})).toBeVisible(); await ctx.close();
    const disabled=await browser.newContext(); await disabled.addInitScript(()=>{ window.BroadcastChannel=undefined; Object.defineProperty(window,'localStorage',{get(){throw new DOMException('Blocked','SecurityError');}}); });
    const p=await disabled.newPage(); const errors=[]; p.on('pageerror',error=>errors.push(error.message)); await login(p); await p.goto('/tasks'); await p.evaluate(()=>window.AppClient.check());
    expect(errors).toEqual([]); await disabled.close();
});

test('R44 subdirectory URLs manifest scope registration and authenticated mutation are coherent', async ({ page }) => {
    test.skip(!process.env.R44_SUBPATH_URL, 'Requires the separately started local subdirectory qualification router.');
    const base=process.env.R44_SUBPATH_URL;
    const unexpected=[]; page.on('request',request=>{ const url=new URL(request.url()); if(url.origin===new URL(base).origin && !url.pathname.startsWith(new URL(base).pathname+'/')) unexpected.push(url.pathname); });
    await page.goto(base+'/login'); await page.locator('#email').fill(manager); await page.locator('#password').fill(password);
    await page.getByRole('button',{name:'Log in',exact:true}).click(); await expect(page).toHaveURL(base+'/manager');
    await page.goto(base+'/tasks'); await page.locator('#newTaskBtn').click(); await page.locator('#taskTitle').fill('R44 Subdirectory Task');
    await page.locator('#taskDescription').fill('Subdirectory mutation through actual scoped browser UI.');
    await page.locator('#taskProject').selectOption({label:'R41 Core Project'}); await page.locator('#taskAssignee').selectOption({label:'R41 Member A'}); await page.locator('#taskReviewer').selectOption({label:'R41 PM'});
    await page.locator('#taskDueDate').fill(futureDate()); await page.locator('#taskForm button[type=submit]').click(); await expect(card(page,'R44 Subdirectory Task')).toBeVisible();
    const registration=await page.evaluate(async()=>{ const reg=await navigator.serviceWorker.ready; return {scope:reg.scope,script:reg.active.scriptURL}; });
    expect(registration).toEqual({scope:base+'/',script:base+'/service-worker.js'});
    const manifest=await page.request.get(base+'/manifest.webmanifest'); expect(manifest.headers()['content-type']).toContain('application/manifest+json');
    const data=await manifest.json(); expect(data.scope).toBe('./'); expect(data.start_url).toBe('dashboard');
    for(const icon of data.icons) expect((await page.request.get(base+'/'+icon.src)).ok()).toBe(true);
    expect(unexpected).toEqual([]);
});

test('R44 core pages pass axe high-confidence WCAG A and AA checks', async ({ page }) => {
    const results = [];
    await page.goto('/login'); await settled(page); results.push({ route: '/login', result: await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze() });
    await login(page);
    for (const route of ['/manager', '/team-management', '/projects', '/tasks', '/analytics', '/notifications/all', '/settings']) {
        await page.goto(route); await settled(page); results.push({ route, result: await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze() });
    }
    fs.writeFileSync(`${evidenceDir}/axe.json`, JSON.stringify(results, null, 2));
    expect(results.flatMap(({ route, result }) => result.violations.map(v => `${route} ${v.id} ${v.nodes.map(n => n.target).join(';')}`))).toEqual([]);
});

test('R52 Manager, Team and PM dashboards retain one top-level main landmark', async ({ page }) => {
    const results = [];
    for (const [email, path] of [[manager, '/manager'], [member, '/team-dashboard'], [pm, '/manager']]) {
        await login(page, email);
        await page.goto(path);
        await settled(page);
        await expect(page.locator('main')).toHaveCount(1);
        await expect(page.locator('main#main-content')).toHaveCount(1);
        const result = await new AxeBuilder({ page }).withRules(['landmark-main-is-top-level', 'landmark-no-duplicate-main']).analyze();
        results.push({ email, path, violations: result.violations });
        expect(result.violations).toEqual([]);
        await page.locator('form[action$="/logout"] button').click();
    }
    fs.writeFileSync(`${evidenceDir}/r52-landmarks.json`, JSON.stringify(results, null, 2));
});

test('R44 responsive matrix preserves page and modal reflow including long content', async ({ page, browser }) => {
    test.setTimeout(300_000);
    const matrix = [[320,568],[375,667],[390,844],[430,932],[768,1024],[844,390],[1280,720],[1440,900],[720,450]];
    const results = [];
    for(const [width,height] of matrix) {
        await page.setViewportSize({width,height}); await page.goto('/login');
        results.push({width,height,route:'/login',...await page.evaluate(()=>({scroll:document.documentElement.scrollWidth,viewport:innerWidth}))});
    }
    await login(page);
    for (const [width,height] of matrix) {
        await page.setViewportSize({ width, height });
        for (const route of ['/manager','/team-management','/projects','/tasks','/analytics','/notifications/all','/settings']) {
            await page.goto(route);
            const metrics = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, viewport: innerWidth }));
            results.push({ width, height, route, ...metrics });
        }
        await page.goto('/tasks'); await page.locator('#newTaskBtn').click();
        await page.locator('#taskTitle').fill('आशा '.repeat(63).slice(0,255));
        await expect(page.locator('#taskForm button[type=submit]')).toBeVisible();
        await page.locator('#taskForm button[type=submit]').scrollIntoViewIfNeeded();
        if (width === 320 || width === 1440) await page.screenshot({ path:`${process.env.BROWSER_OUTPUT_DIR || 'output/playwright'}/r44-modal-${width}.png`, fullPage: true });
        await page.keyboard.press('Escape');
    }
    const ctx = await browser.newContext(); const p = await ctx.newPage(); await login(p, member);
    for (const [width,height] of matrix) { await p.setViewportSize({ width,height }); await p.goto('/team-dashboard'); results.push({ width,height,route:'/team-dashboard',...await p.evaluate(()=>({scroll:document.documentElement.scrollWidth,viewport:innerWidth})) }); }
    await ctx.close(); fs.writeFileSync(`${evidenceDir}/responsive.json`,JSON.stringify(results,null,2));
    expect(results.filter(row=>row.scroll>row.viewport+1)).toEqual([]);
});

for (const engine of ['chromium','firefox','webkit']) {
    test.describe(`R44 ${engine} smoke`, () => {
        test('login, creation, lifecycle, keyboard, filters, core pages and logout', async () => {
            // Independent engine scenarios must not inherit the preceding suite's
            // per-user freshness budget; production throttles remain enabled.
            execFileSync(process.env.PHP_BINARY || 'php', ['tests/Support/r44_browser_rate_reset.php'], { stdio: 'pipe' });
            let browser;
            try { browser=await ({chromium,firefox,webkit})[engine].launch(); }
            catch(error) {
                if(engine==='firefox' && process.platform==='win32' && error.message.includes('spawn UNKNOWN')) {
                    fs.writeFileSync(`${evidenceDir}/firefox-launch-limitation.txt`, error.message+'\nDirect binary check: Windows reports incorrect side-by-side configuration.\n');
                    test.skip(true,'Installed Firefox cannot start on this Windows host: incorrect side-by-side configuration.');
                }
                throw error;
            }
            try {
            const context=await browser.newContext({ baseURL: process.env.APP_URL || 'http://127.0.0.1:8046' }); const page=await context.newPage();
            page.setDefaultTimeout(30_000);
            page.setDefaultNavigationTimeout(25_000);
            let stage='initial'; const step=message=>{ stage=message; fs.appendFileSync(`${evidenceDir}/${engine}-steps.txt`,message+'\n'); };
            const errors=[]; page.on('pageerror', e=>{ errors.push(e.message); fs.appendFileSync(`${evidenceDir}/${engine}-console.jsonl`,JSON.stringify({message:e.message,stack:e.stack,page:page.url(),stage,at:Date.now()})+'\n'); });
            page.on('console', msg=>{ if(msg.type()==='error') { errors.push(msg.text()); fs.appendFileSync(`${evidenceDir}/${engine}-console.jsonl`,JSON.stringify({message:msg.text(),location:msg.location(),page:page.url(),at:Date.now()})+'\n'); } });
            await login(page); step('login'); await page.goto('/team-management?search=unicode%40r44');
            await expect(page.locator('.member-avatar')).toHaveText('आपा');
            const edit=page.getByRole('button',{name:'Edit आशा पाण्डे',exact:true}); await edit.focus(); await page.keyboard.press('Space');
            await expect(page.getByRole('dialog',{name:'Edit Member Details'})).toBeVisible(); await page.keyboard.press('Escape'); await expect(edit).toBeFocused();
            step('keyboard');
            const peer=await context.newPage(); await peer.goto('/tasks'); await peer.locator('#newTaskBtn').click(); await peer.locator('#taskTitle').fill('Preserved parallel window draft');
            const title=`R44 ${engine} Smoke ${Date.now()}`; await create(page,title); step('create');
            await peer.bringToFront(); await peer.evaluate(()=>window.dispatchEvent(new Event('focus')));
            await expect(peer.locator('[data-client-notice]')).toContainText('Newer data',{timeout:8000});
            await expect(peer.locator('#taskTitle')).toHaveValue('Preserved parallel window draft'); await settled(peer); await peer.close(); step('parallel window');
            const ctx=await browser.newContext({ baseURL: process.env.APP_URL || 'http://127.0.0.1:8046' }); const worker=await ctx.newPage(); await login(worker,member); await worker.goto('/tasks');
            await transition(worker,title,'start'); step('start'); await ctx.close(); step('worker close');
            for(const route of ['/analytics','/notifications/all','/settings','/projects']) { step('before '+route); await page.goto(route); await expect(page.locator('main')).toBeVisible(); await settled(page); step('after '+route); }
            step('core pages');
            await page.setViewportSize({width:390,height:844}); step('mobile resize'); await page.locator('#mobileMenuBtn').click(); step('mobile menu'); await expect(page.locator('#primaryNavigation')).toHaveClass(/open/);
            await page.keyboard.press('Escape'); step('mobile escape'); await page.locator('form[action$="/logout"] button').click();
            await expect(page.locator('#email')).toBeVisible(); await settled(page); step('logout'); expect(errors).toEqual([]);
            } finally {
                await browser.close();
            }
        });
    });
}
