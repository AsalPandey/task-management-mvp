/* Shared freshness contract: metadata invalidation + scoped, visible-only revalidation. */
(() => {
    'use strict';
    const manifest = new URL(document.querySelector('link[rel="manifest"]').href);
    const base = new URL('.', manifest);
    const appUrl = path => new URL(String(path).replace(/^\/+/, ''), base).href;
    const user = document.body.dataset.userId;
    const notice = document.querySelector('[data-client-notice]');
    const text = document.querySelector('[data-client-message]');
    const originalFetch = window.fetch.bind(window);
    const key = `task-management.invalidate:${base.pathname}`;
    let version = document.body.dataset.clientVersion;
    let lastCheck = 0;
    let checking = false;
    let changed = false;
    let timer;
    let channel;
    let update = false;
    let accountChanged = false;
    let invalidationTimer;
    let revalidationTimer;
    const initialValues = new WeakMap();
    const valueOf = input => ['checkbox', 'radio'].includes(input.type) ? input.checked : input.value;
    function rememberInputs(root = document) {
        root.querySelectorAll('input, textarea, select').forEach(input => initialValues.set(input, valueOf(input)));
    }
    document.addEventListener('DOMContentLoaded', () => queueMicrotask(() => rememberInputs()));
    const source = `${Date.now()}-${Math.random()}`;
    try { if ('BroadcastChannel' in window) channel = new BroadcastChannel(key); } catch { /* storage/polling fallback */ }

    function show(message, stale = false) {
        if (!notice) return;
        notice.hidden = false;
        text.textContent = message;
        if (stale) {
            changed = true;
            document.querySelectorAll('.execution-transition-btn, .management-action-btn, .progress-update-btn, .member-remove-btn, .activate-btn, .deactivate-btn, .delete-btn, .project-delete-btn').forEach(button => {
                button.disabled = true;
                button.title = 'Reload latest data before using this action.';
            });
        }
        const modal = document.querySelector('.modal.active');
        if (modal) {
            let warning = modal.querySelector('[data-client-draft-notice]');
            if (!warning) {
                warning = document.createElement('p'); warning.dataset.clientDraftNotice = ''; warning.setAttribute('role', 'status');
                modal.querySelector('form, .modal-content')?.prepend(warning);
            }
            warning.textContent = stale ? 'Newer data is available. Your input is preserved. Close this dialog to reload, or keep editing; conflicting task changes will not be saved.' : message;
        }
    }
    function receive(event) {
        const data = event.data;
        if (!data || data.type !== 'INVALIDATE' || data.source === source || data.user !== user) return;
        // Verify against the current authenticated session before displaying any activity signal.
        clearTimeout(invalidationTimer);
        invalidationTimer = setTimeout(() => check(true), Math.max(200, 1000 - (Date.now() - lastCheck)));
    }
    if (channel) channel.onmessage = receive;
    window.addEventListener('storage', event => {
        if (event.key !== key || !event.newValue) return;
        try { receive({ data: JSON.parse(event.newValue) }); } catch { /* unrelated/malformed storage */ }
    });
    function publish() {
        const data = { type: 'INVALIDATE', user, source, at: Date.now() };
        try { channel?.postMessage(data); } catch { /* server fallback */ }
        try { localStorage.setItem(key, JSON.stringify(data)); } catch { /* storage can be disabled */ }
    }
    async function check(force = false) {
        if (!user || checking || !navigator.onLine || document.hidden || (!force && Date.now() - lastCheck < 5000)) return;
        lastCheck = Date.now(); checking = true;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 8000);
        try {
            const response = await originalFetch(appUrl('client/freshness'), { headers: { Accept: 'application/json' }, cache: 'no-store', credentials: 'same-origin', signal: controller.signal });
            if ([401, 403, 419].includes(response.status)) {
                protectAccount();
                show('Your session has changed. Sign in again to load your current workspace.', true);
                document.querySelectorAll('main button[type="submit"]').forEach(button => { button.disabled = true; });
                return;
            }
            if (!response.ok) throw new Error('check');
            const data = await response.json();
            if (data.user !== user) {
                protectAccount();
                show('Your session has changed. Reload to load the current account.', true);
                document.querySelectorAll('main button[type="submit"]').forEach(button => { button.disabled = true; });
            } else if (data.version !== version) {
                show('Newer data is available. Reload latest to update this page. Your input has been preserved.', true);
            } else if (!changed && !update) {
                notice.hidden = true;
                document.querySelectorAll('[data-client-draft-notice]').forEach(warning => warning.remove());
            }
        } catch {
            if (!changed && !update) show('Unable to check for newer data. Check your connection or reload to try again.');
        } finally { clearTimeout(timeout); checking = false; }
    }
    function protectAccount() {
        accountChanged = true;
        const main = document.querySelector('main');
        if (main) {
            const heading = document.createElement('h1'); heading.textContent = 'Your session has changed';
            const link = document.createElement('a'); link.href = appUrl('login'); link.textContent = 'Sign in to your current workspace';
            main.replaceChildren(heading, link);
        }
        document.querySelectorAll('.header, .mobile-bottom-nav').forEach(element => element.remove());
    }
    function schedule() {
        clearInterval(timer);
        if (!document.hidden) timer = setInterval(check, 60_000);
    }
    function revalidateOnReturn() {
        clearTimeout(revalidationTimer);
        revalidationTimer = setTimeout(() => check(), Math.max(0, 5000 - (Date.now() - lastCheck)));
    }
    window.fetch = async (input, options = {}) => {
        const response = await originalFetch(input, options);
        const url = new URL(input instanceof Request ? input.url : input, location.href);
        const method = String(options.method || (input instanceof Request ? input.method : 'GET')).toUpperCase();
        if (user && url.origin === location.origin && url.pathname.startsWith(base.pathname)
            && !['GET', 'HEAD', 'OPTIONS'].includes(method) && response.ok && !response.redirected
            && /\/(tasks|projects|team-management|notifications|settings)(\/|$)/.test(url.pathname)) {
            const data = await response.clone().json().catch(() => null);
            if (data && data.success !== false) publish();
        }
        return response;
    };
    function dirty() {
        if (document.querySelector('.modal.active, .swal2-container')) return true;
        return Array.from(document.querySelectorAll('main input, main textarea, main select')).some(input => {
            if (input.type === 'hidden' || input.disabled || !input.getClientRects().length) return false;
            return initialValues.has(input) && valueOf(input) !== initialValues.get(input);
        });
    }
    async function reloadLatest() {
        if (dirty()) {
            const choice = await window.Swal.fire({ title: 'Reload latest?', text: 'Reloading will discard unsaved input on this page.', showCancelButton: true, confirmButtonText: 'Discard input and reload', cancelButtonText: 'Keep editing' });
            if (!choice.isConfirmed) return;
        }
        location.assign(accountChanged ? appUrl('login') : location.href);
    }
    window.AppClient = Object.freeze({ appUrl, check: () => check(true), hasUnsavedInput: dirty, markSaved: rememberInputs,
        updateAvailable: () => { update = true; show('An application update is available. Reload when you are ready. Your input has been preserved.'); },
        error: message => show(message), reloadLatest });
    notice?.querySelector('button')?.addEventListener('click', reloadLatest);
    window.addEventListener('focus', revalidateOnReturn);
    window.addEventListener('online', () => check(true));
    window.addEventListener('offline', () => show('You are offline. Changes cannot be saved. Reconnect, then reload latest data.'));
    window.addEventListener('pageshow', event => { if (event.persisted) check(true); });
    document.addEventListener('visibilitychange', () => { schedule(); if (!document.hidden) revalidateOnReturn(); });
    window.addEventListener('pagehide', () => { clearInterval(timer); channel?.close(); });
    window.addEventListener('pageshow', event => {
        if (event.persisted) {
            try { if ('BroadcastChannel' in window) { channel = new BroadcastChannel(key); channel.onmessage = receive; } } catch { /* polling */ }
            schedule();
        }
    });
    schedule(); check();
})();
