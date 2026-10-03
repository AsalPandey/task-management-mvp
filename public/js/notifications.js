(() => {
    'use strict';
    const pending = new Set();
    async function mark(id = null) {
        const key = id || 'all';
        if (pending.has(key) || pending.has('all')) return;
        pending.add(key);
        const selector = id ? `[data-id="${CSS.escape(id)}"] .mark-read-btn, [data-id="${CSS.escape(id)}"] .mark-read-page-btn` : '.mark-all-read-btn, #markAllReadBtn';
        const buttons = Array.from(document.querySelectorAll(selector));
        buttons.forEach(button => { button.disabled = true; button.setAttribute('aria-busy', 'true'); });
        try {
            const response = await fetch(window.AppClient.appUrl(id ? `notifications/read/${encodeURIComponent(id)}` : 'notifications/read-all'), {
                method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.success) throw new Error('Notifications could not be marked as read. Check your connection and try again.');
            const items = id ? document.querySelectorAll(`[data-id="${CSS.escape(id)}"]`) : document.querySelectorAll('.notification-item.unread, .notification-page-item.unread');
            const wasUnread = Array.from(items).some(item => item.classList.contains('unread'));
            items.forEach(item => { item.classList.remove('unread'); item.classList.add('read'); item.querySelectorAll('.mark-read-btn, .mark-read-page-btn').forEach(button => button.remove()); });
            document.querySelectorAll('[data-unread-count]').forEach(badge => {
                const count = id ? Math.max(0, Number(badge.dataset.unreadCount) - Number(wasUnread)) : 0;
                badge.dataset.unreadCount = String(count); badge.textContent = count > 99 ? '99+' : String(count);
                badge.setAttribute('aria-label', `${count} unread notifications`);
                if (!count) badge.remove();
            });
            if (!id) document.querySelectorAll('.mark-all-read-btn, #markAllReadBtn').forEach(button => button.remove());
        } catch (error) {
            window.AppClient.error(error.message || 'Notifications could not be marked as read. Try again.');
        } finally {
            pending.delete(key);
            buttons.forEach(button => { button.disabled = false; button.removeAttribute('aria-busy'); });
        }
    }
    window.markAsRead = id => mark(id);
    window.markAllAsRead = () => mark();
    document.addEventListener('DOMContentLoaded', () => document.getElementById('markAllReadBtn')?.addEventListener('click', () => mark()));
})();
