export function initTimelineActions(showError) {
    document.querySelectorAll('.timeline-btn').forEach(button => {
        button.addEventListener('click', async event => {
            event.preventDefault();
            event.stopPropagation();
            button.disabled = true;
            try {
                const response = await fetch(button.dataset.url, {
                    headers: { 'Accept': 'application/json' },
                });
                const data = await response.json();
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'The timeline could not be loaded.');
                }
                await window.Phase3UI.showTimeline(data.entries, { url: button.dataset.url, ...data });
            } catch (error) {
                showError(error.message || 'The timeline could not be loaded.');
            } finally {
                button.disabled = false;
            }
        });
    });
}
