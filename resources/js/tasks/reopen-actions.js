import { requestTaskAction } from './action-request.js';

export function initReopenActions(reviewerCandidates) {
    document.querySelectorAll('.reopen-revision-btn').forEach(button => {
        button.addEventListener('click', async function() {
            const originalText = button.textContent;
            const reason = await Swal.fire({
                title: 'Reopen for revision?',
                input: 'textarea',
                inputLabel: 'Why does the approved result require more work?',
                inputAttributes: { maxlength: '5000' },
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Continue',
                inputValidator: value => value.trim() ? undefined : 'A reopen reason is required.',
            });
            if (!reason.isConfirmed) return;

            const instructions = await Swal.fire({
                title: 'Instructions for the assignee',
                input: 'textarea',
                inputLabel: 'These instructions are visible to the assignee. The management reason remains private.',
                inputAttributes: { maxlength: '5000' },
                showCancelButton: true,
                confirmButtonText: 'Continue',
            });
            if (!instructions.isConfirmed) return;

            const reviewerOptions = Object.fromEntries(reviewerCandidates.map(candidate => [
                candidate.id,
                `${candidate.name} (${candidate.role === 'project_manager' ? 'Project Manager' : 'Manager'})`,
            ]));
            const reviewer = await Swal.fire({
                title: 'Reviewer for future work',
                input: 'select',
                inputOptions: reviewerOptions,
                inputValue: button.dataset.reviewerId || '',
                inputPlaceholder: 'Select an eligible reviewer',
                showCancelButton: true,
                confirmButtonText: 'Continue',
                inputValidator: value => value ? undefined : 'An eligible reviewer is required.',
            });
            if (!reviewer.isConfirmed) return;

            const deadline = await Swal.fire({
                title: 'Set revision deadline',
                input: 'date',
                inputLabel: 'The deadline must be in the future.',
                showCancelButton: true,
                confirmButtonText: 'Reopen for Revision',
                inputValidator: value => value ? undefined : 'A revision deadline is required.',
            });
            if (!deadline.isConfirmed) return;

            button.disabled = true;
            button.textContent = 'Reopening...';

            try {
                const { response, data } = await requestTaskAction(button, {
                    reopen_reason: reason.value.trim(),
                    rework_instructions: instructions.value.trim() || null,
                    revision_due_date: deadline.value,
                    reviewer_id: Number(reviewer.value),
                });
                if (response.status === 409) {
                    const choice = await Swal.fire({ icon: 'warning', title: 'Task changed', text: 'The task changed. Your reopen action was not applied.', showCancelButton: true, confirmButtonText: 'Reload latest task', cancelButtonText: 'Keep this page' });
                    if (choice.isConfirmed) window.location.reload();
                    button.disabled = false;
                    button.textContent = originalText;
                    return;
                }
                if (!response.ok || !data.success) {
                    throw new Error(data.message || Object.values(data.errors || {}).flat()[0] || 'Failed to reopen the task.');
                }

                button.closest('tr')?.remove();
                Swal.fire('Reopened!', 'The task now requires revision.', 'success');
            } catch (error) {
                Swal.fire('Error', error.message || 'Failed to reopen the task.', 'error');
                button.disabled = false;
                button.textContent = originalText;
            }
        });
    });
}
