const CACHE_VERSION = 'task-management-static-v1';
const APP_SCOPE = new URL(self.registration.scope);
const APP_BASE_PATH = APP_SCOPE.pathname.replace(/\/$/, '');
const appPath = path => `${APP_BASE_PATH}/${String(path).replace(/^\/+/, '')}`;
const STATIC_ASSETS = [
    'manifest.webmanifest',
    'css/phase3.css',
    'js/phase3.js',
    'js/pwa.js',
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
                keys.filter(key => key !== CACHE_VERSION).map(key => caches.delete(key)),
            ))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', event => {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    const staticDestination = ['style', 'script', 'image', 'font'].includes(request.destination);
    const relativePath = url.pathname.slice(APP_BASE_PATH.length);
    const staticPath = /^\/(?:build\/assets|css|icons|images|js)\//.test(relativePath);
    if (url.origin !== self.location.origin || !staticDestination || !staticPath) return;

    event.respondWith(
        fetch(request)
            .then(response => {
                if (response.ok && response.type === 'basic') {
                    const clone = response.clone();
                    caches.open(CACHE_VERSION).then(cache => cache.put(request, clone));
                }
                return response;
            })
            .catch(() => caches.match(request)),
    );
});

self.addEventListener('push', event => {
    let payload = {};
    try {
        payload = event.data?.json() || {};
    } catch {
        payload = {};
    }

    const fallbackTarget = appPath('notifications/all');
    const rawTarget = payload?.data?.target || fallbackTarget;
    let target = fallbackTarget;
    try {
        const candidate = new URL(rawTarget, self.location.origin);
        if (candidate.origin === self.location.origin && candidate.pathname.startsWith('/')) {
            target = `${candidate.pathname}${candidate.search}${candidate.hash}`;
        }
    } catch {
        target = fallbackTarget;
    }

    event.waitUntil(self.registration.showNotification(
        String(payload.title || 'Task Management').slice(0, 80),
        {
            body: String(payload.body || 'A task has an update.').slice(0, 180),
            icon: appPath('icons/pwa-192.png'),
            badge: appPath('icons/pwa-badge-96.png'),
            tag: String(payload.tag || 'task-management-update').slice(0, 120),
            data: { ...(payload.data || {}), target },
        },
    ));
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    const target = event.notification?.data?.target || appPath('notifications/all');

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clients => {
            for (const client of clients) {
                if (new URL(client.url).origin === self.location.origin) {
                    return client.focus().then(windowClient => windowClient.navigate(target));
                }
            }

            return self.clients.openWindow(target);
        }),
    );
});
