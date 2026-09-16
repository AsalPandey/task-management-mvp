(() => {
    'use strict';

    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
    const userId = document.body.dataset.userId || '';
    const manifestUrl = new URL(
        document.querySelector('link[rel="manifest"]')?.href || '/manifest.webmanifest',
        window.location.href,
    );
    const appUrl = path => new URL(String(path).replace(/^\/+/, ''), manifestUrl);
    const serviceWorkerUrl = appUrl('service-worker.js');
    const serviceWorkerScope = new URL('.', manifestUrl).pathname;
    const statusElements = () => document.querySelectorAll('[data-push-status]');
    const messageElements = () => document.querySelectorAll('[data-push-message]');
    let registration = null;
    let serverStatus = null;
    let installPrompt = null;

    const secureContext = window.isSecureContext
        || ['localhost', '127.0.0.1'].includes(window.location.hostname);
    const supported = 'serviceWorker' in navigator
        && 'PushManager' in window
        && 'Notification' in window;

    function showMessage(message, state = 'info') {
        messageElements().forEach(element => {
            element.textContent = message;
            element.dataset.state = state;
            element.hidden = false;
        });
    }

    function statusLabel(state) {
        return {
            unsupported: 'Unsupported',
            unavailable: 'Configuration unavailable',
            blocked: 'Permission blocked',
            enabled: 'Enabled on this device',
            stale: 'Subscription stale',
            disabled: 'Not enabled',
        }[state] || 'Not enabled';
    }

    function render(state) {
        statusElements().forEach(element => {
            element.textContent = statusLabel(state);
            element.dataset.state = state;
        });
        document.querySelectorAll('[data-push-enable]').forEach(button => {
            button.hidden = !['disabled', 'stale'].includes(state);
        });
        document.querySelectorAll('[data-push-disable], [data-push-test]').forEach(button => {
            button.hidden = state !== 'enabled';
        });
        document.querySelectorAll('[data-push-blocked-help]').forEach(element => {
            element.hidden = state !== 'blocked';
        });
    }

    async function api(url, method = 'GET', payload = null) {
        const response = await fetch(url, {
            method,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: payload ? JSON.stringify(payload) : null,
            credentials: 'same-origin',
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.message || 'The notification request failed.');
        return data;
    }

    function applicationServerKey(value) {
        const padding = '='.repeat((4 - value.length % 4) % 4);
        const base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
        return Uint8Array.from(atob(base64), character => character.charCodeAt(0));
    }

    async function currentSubscription() {
        registration ||= await navigator.serviceWorker.register(serviceWorkerUrl.pathname, {
            scope: serviceWorkerScope,
        });
        await navigator.serviceWorker.ready;
        return registration.pushManager.getSubscription();
    }

    function serialize(subscription) {
        const json = subscription.toJSON();
        return {
            endpoint: subscription.endpoint,
            keys: json.keys,
            content_encoding: (PushManager.supportedContentEncodings || ['aes128gcm'])[0],
            device_label: navigator.userAgentData?.platform || navigator.platform || 'Current browser',
        };
    }

    async function refresh() {
        if (!supported || !secureContext) {
            if (userId) render('unsupported');
            return;
        }

        registration ||= await navigator.serviceWorker.register('/service-worker.js', { scope: '/' });
        if (!userId) return;

        serverStatus = await api(appUrl('push/status'));
        if (!serverStatus.configured || !serverStatus.public_key) {
            render('unavailable');
            return;
        }
        if (Notification.permission === 'denied') {
            render('blocked');
            return;
        }

        const subscription = await currentSubscription();
        if (!subscription) {
            render('disabled');
            return;
        }

        const previousUser = localStorage.getItem('task-management.push-user');
        if (previousUser !== userId) {
            render('stale');
            return;
        }

        await api('/push/subscriptions', 'POST', serialize(subscription));
        render('enabled');
    }

    async function enable() {
        if (!supported || !secureContext) {
            render('unsupported');
            return;
        }
        serverStatus ||= await api(appUrl('push/status'));
        if (!serverStatus.configured) {
            render('unavailable');
            return;
        }

        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
            render(permission === 'denied' ? 'blocked' : 'disabled');
            return;
        }

        registration ||= await navigator.serviceWorker.register(serviceWorkerUrl.pathname, {
            scope: serviceWorkerScope,
        });
        let subscription = await registration.pushManager.getSubscription();
        subscription ||= await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: applicationServerKey(serverStatus.public_key),
        });
        await api(appUrl('push/subscriptions'), 'POST', serialize(subscription));
        localStorage.setItem('task-management.push-user', userId);
        render('enabled');
        showMessage('Notifications are enabled for this browser and device.', 'success');
    }

    async function disable({ quiet = false } = {}) {
        if (!supported) return;
        const subscription = await currentSubscription();
        if (subscription) {
            await api(appUrl('push/subscriptions'), 'DELETE', { endpoint: subscription.endpoint });
            await subscription.unsubscribe();
        }
        localStorage.removeItem('task-management.push-user');
        render('disabled');
        if (!quiet) showMessage('Notifications are disabled for this device.', 'success');
    }

    async function testNotification() {
        const subscription = await currentSubscription();
        if (!subscription) {
            render('stale');
            return;
        }
        await api(appUrl('push/test'), 'POST', { endpoint: subscription.endpoint });
        showMessage('A test notification was queued for this device.', 'success');
    }

    function installExperience() {
        window.addEventListener('beforeinstallprompt', event => {
            event.preventDefault();
            installPrompt = event;
            if (localStorage.getItem('task-management.install-dismissed') !== '1') {
                document.querySelectorAll('[data-pwa-install]').forEach(button => {
                    button.hidden = false;
                });
            }
        });
        document.querySelectorAll('[data-pwa-install]').forEach(button => {
            button.addEventListener('click', async () => {
                if (!installPrompt) return;
                await installPrompt.prompt();
                installPrompt = null;
                button.hidden = true;
            });
        });
        document.querySelectorAll('[data-pwa-dismiss]').forEach(button => {
            button.addEventListener('click', () => {
                localStorage.setItem('task-management.install-dismissed', '1');
                button.closest('[data-pwa-card]')?.remove();
            });
        });
    }

    document.addEventListener('DOMContentLoaded', async () => {
        document.querySelectorAll('[data-push-enable]').forEach(button => button.addEventListener('click', () => enable().catch(error => showMessage(error.message, 'error'))));
        document.querySelectorAll('[data-push-disable]').forEach(button => button.addEventListener('click', () => disable().catch(error => showMessage(error.message, 'error'))));
        document.querySelectorAll('[data-push-test]').forEach(button => button.addEventListener('click', () => testNotification().catch(error => showMessage(error.message, 'error'))));
        installExperience();

        if (supported && userId) {
            const previousUser = localStorage.getItem('task-management.push-user');
            if (previousUser && previousUser !== userId) {
                await disable({ quiet: true }).catch(() => {});
            }
        }

        document.querySelectorAll('form[action$="/logout"]').forEach(form => {
            form.addEventListener('submit', async event => {
                if (form.dataset.pushCleanupDone === 'true') return;
                event.preventDefault();
                await disable({ quiet: true }).catch(() => {});
                form.dataset.pushCleanupDone = 'true';
                form.submit();
            });
        });

        await refresh().catch(() => {
            if (userId) render('stale');
        });
    });
})();
