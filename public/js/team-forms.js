/* Shared JSON request lifecycle for account create/edit. Authorization stays on the server. */
window.TeamForms = (() => {
    function clearErrors(form) {
        form.querySelectorAll('[data-error-field][aria-describedby]').forEach(input => {
            const references = input.getAttribute('aria-describedby').split(' ').filter(id => !id.startsWith('account-error-'));
            if (references.length) input.setAttribute('aria-describedby', references.join(' '));
            else input.removeAttribute('aria-describedby');
        });
        form.querySelectorAll('[data-form-error]').forEach(node => node.remove());
        form.querySelectorAll('[aria-invalid]').forEach(node => node.removeAttribute('aria-invalid'));
    }

    function showErrors(form, message, errors = {}) {
        const summary = document.createElement('p');
        summary.dataset.formError = 'summary';
        summary.setAttribute('role', 'alert');
        summary.textContent = message;
        form.prepend(summary);
        Object.entries(errors).forEach(([key, messages]) => {
            const input = Array.from(form.querySelectorAll('[data-error-field]')).find(node => node.dataset.errorField === key);
            if (!input || !Array.isArray(messages)) return;
            const error = document.createElement('p');
            error.dataset.formError = key;
            error.id = `account-error-${form.id}-${key}`;
            error.textContent = messages.filter(value => typeof value === 'string').join(' ');
            input.setAttribute('aria-invalid', 'true');
            input.setAttribute('aria-describedby', [input.getAttribute('aria-describedby'), error.id].filter(Boolean).join(' '));
            input.insertAdjacentElement('afterend', error);
        });
        form.querySelector('[aria-invalid="true"]')?.focus();
    }

    const statusMessages = {
        401: 'Your session has expired. Sign in again before saving.',
        403: 'You do not have permission to save this account.',
        404: 'This account is no longer available. Refresh the team list.',
        409: 'The account cannot be changed in its current state. Review its responsibilities and try again.',
        419: 'Your session has expired. Refresh this page before saving.',
        429: 'Too many requests. Wait a moment and try again.',
    };

    async function submit(form, { url, method, payload, confirm }) {
        if (form.dataset.pending === 'true') return;
        form.dataset.pending = 'true';
        form.setAttribute('aria-busy', 'true');
        const button = form.querySelector('button[type="submit"]');
        const previousText = button.textContent;
        const previousDisabled = button.disabled;
        const cancelButtons = Array.from(form.querySelectorAll('button[type="button"]'));
        cancelButtons.forEach(cancel => { cancel.disabled = true; });
        button.disabled = true;
        clearErrors(form);
        try {
            if (confirm && !await confirm()) return;
            button.textContent = 'Saving…';
            const response = await fetch(url, {
                method,
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify(payload),
            });
            if (response.redirected || (response.status >= 300 && response.status < 400)) {
                showErrors(form, 'Your session or page has changed. Refresh before saving.');
                return;
            }
            const json = response.headers.get('Content-Type')?.includes('application/json');
            const data = json ? await response.json().catch(() => null) : null;
            if (response.status === 422 && data?.errors && typeof data.errors === 'object') {
                showErrors(form, 'Please correct the highlighted fields.', data.errors);
                return;
            }
            if (!response.ok) {
                if (response.status === 409 && data?.code === 'assignment_continuity') {
                    showErrors(form, 'This employee has unfinished task assignments that would become inaccessible after this role change. Reassign or complete them first.');
                    return;
                }
                showErrors(form, statusMessages[response.status] || 'The account could not be saved. Try again shortly.');
                return;
            }
            if (!data || !Number.isInteger(data.id) || data.id < 1) {
                showErrors(form, 'The server returned an unexpected response. Refresh the team list before retrying.');
                return;
            }
            form.closest('.modal').classList.remove('active');
            window.location.reload();
        } catch {
            showErrors(form, 'Connection failed. Check your connection, then refresh the team list before retrying.');
        } finally {
            form.dataset.pending = 'false';
            form.removeAttribute('aria-busy');
            button.textContent = previousText;
            button.disabled = previousDisabled;
            cancelButtons.forEach(cancel => { cancel.disabled = false; });
        }
    }
    return { submit, clearErrors };
})();
