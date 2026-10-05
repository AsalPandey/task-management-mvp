// Feature serialization uses window.fetch so AppClient remains authoritative for
// account freshness and invalidation. Preserve the version of the rendered action.
export async function requestTaskAction(button, payload) {
    const response = await fetch(button.dataset.url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
            'Accept': 'application/json',
        },
        body: JSON.stringify({ ...payload, expected_version: Number(button.dataset.taskVersion) }),
    });
    const data = await response.json().catch(() => ({}));
    return { response, data };
}
