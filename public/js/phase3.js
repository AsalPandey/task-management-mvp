(() => {
    'use strict';

    const mobileQuery = window.matchMedia('(max-width: 768px)');

    const createElement = (tag, className, text) => {
        const element = document.createElement(tag);
        if (className) element.className = className;
        if (text !== undefined && text !== null) element.textContent = text;

        return element;
    };

    const timelineIcon = (label) => {
        const normalized = String(label || '').toLowerCase();

        if (normalized.includes('approved') || normalized.includes('completed')) return '✓';
        if (normalized.includes('revision')) return '↻';
        if (normalized.includes('hold')) return 'Ⅱ';
        if (normalized.includes('deadline')) return '◷';
        if (normalized.includes('cancel')) return '×';
        if (normalized.includes('review') || normalized.includes('submitted')) return '◉';

        return '•';
    };

    const showTimeline = async (entries) => {
        const timeline = createElement('ol', 'phase3-timeline');

        if (!Array.isArray(entries) || entries.length === 0) {
            timeline.append(createElement('li', 'phase3-timeline-empty', 'No timeline entries are available.'));
        } else {
            entries.forEach((entry) => {
                const item = createElement('li', 'phase3-timeline-item');
                const icon = createElement('span', 'phase3-timeline-icon', timelineIcon(entry.label));
                icon.setAttribute('aria-hidden', 'true');

                const content = createElement('div', 'phase3-timeline-content');
                content.append(createElement('strong', 'phase3-timeline-action', entry.label || 'Task activity'));

                const meta = createElement('div', 'phase3-timeline-meta');
                meta.append(createElement('span', 'phase3-timeline-actor', entry.actor || 'System'));
                if (entry.occurred_at) {
                    const time = createElement(
                        'time',
                        'phase3-timeline-time',
                        new Date(entry.occurred_at).toLocaleString()
                    );
                    time.dateTime = entry.occurred_at;
                    meta.append(time);
                }
                content.append(meta);

                if (Array.isArray(entry.details) && entry.details.length) {
                    content.append(createElement('p', 'phase3-timeline-note', entry.details.join(' · ')));
                }

                item.append(icon, content);
                timeline.append(item);
            });
        }

        return window.Swal.fire({
            title: 'Task timeline',
            html: timeline,
            width: 680,
            confirmButtonText: 'Close',
            customClass: {
                popup: 'phase3-timeline-dialog',
                htmlContainer: 'phase3-timeline-scroll',
            },
        });
    };

    const enhanceTables = () => {
        document.querySelectorAll('table:not([data-no-responsive])').forEach((table) => {
            const headings = Array.from(table.querySelectorAll('thead th')).map((heading) =>
                heading.textContent.replace(/\s+/g, ' ').trim()
            );

            if (!headings.length) return;

            table.classList.add('phase3-responsive-table');
            table.querySelectorAll('tbody tr').forEach((row) => {
                Array.from(row.children).forEach((cell, index) => {
                    if (!cell.dataset.label) {
                        cell.dataset.label = headings[index] || '';
                    }
                });
            });
        });
    };

    const enhanceFilters = () => {
        const filterPanels = document.querySelectorAll('.filters-card, .analytics-filters');
        const hasQuery = Array.from(new URLSearchParams(window.location.search).keys())
            .some((key) => key !== 'page');

        filterPanels.forEach((panel, index) => {
            if (panel.dataset.phase3Filter === 'true') return;

            panel.dataset.phase3Filter = 'true';
            panel.id ||= `phase3-filters-${index + 1}`;

            const button = createElement('button', 'phase3-filter-toggle');
            button.type = 'button';
            button.setAttribute('aria-controls', panel.id);

            const update = (open) => {
                panel.dataset.collapsed = String(!open);
                button.setAttribute('aria-expanded', String(open));
                button.textContent = open ? 'Hide filters' : 'Filters and search';
            };

            update(!mobileQuery.matches || hasQuery);
            button.addEventListener('click', () => update(button.getAttribute('aria-expanded') !== 'true'));
            panel.before(button);

            mobileQuery.addEventListener('change', (event) => update(!event.matches || hasQuery));
        });
    };

    const enhanceTaskCards = () => {
        document.querySelectorAll('.task-card').forEach((card) => {
            const collapsible = card.querySelectorAll('.task-comments');
            if (!collapsible.length || card.querySelector('.task-card-expand')) return;

            card.classList.add('has-collapsible-details');
            const button = createElement('button', 'task-card-expand', 'Show notes');
            button.type = 'button';
            button.setAttribute('aria-expanded', 'false');
            button.addEventListener('click', (event) => {
                event.stopPropagation();
                const expanded = card.classList.toggle('is-expanded');
                button.setAttribute('aria-expanded', String(expanded));
                button.textContent = expanded ? 'Hide notes' : 'Show notes';
            });

            collapsible[0].before(button);
        });
    };

    const enhanceModals = () => {
        const modalReturnFocus = new WeakMap();

        document.querySelectorAll('.modal').forEach((modal, index) => {
            modal.setAttribute('role', 'dialog');
            modal.setAttribute('aria-modal', 'true');
            const heading = modal.querySelector('.modal-header h3, .modal-header h2');
            if (heading) {
                heading.id ||= `phase3-modal-title-${index + 1}`;
                modal.setAttribute('aria-labelledby', heading.id);
            }

            const close = modal.querySelector('.modal-close');
            if (close && !close.getAttribute('aria-label')) close.setAttribute('aria-label', 'Close dialog');

            const observer = new MutationObserver(() => {
                if (modal.classList.contains('active')) {
                    modalReturnFocus.set(modal, document.activeElement);
                    requestAnimationFrame(() => {
                        const initialFocus = modal.querySelector(
                            'input:not([type="hidden"]):not(:disabled), select:not(:disabled), textarea:not(:disabled)'
                        ) || modal.querySelector('button:not(:disabled)');
                        initialFocus?.focus();
                    });
                } else {
                    const trigger = modalReturnFocus.get(modal);
                    if (trigger instanceof HTMLElement && trigger.isConnected) trigger.focus();
                }
            });
            observer.observe(modal, { attributes: true, attributeFilter: ['class'] });
        });

        document.addEventListener('keydown', (event) => {
            const activeModal = document.querySelector('.modal.active');
            if (!activeModal) return;

            if (event.key === 'Escape') {
                activeModal.querySelector('.modal-close')?.click();

                return;
            }

            if (event.key !== 'Tab') return;
            const focusable = Array.from(activeModal.querySelectorAll(
                'a[href], button:not(:disabled), input:not([type="hidden"]):not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"])'
            )).filter((element) => element.getClientRects().length > 0);
            if (!focusable.length) return;

            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });
    };

    const enhanceFeedback = () => {
        document.querySelectorAll('.message-container').forEach((container) => {
            container.setAttribute('role', 'status');
            container.setAttribute('aria-live', 'polite');
            container.setAttribute('aria-atomic', 'true');
        });
    };

    const handleDeepLinks = () => {
        if (window.location.hash === '#searchTasks') {
            requestAnimationFrame(() => document.getElementById('searchTasks')?.focus());
        }

        if (window.location.hash === '#profile') {
            document.querySelector('.nav-item[data-tab="profile"]')?.click();
        }

        if (window.location.hash.startsWith('#task-')) {
            const card = document.getElementById(window.location.hash.slice(1));
            if (card) {
                card.classList.add('phase3-target');
                card.scrollIntoView({ block: 'center' });
                card.focus({ preventScroll: true });
            }
        }
    };

    window.Phase3UI = Object.freeze({ showTimeline });

    document.addEventListener('DOMContentLoaded', () => {
        document.documentElement.classList.add('phase3-ready');
        enhanceTables();
        enhanceFilters();
        enhanceTaskCards();
        enhanceModals();
        enhanceFeedback();
        handleDeepLinks();
        window.addEventListener('hashchange', handleDeepLinks);
    });
})();
