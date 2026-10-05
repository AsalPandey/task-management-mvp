import { requestTaskAction } from './action-request.js';

export function initLifecycleActions({ reviewerCandidates, showMessage, firstErrorMessage }) {
    async function postExecutionTransition(button, payload = {}) {
        button.disabled = true;

        try {
            const { response, data } = await requestTaskAction(button, payload);

            if (response.status === 409) {
                const choice = await Swal.fire({ icon: 'warning', title: 'Task changed',
                    text: 'This task changed after this page was loaded. Your action was not applied. Reload the latest task to review it.',
                    showCancelButton: true, confirmButtonText: 'Reload latest task', cancelButtonText: 'Keep this page' });
                if (choice.isConfirmed) window.location.reload();
                return;
            }
            if (!response.ok || !data.success) {
                throw new Error(firstErrorMessage(data, 'The task state could not be changed.'));
            }

            await Swal.fire({
                icon: 'success',
                title: 'Success',
                text: data.message || 'Task state updated.',
            });
            window.location.reload();
        } catch (error) {
            showMessage(error.message || 'The task state could not be changed.', false);
            button.disabled = false;
        } finally {
            button.disabled = false;
        }
    }

    document.querySelectorAll('.execution-transition-btn').forEach(button => {
        button.addEventListener('click', async function(e) {
            e.preventDefault();
            e.stopPropagation();

            if (this.dataset.transition === 'hold') {
                const prompt = await Swal.fire({
                    title: 'Put task on hold',
                    text: 'Explain why work is being paused.',
                    input: 'textarea',
                    inputLabel: 'Hold reason',
                    inputPlaceholder: 'Enter a reason',
                    inputAttributes: { maxlength: '1000' },
                    showCancelButton: true,
                    confirmButtonText: 'Put On Hold',
                    inputValidator: value => value.trim() ? undefined : 'A hold reason is required.',
                });

                if (!prompt.isConfirmed) {
                    return;
                }

                await postExecutionTransition(this, { reason: prompt.value.trim() });

                return;
            }

            if (this.dataset.transition === 'submit') {
                const prompt = await Swal.fire({
                    title: 'Submit for review',
                    input: 'textarea',
                    inputLabel: 'Submission note (optional)',
                    inputAttributes: { maxlength: '2000' },
                    showCancelButton: true,
                    confirmButtonText: 'Submit',
                });

                if (prompt.isConfirmed) {
                    await postExecutionTransition(this, {
                        submission_note: prompt.value?.trim() || null,
                    });
                }

                return;
            }

            if (this.dataset.transition === 'revision-request') {
                const feedback = await Swal.fire({
                    title: 'Request revision',
                    input: 'textarea',
                    inputLabel: 'Formal feedback',
                    inputAttributes: { maxlength: '5000' },
                    showCancelButton: true,
                    confirmButtonText: 'Continue',
                    inputValidator: value => value.trim() ? undefined : 'Formal feedback is required.',
                });
                if (!feedback.isConfirmed) return;

                const deadline = await Swal.fire({
                    title: 'Revision deadline',
                    input: 'date',
                    inputLabel: 'Choose a future date',
                    showCancelButton: true,
                    confirmButtonText: 'Request Revision',
                    inputValidator: value => value ? undefined : 'A revision deadline is required.',
                });
                if (!deadline.isConfirmed) return;

                await postExecutionTransition(this, {
                    formal_feedback: feedback.value.trim(),
                    revision_due_date: deadline.value,
                });

                return;
            }

            if (this.dataset.transition === 'resubmit') {
                const prompt = await Swal.fire({
                    title: 'Resubmit revision',
                    input: 'textarea',
                    inputLabel: 'Submission note (optional)',
                    inputAttributes: { maxlength: '2000' },
                    showCancelButton: true,
                    confirmButtonText: 'Resubmit',
                });

                if (prompt.isConfirmed) {
                    await postExecutionTransition(this, {
                        submission_note: prompt.value?.trim() || null,
                    });
                }

                return;
            }

            if (this.dataset.transition === 'approve') {
                const prompt = await Swal.fire({
                    title: 'Approve and complete task?',
                    input: 'textarea',
                    inputLabel: 'Approval comment (optional)',
                    inputAttributes: { maxlength: '2000' },
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Approve and Complete',
                });

                if (prompt.isConfirmed) {
                    await postExecutionTransition(this, {
                        approval_comment: prompt.value?.trim() || null,
                    });
                }

                return;
            }

            if (this.dataset.transition === 'override-approve') {
                const reason = await Swal.fire({
                    title: 'Emergency Override Approval',
                    input: 'textarea',
                    inputLabel: 'Management-only override reason',
                    inputAttributes: { maxlength: '5000' },
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Continue',
                    inputValidator: value => value.trim() ? undefined : 'An override reason is required.',
                });
                if (!reason.isConfirmed) return;

                const comment = await Swal.fire({
                    title: 'Approval comment',
                    input: 'textarea',
                    inputLabel: 'Optional user-visible approval comment',
                    inputAttributes: { maxlength: '2000' },
                    showCancelButton: true,
                    confirmButtonText: 'Approve and Complete',
                });
                if (!comment.isConfirmed) return;

                await postExecutionTransition(this, {
                    override_reason: reason.value.trim(),
                    approval_comment: comment.value?.trim() || null,
                });

                return;
            }

            if (this.dataset.transition === 'cancel') {
                const prompt = await Swal.fire({
                    title: 'Cancel this task?',
                    input: 'textarea',
                    inputLabel: 'Cancellation reason',
                    inputAttributes: { maxlength: '5000' },
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Cancel Task',
                    confirmButtonColor: '#dc2626',
                    inputValidator: value => value.trim() ? undefined : 'A cancellation reason is required.',
                });

                if (prompt.isConfirmed) {
                    await postExecutionTransition(this, {
                        cancellation_reason: prompt.value.trim(),
                    });
                }

                return;
            }

            const labels = {
                start: {
                    title: 'Start work?',
                    text: 'This task will move to In Progress.',
                    confirm: 'Start Work',
                },
                resume: {
                    title: 'Resume work?',
                    text: 'This task will return to In Progress.',
                    confirm: 'Resume',
                },
                review: {
                    title: 'Start review?',
                    text: 'This task will move to In Review.',
                    confirm: 'Start Review',
                },
                'revision-start': {
                    title: 'Begin revision?',
                    text: 'The task will return to In Progress for revision work.',
                    confirm: 'Begin Revision',
                },
            };
            const copy = labels[this.dataset.transition];
            const confirmation = await Swal.fire({
                title: copy.title,
                text: copy.text,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: copy.confirm,
            });

            if (confirmation.isConfirmed) {
                await postExecutionTransition(this);
            }
        });
    });

    document.querySelectorAll('.management-action-btn').forEach(button => {
        button.addEventListener('click', async function(e) {
            e.preventDefault();
            e.stopPropagation();

            if (this.dataset.action === 'reassign-reviewer') {
                const options = {};
                const projectId = this.closest('.task-card')?.dataset.projectId;
                const rosterUrl = window.AppClient.appUrl(`/projects/${projectId}/candidates?kind=reviewer`);
                let candidates;
                this.disabled = true;
                try {
                    const response = await fetch(rosterUrl, { headers: { Accept: 'application/json' } });
                    const data = await response.json();
                    if (!response.ok) throw new Error(data.message || 'Reviewers could not be loaded.');
                    candidates = data.candidates;
                } catch (error) {
                    showMessage(error.message, false);
                    return;
                } finally { this.disabled = false; }
                candidates.forEach(candidate => {
                    options[candidate.id] = candidate.name;
                });
                const reviewer = await Swal.fire({
                    title: 'Reassign reviewer',
                    input: 'select',
                    inputOptions: options,
                    inputPlaceholder: 'Select reviewer',
                    showCancelButton: true,
                    confirmButtonText: 'Continue',
                    inputValidator: value => value ? undefined : 'A reviewer is required.',
                    didOpen: () => {
                        const search = document.createElement('input');
                        search.type = 'search'; search.className = 'swal2-input';
                        search.setAttribute('aria-label', 'Search replacement reviewer');
                        search.placeholder = 'Search reviewer by name';
                        const select = Swal.getInput();
                        select.before(search);
                        let timer; let sequence = 0;
                        search.addEventListener('input', () => {
                            clearTimeout(timer);
                            timer = setTimeout(async () => {
                                const current = ++sequence;
                                try {
                                    const response = await fetch(`${rosterUrl}&search=${encodeURIComponent(search.value)}`, {headers: {Accept: 'application/json'}});
                                    const data = await response.json();
                                    if (!response.ok) throw new Error(data.message || 'Reviewers could not be loaded.');
                                    if (current !== sequence || !select.isConnected) return;
                                    select.replaceChildren(new Option('Select reviewer', ''));
                                    data.candidates.forEach(user => select.appendChild(new Option(user.name, user.id)));
                                } catch(error) { Swal.showValidationMessage(error.message); }
                            }, 250);
                        });
                    },
                });
                if (!reviewer.isConfirmed) return;

                const reason = await Swal.fire({
                    title: 'Reason for reassignment',
                    input: 'textarea',
                    inputLabel: this.dataset.reasonRequired === 'true'
                        ? 'Required after submission'
                        : 'Optional before submission',
                    inputAttributes: { maxlength: '1000' },
                    showCancelButton: true,
                    confirmButtonText: 'Reassign Reviewer',
                    inputValidator: value => this.dataset.reasonRequired === 'true' && !value.trim()
                        ? 'A reason is required after submission.'
                        : undefined,
                });
                if (!reason.isConfirmed) return;

                await postExecutionTransition(this, {
                    reviewer_id: reviewer.value,
                    reason: reason.value?.trim() || null,
                });

                return;
            }

            if (this.dataset.action === 'change-deadline') {
                const deadline = await Swal.fire({
                    title: `Change ${this.dataset.deadlineType} deadline`,
                    input: 'date',
                    inputLabel: 'Choose a future date',
                    showCancelButton: true,
                    confirmButtonText: 'Continue',
                    inputValidator: value => value ? undefined : 'A deadline is required.',
                });
                if (!deadline.isConfirmed) return;

                const reason = await Swal.fire({
                    title: 'Reason for deadline change',
                    input: 'textarea',
                    inputLabel: this.dataset.reasonRequired === 'true'
                        ? 'Required for the active workflow period'
                        : 'Optional before work begins',
                    inputAttributes: { maxlength: '1000' },
                    showCancelButton: true,
                    confirmButtonText: 'Change Deadline',
                    inputValidator: value => this.dataset.reasonRequired === 'true' && !value.trim()
                        ? 'A reason is required.'
                        : undefined,
                });
                if (!reason.isConfirmed) return;

                await postExecutionTransition(this, {
                    due_date: deadline.value,
                    reason: reason.value?.trim() || null,
                });
            }
        });
    });

}
