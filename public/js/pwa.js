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
    let automaticOfferTimer = null;
    let installReturnFocus = null;
    let hadController = Boolean(navigator.serviceWorker?.controller);
    const initialController = hadController;
    const storage = {
        getItem: key => { try { return localStorage.getItem(key); } catch { return null; } },
        setItem: (key, value) => { try { localStorage.setItem(key, value); } catch { /* optional preference */ } },
        removeItem: key => { try { localStorage.removeItem(key); } catch { /* optional preference */ } },
    };
    const installDismissedAtKey = 'task-management.install-dismissed-at';
    const installedAtKey = 'task-management.installed-at';
    const installCooldownMs = 30 * 24 * 60 * 60 * 1000;

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
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 8000);
        try {
            const response = await fetch(url, {
                method,
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: payload ? JSON.stringify(payload) : null,
                credentials: 'same-origin',
                signal: controller.signal,
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.message || 'The notification request failed.');
            return data;
        } finally { clearTimeout(timeout); }
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
        let timeout;
        try {
            await Promise.race([navigator.serviceWorker.ready, new Promise((_, reject) => {
                timeout = setTimeout(() => reject(new Error('Notifications are unavailable. Reload this page and try again.')), 3000);
            })]);
        } finally { clearTimeout(timeout); }
        try {
            return await Promise.race([registration.pushManager.getSubscription(), new Promise((_, reject) => {
                timeout = setTimeout(() => reject(new Error('Notifications are unavailable. Reload this page and try again.')), 3000);
            })]);
        } finally { clearTimeout(timeout); }
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

        registration ||= await navigator.serviceWorker.register(serviceWorkerUrl.pathname, { scope: serviceWorkerScope, updateViaCache: 'none' });
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

        const previousUser = storage.getItem('task-management.push-user');
        if (previousUser !== userId) {
            render('stale');
            return;
        }

        await api(appUrl('push/subscriptions'), 'POST', serialize(subscription));
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
        storage.setItem('task-management.push-user', userId);
        render('enabled');
        showMessage('Notifications are enabled for this browser and device.', 'success');
    }

    async function disable({ quiet = false } = {}) {
        if (!supported || Notification.permission !== 'granted') {
            storage.removeItem('task-management.push-user');
            return;
        }
        const subscription = await currentSubscription();
        if (subscription) {
            await api(appUrl('push/subscriptions'), 'DELETE', { endpoint: subscription.endpoint });
            let timeout;
            try {
                await Promise.race([subscription.unsubscribe(), new Promise((_, reject) => {
                    timeout = setTimeout(() => reject(new Error('Device notifications could not be disabled. Try again.')), 3000);
                })]);
            } finally { clearTimeout(timeout); }
        }
        storage.removeItem('task-management.push-user');
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

    function isStandalone() {
        return window.matchMedia('(display-mode: standalone)').matches
            || window.navigator.standalone === true;
    }

    function isMobileDevice() {
        if (navigator.userAgentData?.mobile === true) return true;

        const ipadDesktopMode = navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1;
        return ipadDesktopMode || /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent);
    }

    function dismissalIsCoolingDown() {
        const dismissedAt = Number(storage.getItem(installDismissedAtKey));
        if (Number.isFinite(dismissedAt) && dismissedAt > 0) {
            return Date.now() - dismissedAt < installCooldownMs;
        }

        if (storage.getItem('task-management.install-dismissed') === '1') {
            storage.setItem(installDismissedAtKey, String(Date.now()));
            storage.removeItem('task-management.install-dismissed');
            return true;
        }

        return false;
    }

    function installationGuide() {
        if (isStandalone() || storage.getItem(installedAtKey)) {
            return '<p><strong>Task Management MVP is already installed on this device.</strong></p>';
        }

        const ios = /iPhone|iPad|iPod/i.test(navigator.userAgent)
            || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
        if (ios) {
            return '<p><strong>On iPhone or iPad:</strong></p><ol><li>Open this page in Safari.</li><li>Open the Share controls.</li><li>Choose <strong>Add to Home Screen</strong>, then confirm <strong>Add</strong>.</li></ol>';
        }

        if (!isMobileDevice()) {
            return '<p>If this desktop browser supports installation, use its address-bar installation control or browser menu and follow the confirmation steps.</p>';
        }

        return '<p><strong>Install from your mobile browser:</strong></p><ol><li>Open the browser menu.</li><li>Choose <strong>Install app</strong> or <strong>Add to Home screen</strong>.</li><li>Confirm the installation.</li></ol><p>Menu wording varies by browser.</p>';
    }

    function renderInstallDialog() {
        const guide = document.querySelector('[data-pwa-guide]');
        const installButton = document.querySelector('[data-pwa-install]');
        if (guide) guide.innerHTML = installationGuide();
        if (installButton) {
            installButton.hidden = isStandalone() || Boolean(storage.getItem(installedAtKey));
            installButton.textContent = installPrompt ? 'Install App' : 'View Installation Steps';
        }
    }

    function openInstallDialog({ automatic = false } = {}) {
        const dialog = document.querySelector('[data-pwa-dialog]');
        if (!dialog) return;
        if (automatic && (!userId || !isMobileDevice() || isStandalone() || storage.getItem(installedAtKey) || dismissalIsCoolingDown())) return;
        if (automatic && document.querySelector('.swal2-container, dialog[open], .modal.active')) return;

        renderInstallDialog();
        document.querySelector('[data-pwa-guide]').hidden = Boolean(installPrompt) && !isStandalone();
        dialog.hidden = false;
        installReturnFocus = document.activeElement;
        document.querySelector('.app-shell').inert = true;
        document.body.classList.add('pwa-install-dialog-open');
        dialog.querySelector('[data-pwa-install]:not([hidden]), [data-pwa-dismiss]')?.focus();
    }

    function closeInstallDialog({ remember = false } = {}) {
        document.querySelector('[data-pwa-dialog]')?.setAttribute('hidden', '');
        document.body.classList.remove('pwa-install-dialog-open');
        const shell = document.querySelector('.app-shell');
        if (shell) shell.inert = false;
        installReturnFocus?.focus();
        if (remember) storage.setItem(installDismissedAtKey, String(Date.now()));
    }

    function scheduleAutomaticInstallOffer() {
        clearTimeout(automaticOfferTimer);
        automaticOfferTimer = setTimeout(() => openInstallDialog({ automatic: true }), 1500);
    }

    function bindInstallExperience() {
        document.addEventListener('keydown', event => {
            const dialog = document.querySelector('[data-pwa-dialog]:not([hidden])');
            if (!dialog) return;
            if (event.key === 'Escape') { event.preventDefault(); closeInstallDialog({ remember: true }); }
            if (event.key !== 'Tab') return;
            const controls = Array.from(dialog.querySelectorAll('button:not(:disabled), a[href]')).filter(control => control.getClientRects().length);
            const first = controls[0], last = controls.at(-1);
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
        });
        if (isStandalone()) storage.setItem(installedAtKey, String(Date.now()));
        document.querySelectorAll('[data-pwa-open]').forEach(button => {
            button.addEventListener('click', () => openInstallDialog());
        });
        document.querySelectorAll('[data-pwa-install]').forEach(button => {
            button.addEventListener('click', async () => {
                if (!installPrompt) {
                    document.querySelector('[data-pwa-guide]').hidden = false;
                    return;
                }

                const prompt = installPrompt;
                installPrompt = null;
                await prompt.prompt();
                const choice = await prompt.userChoice;
                if (choice.outcome === 'accepted') closeInstallDialog();
                else renderInstallDialog();
            });
        });
        document.querySelectorAll('[data-pwa-help]').forEach(button => {
            button.addEventListener('click', () => {
                document.querySelector('[data-pwa-guide]').hidden = false;
            });
        });
        document.querySelectorAll('[data-pwa-dismiss]').forEach(button => {
            button.addEventListener('click', () => closeInstallDialog({ remember: true }));
        });

        if (userId && isMobileDevice() && !isStandalone()) scheduleAutomaticInstallOffer();
    }

    window.addEventListener('beforeinstallprompt', event => {
        event.preventDefault();
        installPrompt = event;
        if (document.readyState !== 'loading') scheduleAutomaticInstallOffer();
    });

    window.addEventListener('appinstalled', () => {
        storage.removeItem(installDismissedAtKey);
        storage.setItem(installedAtKey, String(Date.now()));
        installPrompt = null;
        closeInstallDialog();
    });

    document.addEventListener('DOMContentLoaded', async () => {
        // Offline/update capability is independent of optional push support.
        if ('serviceWorker' in navigator && secureContext) {
            navigator.serviceWorker.addEventListener('message', event => {
                if (event.data?.type === 'REVALIDATE') window.AppClient?.check();
                if (event.data?.type === 'APP_UPDATED' && initialController) window.AppClient?.updateAvailable();
            });
            navigator.serviceWorker.addEventListener('controllerchange', () => {
                if (hadController) window.AppClient?.updateAvailable();
                hadController = true;
            });
            registration = await navigator.serviceWorker.register(serviceWorkerUrl.pathname, { scope: serviceWorkerScope, updateViaCache: 'none' }).catch(() => null);
            registration?.update().catch(() => {});
        }
        document.querySelectorAll('[data-push-enable]').forEach(button => button.addEventListener('click', () => enable().catch(error => showMessage(error.message, 'error'))));
        document.querySelectorAll('[data-push-disable]').forEach(button => button.addEventListener('click', () => disable().catch(error => showMessage(error.message, 'error'))));
        document.querySelectorAll('[data-push-test]').forEach(button => button.addEventListener('click', () => testNotification().catch(error => showMessage(error.message, 'error'))));
        bindInstallExperience();

        if (supported && userId) {
            const previousUser = storage.getItem('task-management.push-user');
            if (previousUser && previousUser !== userId) {
                await disable({ quiet: true }).catch(() => {});
            }
        }

        document.querySelectorAll('form[action$="/logout"]').forEach(form => {
            form.addEventListener('submit', async event => {
                if (form.dataset.pushCleanupDone === 'true') return;
                event.preventDefault();
                // Optional browser push APIs must never prevent the authenticated logout request.
                let timeout;
                try {
                    await Promise.race([disable({ quiet: true }).catch(() => {}), new Promise(resolve => {
                        timeout = setTimeout(resolve, 6000);
                    })]);
                } finally { clearTimeout(timeout); }
                form.dataset.pushCleanupDone = 'true';
                form.submit();
            });
        });

        await refresh().catch(() => {
            if (userId) render('stale');
        });
    });
})();
