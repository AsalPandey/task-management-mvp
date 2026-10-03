const CACHE_PREFIX = 'task-management-static-';
const APP_SCOPE = new URL(self.registration.scope);
const APP_BASE_PATH = APP_SCOPE.pathname.replace(/\/$/, '');
const CACHE_NAMESPACE = `${CACHE_PREFIX}${encodeURIComponent(APP_SCOPE.pathname)}-`;
const CACHE_VERSION = `${CACHE_NAMESPACE}v3`;
const appPath = path => `${APP_BASE_PATH}/${String(path).replace(/^\/+/, '')}`;
const STATIC_ASSETS = [
    'manifest.webmanifest',
    'css/phase3.css',
    'js/phase3.js',
    'js/pwa.js',
    'js/client.js',
    'js/notifications.js',
    'offline.html',
    'icons/pwa-192.png',
    'icons/pwa-512.png',
    'icons/pwa-maskable-512.png',
    'icons/pwa-badge-96.png',
].map(appPath);

self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_VERSION)
            .then(cache => cache.addAll(STATIC_ASSETS))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys()
            .then(keys => Promise.all(
                keys
                    .filter(key => (key.startsWith(CACHE_NAMESPACE) || /^task-management-static-v\d+$/.test(key)) && key !== CACHE_VERSION)
                    .map(key => caches.delete(key)),
            ))
            .then(() => self.clients.claim())
            .then(() => self.clients.matchAll({ type: 'window' }))
            .then(clients => clients.forEach(client => client.postMessage({ type: 'APP_UPDATED' }))),
    );
});

self.addEventListener('fetch', event => {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin || !url.pathname.startsWith(APP_SCOPE.pathname)) return;
    // Authenticated navigation is always network-only; the fallback contains no company/user data.
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(appPath('offline.html'))));
        return;
    }
    const staticDestination = ['style', 'script', 'image', 'font'].includes(request.destination);
    const relativePath = url.pathname.slice(APP_BASE_PATH.length);
    const staticPath = /^\/(?:build\/assets|css|icons|images|js)\//.test(relativePath);
    if (url.origin !== self.location.origin || !staticDestination || !staticPath) return;
    // Hold the handle before the network await so a retired worker cannot recreate a deleted cache.
    const cachePromise = caches.open(CACHE_VERSION);

    event.respondWith(
        fetch(request)
            .then(response => {
                if (response.ok && response.type === 'basic') {
                    const clone = response.clone();
                    event.waitUntil(cachePromise.then(cache => cache.put(request, clone)));
                }
                return response;
            })
            .catch(async () => await caches.match(request) || Response.error()),
    );
});

function safeTarget(rawTarget) {
    const fallbackTarget = appPath('notifications/all');
    try {
        const candidate = new URL(rawTarget || fallbackTarget, self.location.origin);
        const relative = candidate.pathname.slice(APP_BASE_PATH.length);
        if (candidate.origin === self.location.origin && candidate.pathname.startsWith(APP_SCOPE.pathname)
            && /^\/(?:tasks|projects|dashboard|notifications\/all)(?:\/|$)/.test(relative)) {
            return `${candidate.pathname}${candidate.search}${candidate.hash}`;
        }
    } catch { /* use the safe application destination */ }
    return fallbackTarget;
}

self.addEventListener('push', event => {
    let payload = {};
    try {
        payload = event.data?.json() || {};
    } catch {
        payload = {};
    }

    const target = safeTarget(payload?.data?.target);

    event.waitUntil(Promise.all([self.registration.showNotification(
        String(payload.title || 'Task Management MVP').slice(0, 80),
        {
            body: String(payload.body || 'A task has an update.').slice(0, 180),
            icon: appPath('icons/pwa-192.png'),
            badge: appPath('icons/pwa-badge-96.png'),
            tag: String(payload.tag || 'task-management-update').slice(0, 120),
            data: { target },
        },
    ), self.clients.matchAll({ type: 'window' }).then(clients => clients.forEach(client => client.postMessage({ type: 'REVALIDATE' })))]));
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    const target = safeTarget(event.notification?.data?.target);

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clients => {
            for (const client of clients) {
                if (client.url === new URL(target, self.location.origin).href) {
                    return client.focus();
                }
            }

            return self.clients.openWindow(target);
        }),
    );
});
