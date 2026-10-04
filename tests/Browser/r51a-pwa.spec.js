import { test, expect, chromium, webkit } from '@playwright/test';
import fs from 'node:fs';
import http from 'node:http';

const password = 'R41-browser-unique-secret-123!';
const evidence = `${process.env.R44_EVIDENCE_DIR || 'output/r44'}/r51a`;
fs.mkdirSync(evidence, { recursive: true });
const root = process.env.APP_URL || 'http://127.0.0.1:8046';
const deferred = () => { let resolve; const promise = new Promise(r => { resolve = r; }); return { promise, resolve }; };

async function networkBoundary(upstream) {
    const target = new URL(upstream); let online = true;
    const server = http.createServer((request, response) => {
        if (!online) { request.socket.destroy(); return; }
        const forwarded = http.request({ hostname: target.hostname, port: target.port, path: request.url, method: request.method, headers: request.headers }, received => {
            response.writeHead(received.statusCode, received.headers); received.pipe(response);
        });
        forwarded.on('error', error => response.destroy(error));
        request.pipe(forwarded);
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    return {
        base: `http://127.0.0.1:${server.address().port}${target.pathname.replace(/\/$/, '')}`,
        setOnline: value => { online = value; },
        close: () => new Promise(resolve => server.close(resolve)),
    };
}

function observe(context) {
    const errors = [], failures = [], responses = [];
    context.on('page', page => {
        page.on('pageerror', error => errors.push({ page: page.url(), message: error.message }));
        page.on('console', message => { if (message.type() === 'error') errors.push({ page: page.url(), message: message.text() }); });
        page.on('response', response => responses.push({ url: response.url(), status: response.status(), location: response.headers().location }));
        page.on('requestfailed', request => failures.push({ url: request.url(), reason: request.failure()?.errorText }));
    });
    return { errors, failures, responses };
}
async function login(page, base, email = 'manager@r41.example.invalid') {
    await page.goto(`${base}/login`);
    await ready(page);
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(password);
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await expect(page).not.toHaveURL(/\/login$/);
    await page.waitForLoadState('networkidle');
}
async function ready(page) {
    await page.evaluate(() => navigator.serviceWorker.ready);
    await expect.poll(() => page.evaluate(() => navigator.serviceWorker.controller?.state)).toBe('activated');
    await page.waitForLoadState('networkidle');
}
async function updateWorker(page) {
    await page.evaluate(async () => (await navigator.serviceWorker.getRegistration()).update());
    await expect.poll(() => page.evaluate(async () => {
        const registration = await navigator.serviceWorker.getRegistration();
        return !registration.installing && !registration.waiting && registration.active?.state === 'activated';
    })).toBe(true);
}
async function state(page) {
    return page.evaluate(async () => ({
        origin: location.origin, secure: isSecureContext,
        serviceWorker: 'serviceWorker' in navigator, notification: typeof Notification,
        pushManager: typeof PushManager, registration: typeof ServiceWorkerRegistration,
        showNotification: typeof ServiceWorkerRegistration.prototype.showNotification,
        subscription: 'pushManager' in ServiceWorkerRegistration.prototype,
        permissions: typeof navigator.permissions, installPrompt: 'onbeforeinstallprompt' in window,
        permission: typeof Notification === 'undefined' ? null : Notification.permission,
        controller: navigator.serviceWorker.controller?.scriptURL,
        registrations: (await navigator.serviceWorker.getRegistrations()).map(reg => ({ scope: reg.scope, script: reg.active?.scriptURL, state: reg.active?.state })),
        cache: await Promise.all((await caches.keys()).map(async name => ({ name, urls: (await (await caches.open(name)).keys()).map(request => request.url) }))),
    }));
}

for (const engine of ['chromium', 'webkit']) {
    for (const [label, upstream] of [['root', root], ['subdirectory', process.env.R44_SUBPATH_URL]]) {
        test(`R51A ${engine} ${label} worker offline update and account safety`, async () => {
            test.skip(!upstream, 'Requires the established subdirectory qualification server.');
            // Disconnect a real loopback network boundary. WebKit's setOffline emulation bypasses worker fallback.
            const network = await networkBoundary(upstream); const base = network.base;
            const browser = await ({ chromium, webkit })[engine].launch();
            const context = await browser.newContext(); const events = observe(context); const page = await context.newPage();
            const workerPath = 'public/service-worker.js'; const original = fs.readFileSync(workerPath, 'utf8');
            try {
                await login(page, base); await page.goto(`${base}/tasks`); await ready(page);
                const initial = await state(page);
                fs.writeFileSync(`${evidence}/${engine}-${label}-initial.json`, JSON.stringify(initial, null, 2));
                expect(initial.secure).toBe(true);
                expect(initial.registrations).toEqual([{ scope: `${base}/`, script: `${base}/service-worker.js`, state: 'activated' }]);
                expect(initial.controller).toBe(`${base}/service-worker.js`);
                const manifest = await (await page.request.get(`${base}/manifest.webmanifest`)).json();
                expect(new URL(manifest.scope, `${base}/manifest.webmanifest`).href).toBe(`${base}/`);
                expect(new URL(manifest.start_url, `${base}/manifest.webmanifest`).href).toBe(`${base}/dashboard`);
                const offline = await context.newPage(); await offline.goto(`${base}/manager`); await ready(offline);
                network.setOnline(false); await offline.reload();
                await expect(offline.getByRole('heading', { name: 'You are offline' })).toBeVisible();
                await expect(offline.getByText('R41 Manager', { exact: true })).toHaveCount(0);
                const styled = await offline.evaluate(() => new Promise((resolve, reject) => {
                    const link = document.createElement('link'); link.rel = 'stylesheet'; link.href = new URL('css/phase3.css', location.href);
                    link.onload = () => resolve(link.sheet.cssRules.length > 0); link.onerror = () => reject(new Error('Cached stylesheet unavailable')); document.head.append(link);
                }));
                expect(styled).toBe(true);
                network.setOnline(true); await offline.getByRole('button', { name: 'Try again' }).click();
                await expect(offline.getByRole('heading', { name: 'Manager Dashboard' })).toBeVisible(); await ready(offline); await offline.close();
                await page.locator('#newTaskBtn').click(); await page.locator('#taskTitle').fill('Keep the update draft');
                const version = original.match(/const CACHE_VERSION = `\$\{CACHE_NAMESPACE\}(v\d+)`;/)[1];
                const namespace = `task-management-static-${encodeURIComponent(new URL(`${base}/`).pathname)}-`;
                await page.evaluate(async name => { await caches.open(name); }, `${namespace}obsolete-r51a`);
                fs.writeFileSync(workerPath, original.replace('${CACHE_NAMESPACE}' + version, '${CACHE_NAMESPACE}' + version + '-r51a-probe'));
                await updateWorker(page);
                await expect(page.locator('[data-client-notice]')).toContainText('application update');
                await expect(page.locator('#taskTitle')).toHaveValue('Keep the update draft');
                await expect.poll(() => page.evaluate(() => caches.keys())).toContain(`${namespace}${version}-r51a-probe`);
                await expect.poll(() => page.evaluate(() => caches.keys())).not.toContain(`${namespace}obsolete-r51a`);
                const cache = (await state(page)).cache;
                expect(cache.some(entry => entry.name === `${namespace}obsolete-r51a`)).toBe(false);
                expect(cache.flatMap(entry => entry.urls).every(url => /\/(manifest\.webmanifest|offline\.html|build\/assets\/|css\/|js\/|icons\/|images\/)/.test(new URL(url).pathname))).toBe(true);
                fs.writeFileSync(workerPath, original);
                await updateWorker(page);
                await expect.poll(() => page.evaluate(() => caches.keys())).toContain(`${namespace}${version}`);
                await page.goto(`${base}/settings`); await ready(page);
                await page.locator('.nav-item[data-tab="notifications"]').click();
                await expect(page.locator('[data-push-status]')).toHaveAttribute('data-state', initial.pushManager === 'undefined' || initial.showNotification !== 'function' ? 'unsupported' : 'unavailable');
                await expect(page.locator('[data-push-enable]')).toBeHidden();
                await page.locator('form[action$="/logout"] button').click(); await expect(page.locator('#email')).toBeVisible(); await ready(page);
                network.setOnline(false); await page.goto(`${base}/manager`);
                await expect(page.getByRole('heading', { name: 'You are offline' })).toBeVisible();
                await expect(page.getByText('R41 Manager', { exact: true })).toHaveCount(0);
                network.setOnline(true); await login(page, base, 'a@r41.example.invalid');
                await expect(page).toHaveURL(new RegExp('/team-dashboard$'));
                await expect(page.getByText('R41 Manager', { exact: true })).toHaveCount(0);
                fs.writeFileSync(`${evidence}/${engine}-${label}.json`, JSON.stringify({ initial, after: await state(page), ...events }, null, 2));
                expect(events.errors).toEqual([]);
                // Offline is deliberate in this test; every failure must belong to the offline navigation/static probe.
                expect(events.failures.every(failure => [new URL(`${base}/manager`).href, new URL(`${base}/css/phase3.css`).href].includes(failure.url))).toBe(true);
            } finally { fs.writeFileSync(`${evidence}/${engine}-${label}-network.json`, JSON.stringify(events, null, 2)); fs.writeFileSync(workerPath, original); await browser.close(); await network.close(); }
        });
    }

    test(`R51A ${engine} absent PushManager preserves worker and honest status`, async () => {
        const browser = await ({ chromium, webkit })[engine].launch(); const context = await browser.newContext();
        await context.addInitScript(() => { delete window.PushManager; });
        const events = observe(context); const requests = [];
        context.on('request', request => { if (new URL(request.url()).pathname.startsWith('/push/')) requests.push(request.url()); });
        try {
            const page = await context.newPage(); await login(page, root); await page.goto(`${root}/settings`); await ready(page);
            await page.locator('.nav-item[data-tab="notifications"]').click();
            await expect(page.locator('[data-push-status]')).toHaveAttribute('data-state', 'unsupported');
            await expect(page.locator('[data-push-enable]')).toBeHidden();
            expect(requests).toEqual([]); expect(events.errors).toEqual([]); expect(events.failures).toEqual([]);
            expect((await state(page)).controller).toBe(`${root}/service-worker.js`);
        } finally { await browser.close(); }
    });

    test(`R51A ${engine} optional status failure respects capability and explicit retry`, async () => {
        const browser = await ({ chromium, webkit })[engine].launch(); const context = await browser.newContext(); const events = observe(context);
        try {
            // Inject only this optional transport failure; WebKit does not route worker-controlled fetches.
            await context.addInitScript(() => {
                const fetch = window.fetch.bind(window); window.r51aStatusAttempts = 0;
                window.fetch = (...args) => {
                    if (String(args[0]).endsWith('/push/status')) {
                        window.r51aStatusAttempts++;
                        return Promise.reject(new TypeError('Notification status unavailable'));
                    }
                    return fetch(...args);
                };
            });
            const page = await context.newPage(); await login(page, root); await page.goto(`${root}/settings`); await ready(page);
            await page.locator('.nav-item[data-tab="notifications"]').click();
            const capability = await state(page);
            if (capability.showNotification !== 'function' || capability.pushManager === 'undefined') {
                await expect(page.locator('[data-push-status]')).toHaveAttribute('data-state', 'unsupported');
                await expect(page.locator('[data-push-enable]')).toBeHidden();
                expect(await page.evaluate(() => window.r51aStatusAttempts)).toBe(0);
                expect(events.errors).toEqual([]); expect(events.failures).toEqual([]);
                return;
            }
            await expect(page.locator('[data-push-status]')).toHaveAttribute('data-state', 'stale');
            await page.locator('[data-push-enable]').click();
            await expect(page.locator('[data-push-message]')).toContainText('Notification status unavailable');
            await expect(page.locator('[data-push-status]')).not.toHaveAttribute('data-state', 'enabled');
            expect(await page.evaluate(() => window.r51aStatusAttempts)).toBe(2);
            expect(events.errors).toEqual([]); expect(events.failures).toEqual([]);
        } finally { await browser.close(); }
    });
}

test('R51A WebKit navigation cancels registration without retrying the departing document', async () => {
    const browser = await webkit.launch(); const context = await browser.newContext(); const events = observe(context);
    const first = deferred(), release = deferred(); let stalled = 0;
    try {
        const page = await context.newPage();
        await context.route('**/service-worker.js', async route => {
            if (page.url().endsWith('/login')) {
                stalled++; const response = await route.fetch(); first.resolve(); await release.promise;
                if (!page.isClosed()) await route.fulfill({ response });
            } else await route.continue();
        });
        await page.goto(`${root}/login`, { waitUntil: 'domcontentloaded' }); await first.promise;
        await page.goto(`${root}/login?generation=next`, { waitUntil: 'domcontentloaded' }); release.resolve(); await ready(page);
        expect(stalled).toBe(1); expect(events.errors).toEqual([]);
        expect(events.failures.every(failure => failure.url === `${root}/service-worker.js` && failure.reason === 'Load request cancelled')).toBe(true);
        fs.writeFileSync(`${evidence}/webkit-registration-cancellation.json`, JSON.stringify(events, null, 2));
    } finally { release.resolve(); await browser.close(); }
});
