@extends('layouts.app')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
<link rel="stylesheet" href="{{ asset('css/tasks.css') }}">
<link rel="stylesheet" href="{{ asset('css/common.css') }}?v={{ filemtime(public_path('css/common.css')) }}">
<style>
body { background: #f7f8fa; }
.tasks-container { max-width: 1100px; margin: 0 auto; padding: 2rem 1rem; background: #fff; border-radius: 16px; box-shadow: 0 2px 16px 0 rgba(60,72,88,0.05); }
.tasks-header h1 { font-size: 1.7rem; font-weight: 700; color: #22223b; margin-bottom: 0.2rem; }
.filters-card { background: #f8fafc; border-radius: 12px; box-shadow: none; padding: 1.2rem 1rem; margin-bottom: 2rem; }
.filter-actions { display: flex; align-items: center; gap: 0.75rem; margin-top: 1rem; }
.filter-actions .btn-secondary { text-decoration: none; }
.filter-errors { color: #991b1b; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 1rem; }
.filter-errors ul { margin: 0.4rem 0 0 1.25rem; }
.task-results-summary { color: #4b5563; font-size: 0.95rem; margin: -1rem 0 1.25rem; }
.pagination-wrapper { margin-top: 1.5rem; }
.tasks-table { background: #f8fafc; border-radius: 12px; box-shadow: none; }
@media (max-width: 900px) { .tasks-table th, .tasks-table td { padding: 0.5rem 0.5rem; } }
@media (max-width: 600px) { .tasks-container { padding: 1rem 0.2rem; } }
</style>
@endpush
@push('scripts')
@vite('resources/js/task-features.js')
@php
    $projectMembersForScript = $projects->mapWithKeys(function ($project) {
        return [
            $project->id => $project->members->map(function ($member) {
                return [
                    'id' => $member->id,
                    'name' => $member->name,
                ];
            })->values(),
        ];
    });
    $projectMembershipForScript = $projects->mapWithKeys(fn ($project) => [
        $project->id => [
            'name' => $project->name,
            'project_manager_id' => $project->project_manager_id,
            'addMemberUrl' => route('projects.members.add', $project),
        ],
    ]);
@endphp
<script>
window.projectMembers = {{ Illuminate\Support\Js::from($projectMembersForScript) }};
window.assignmentCandidates = {{ Illuminate\Support\Js::from($assignmentCandidates) }};
window.reviewerCandidates = {{ Illuminate\Support\Js::from($reviewerCandidates) }};
window.projectMembership = {{ Illuminate\Support\Js::from($projectMembershipForScript) }};
window.taskStoreUrl = {{ Illuminate\Support\Js::from(route('tasks.store')) }};
window.taskUpdateUrlTemplate = {{ Illuminate\Support\Js::from(route('tasks.update', ['task' => '__TASK_ID__'])) }};

document.addEventListener('DOMContentLoaded', function() {
    for (const [id, kind] of [['assigneeFilter','assignee'], ['reviewerFilter','reviewer']]) {
        const select = document.getElementById(id);
        if (!select) continue;
        const search = document.createElement('input'); search.type = 'search';
        search.placeholder = `Search ${kind}`; search.setAttribute('aria-label', `Search ${kind} filter`);
        select.before(search);
        let timer; let sequence = 0;
        search.addEventListener('input', () => {
            clearTimeout(timer); timer = setTimeout(async () => {
                const current = ++sequence;
                try {
                    const parameters = new URLSearchParams({search: search.value, kind});
                    const response = await fetch(window.AppClient.appUrl(`/roster/task-filters?${parameters}`), {headers: {Accept: 'application/json'}});
                    const data = await response.json();
                    if (!response.ok) throw new Error(data.message || 'Filter options could not be loaded.');
                    if (current !== sequence) return;
                    select.replaceChildren(new Option(`All ${kind}s`, ''));
                    data.candidates.forEach(user => select.appendChild(new Option(user.name, user.id)));
                } catch(error) { showMessage(error.message, false); }
            }, 250);
        });
    }
    // Modal logic
    const newTaskBtn = document.getElementById('newTaskBtn');
    const taskModal = document.getElementById('taskModal');
    const taskForm = document.getElementById('taskForm');
    const modalClose = taskModal ? taskModal.querySelector('.modal-close') : null;
    const messageContainer = document.getElementById('messageContainer');
    const taskProjectSelect = document.getElementById('taskProject');
    const taskAssigneeSelect = document.getElementById('taskAssignee');
    const taskReviewerSelect = document.getElementById('taskReviewer');
    const projectMembers = window.projectMembers || {};
    const assignmentCandidates = window.assignmentCandidates || [];
    const reviewerCandidates = window.reviewerCandidates || [];
    const projectMembership = window.projectMembership || {};
    const canManageTasks = document.body.dataset.userRole !== 'team_member';
    let editTaskId = null;
    let editTaskVersion = null;

    if (!canManageTasks) {
        newTaskBtn?.style.setProperty('display', 'none');
        document.querySelectorAll('.delete-btn, .table-delete-btn, #bulkActions, #selectAllTasks, .task-checkbox').forEach(el => {
            el.style.display = 'none';
        });
    }

    let rosterRequest = 0;
    let rosterTimer;
    const assigneeSearch = document.createElement('input');
    assigneeSearch.type = 'search';
    assigneeSearch.placeholder = 'Search staff by name';
    assigneeSearch.setAttribute('aria-label', 'Search task assignee');
    assigneeSearch.addEventListener('input', () => {
        clearTimeout(rosterTimer);
        rosterTimer = setTimeout(() => populateAssignees(taskProjectSelect.value), 250);
    });
    taskAssigneeSelect?.before(assigneeSearch);
    let assigneeLoading = Promise.resolve();
    let reviewerLoading = Promise.resolve();
    function populateAssignees(projectId, selectedId = '') {
        return assigneeLoading = loadAssignees(projectId, selectedId);
    }
    async function loadAssignees(projectId, selectedId = '') {
        if (!projectId || !taskAssigneeSelect) { renderAssignees(projectId, selectedId); return; }
        const sequence = ++rosterRequest;
        taskAssigneeSelect.disabled = true;
        try {
            const url = window.AppClient.appUrl(`/projects/${projectId}/candidates`);
            const parameters = new URLSearchParams({search: assigneeSearch.value, kind: 'assignment'});
            if (selectedId) parameters.set('selected', selectedId);
            const response = await fetch(`${url}?${parameters}`, {headers: {Accept: 'application/json'}});
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Staff could not be loaded.');
            if (sequence !== rosterRequest) return;
            assignmentCandidates.splice(0, assignmentCandidates.length, ...data.candidates);
            projectMembers[projectId] = data.candidates.filter(user => user.project_member);
            renderAssignees(projectId, selectedId);
        } catch (error) {
            if (sequence === rosterRequest) showMessage(error.message || 'Staff could not be loaded. Search again to retry.', false);
        } finally {
            if (sequence === rosterRequest) taskAssigneeSelect.disabled = false;
        }
    }

    function renderAssignees(projectId, selectedId = '') {
        if (!taskAssigneeSelect) return;

        taskAssigneeSelect.replaceChildren(new Option('Select Team Member', ''));

        const members = projectMembers[projectId] || [];
        const memberIds = new Set(members.map(member => String(member.id)));
        const memberGroup = document.createElement('optgroup');
        memberGroup.label = 'Project members';

        members.forEach(member => {
            const option = document.createElement('option');
            option.value = member.id;
            option.textContent = member.name;
            if (String(member.id) === String(selectedId)) {
                option.selected = true;
            }
            memberGroup.appendChild(option);
        });

        if (memberGroup.children.length > 0) {
            taskAssigneeSelect.appendChild(memberGroup);
        }

        const availableGroup = document.createElement('optgroup');
        availableGroup.label = 'Available staff — add to project';
        assignmentCandidates
            .filter(candidate => !memberIds.has(String(candidate.id)))
            .filter(candidate => {
                const reviewer = reviewerCandidates.find(user => String(user.id) === String(candidate.id));
                const project = projectMembership[projectId];
                return reviewer?.role?.name !== 'project_manager' || String(project?.project_manager_id) === String(candidate.id);
            })
            .forEach(candidate => {
                const option = document.createElement('option');
                option.value = candidate.id;
                option.textContent = `${candidate.name} (add to project)`;
                option.dataset.requiresMembership = 'true';
                if (String(candidate.id) === String(selectedId)) {
                    option.selected = true;
                }
                availableGroup.appendChild(option);
            });

        if (projectId && availableGroup.children.length > 0) {
            taskAssigneeSelect.appendChild(availableGroup);
        }
    }

    function firstErrorMessage(data, fallback) {
        const validationMessages = Object.values(data.errors || {}).flat();

        return data.message || validationMessages[0] || fallback;
    }

    let reviewerRequest = 0;
    let reviewerTimer;
    const reviewerSearch = document.createElement('input');
    reviewerSearch.type = 'search'; reviewerSearch.placeholder = 'Search reviewer by name';
    reviewerSearch.setAttribute('aria-label', 'Search task reviewer');
    taskReviewerSelect?.before(reviewerSearch);
    reviewerSearch.addEventListener('input', () => {
        clearTimeout(reviewerTimer); reviewerTimer = setTimeout(() => populateReviewers(taskProjectSelect.value), 250);
    });
    function populateReviewers(projectId, selectedId = '') {
        return reviewerLoading = loadReviewers(projectId, selectedId);
    }
    async function loadReviewers(projectId, selectedId = '') {
        if (!projectId || !taskReviewerSelect) { renderReviewers(projectId, selectedId); return; }
        const sequence = ++reviewerRequest;
        taskReviewerSelect.disabled = true;
        try {
            const parameters = new URLSearchParams({search: reviewerSearch.value, kind: 'reviewer'});
            if (selectedId) parameters.set('selected', selectedId);
            const response = await fetch(window.AppClient.appUrl(`/projects/${projectId}/candidates?${parameters}`), {headers: {Accept: 'application/json'}});
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Reviewers could not be loaded.');
            if (sequence !== reviewerRequest) return;
            reviewerCandidates.splice(0, reviewerCandidates.length, ...data.candidates);
            renderReviewers(projectId, selectedId);
        } catch(error) { if (sequence === reviewerRequest) showMessage(error.message, false); }
        finally { if (sequence === reviewerRequest) taskReviewerSelect.disabled = Boolean(editTaskId); }
    }

    function renderReviewers(projectId, selectedId = '') {
        if (!taskReviewerSelect) return;

        taskReviewerSelect.replaceChildren(new Option('Select Reviewer', ''));
        const project = projectMembership[projectId];
        reviewerCandidates
            .filter(candidate => candidate.role?.name === 'manager'
                || String(candidate.id) === String(project?.project_manager_id))
            .forEach(candidate => {
                const option = new Option(candidate.name, candidate.id);
                option.selected = String(candidate.id) === String(selectedId);
                taskReviewerSelect.appendChild(option);
            });
    }

    async function ensureSelectedAssigneeMembership(payload) {
        const selectedOption = taskAssigneeSelect?.selectedOptions[0];
        if (!selectedOption || selectedOption.dataset.requiresMembership !== 'true') {
            return true;
        }

        const project = projectMembership[payload.project_id];
        if (!project) {
            throw new Error('The selected project is not available.');
        }

        const staffMember = assignmentCandidates.find(candidate => String(candidate.id) === String(payload.assignee_id));
        const confirmation = await Swal.fire({
            icon: 'question',
            title: 'Add staff to project?',
            text: `Add ${staffMember?.name || 'this staff member'} to ${project.name} and assign this task?`,
            showCancelButton: true,
            confirmButtonText: 'Add and assign',
            cancelButtonText: 'Cancel',
        });

        if (!confirmation.isConfirmed) {
            return false;
        }

        const response = await fetch(project.addMemberUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'Accept': 'application/json',
            },
            body: JSON.stringify({ user_id: payload.assignee_id }),
        });
        const data = await response.json().catch(() => ({}));

        if (!response.ok || !data.success) {
            throw new Error(firstErrorMessage(data, 'The staff member could not be added to the project.'));
        }

        projectMembers[payload.project_id] = (data.members || [])
            .filter(member => ![false, 0, '0'].includes(member.active))
            .map(member => ({ id: member.id, name: member.name }));
        populateAssignees(payload.project_id, payload.assignee_id);

        return true;
    }

    function showMessage(msg, success = true) {
        Swal.fire({
            icon: success ? 'success' : 'error',
            title: success ? 'Success' : 'Error',
            text: msg,
            timer: success ? 2000 : undefined,
            showConfirmButton: !success
        });
    }

    function setLoading(isLoading) {
        taskForm.setAttribute('aria-busy', String(isLoading));
        taskForm.dataset.pending = isLoading ? 'true' : 'false';
        const submitBtn = document.getElementById('submitBtn');
        if (submitBtn) submitBtn.disabled = isLoading;
        if (isLoading) {
            submitBtn.textContent = '⏳ Please wait...';
        } else {
            submitBtn.textContent = editTaskId ? '✏️ Update Task' : '➕ Create Task';
        }
    }

    function populateTaskEditForm(task) {
        if (!taskModal || !taskForm) return;
        taskModal.classList.add('active');
        document.getElementById('submitBtn').textContent = '✏️ Update Task';
        document.getElementById('modalTitle').textContent = 'Edit Task';
        taskForm.querySelector('#taskTitle').value = task.title || '';
        taskForm.querySelector('#taskDescription').value = task.description || '';
        taskForm.querySelector('#taskProject').value = task.project_id || '';
        populateAssignees(task.project_id || '', task.assignee_id || '');
        populateReviewers(task.project_id || '', task.reviewer_id || '');
        taskForm.querySelector('#taskPriority').value = task.priority || 'Medium';
        taskForm.querySelector('#taskStartDate').value = task.start_date || '';
        taskForm.querySelector('#taskDueDate').value = task.due_date || '';
        taskForm.querySelector('#taskDueDate').disabled = true;
        taskForm.querySelector('#taskReviewer').disabled = true;
        taskForm.querySelector('#taskComments').value = task.comments || '';
        editTaskId = task.id;
        editTaskVersion = task.lock_version ?? task.version ?? 1;
    }

    function resetModal() {
        taskForm.reset();
        editTaskId = null;
        editTaskVersion = null;
        setLoading(false);
        // Do not show any message on modal reset/close
    }

    if (newTaskBtn && taskModal) {
        newTaskBtn.addEventListener('click', function() {
            taskForm.reset();
            editTaskId = null;
            editTaskVersion = null;
            taskReviewerSelect.disabled = false;
            document.getElementById('taskDueDate').disabled = false;
            taskModal.classList.add('active');
            document.getElementById('submitBtn').textContent = '➕ Create Task';
            document.getElementById('modalTitle').textContent = 'Create New Task';
            // Set start date to today by default
            const startDateInput = document.getElementById('taskStartDate');
            if (startDateInput) {
                const today = new Date();
                const yyyy = today.getFullYear();
                const mm = String(today.getMonth() + 1).padStart(2, '0');
                const dd = String(today.getDate()).padStart(2, '0');
                startDateInput.value = `${yyyy}-${mm}-${dd}`;
            }
            // Populate assignees when opening new task modal
            if (taskProjectSelect) {
                populateAssignees(taskProjectSelect.value);
                populateReviewers(taskProjectSelect.value);
            }
        });
    }
    if (modalClose) {
        modalClose.addEventListener('click', function() {
            taskModal.classList.remove('active');
            resetModal();
        });
    }
    if (taskModal) {
        taskModal.addEventListener('click', function(e) {
            if (e.target === this) {
                taskModal.classList.remove('active');
                resetModal();
            }
        });
    }
    // Edit task
    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const card = this.closest('.task-card');
            const taskId = card.dataset.taskId;
            fetch(window.AppClient.appUrl(`/tasks/${taskId}/edit`), {
                headers: { 'Accept': 'application/json' }
            })
            .then(r => r.json())
            .then(data => {
                if (data.success && data.task) {
                    populateTaskEditForm(data.task);
                } else {
                    showMessage('Could not fetch task data.', false);
                }
            })
            .catch(() => showMessage('Could not fetch task data.', false));
        });
    });
    // Delete task
    document.querySelectorAll('.delete-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            Swal.fire({
                title: 'Delete this task?',
                text: 'This action cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (!result.isConfirmed) return;
            const card = this.closest('.task-card');
            const id = card.dataset.taskId;
                fetch(window.AppClient.appUrl(`/tasks/${id}`), {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                       'Accept': 'application/json',
                    },
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        card.remove();
                        Swal.fire('Deleted!', 'Task deleted.', 'success');
                    } else if (data.message) {
                        Swal.fire('Error', data.message, 'error');
                    } else {
                        Swal.fire('Error', 'Error deleting task.', 'error');
                    }
                })
                .catch(error => {
                    Swal.fire('Error', 'Error deleting task: ' + (error.message || error), 'error');
                });
            });
        });
    });
    // Create/update task
    taskForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        if (taskForm.dataset.pending === 'true') return;
        setLoading(true);
        await Promise.all([assigneeLoading, reviewerLoading]);
        const formData = new FormData(taskForm);
        const payload = {
            title: formData.get('taskTitle'),
            description: formData.get('taskDescription'),
            project_id: formData.get('taskProject'),
            assignee_id: formData.get('taskAssignee'),
            priority: formData.get('taskPriority'),
            start_date: formData.get('taskStartDate'),
            comments: formData.get('taskComments'),
        };
        if (editTaskId) {
            payload.expected_version = editTaskVersion;
        } else {
            payload.reviewer_id = formData.get('taskReviewer');
            payload.due_date = formData.get('taskDueDate');
        }
        const url = editTaskId
            ? window.taskUpdateUrlTemplate.replace('__TASK_ID__', encodeURIComponent(editTaskId))
            : window.taskStoreUrl;
        const method = editTaskId ? 'PUT' : 'POST';

        try {
            if (!await ensureSelectedAssigneeMembership(payload)) {
                setLoading(false);

                return;
            }
        } catch (error) {
            setLoading(false);
            showMessage(error.message || 'The staff member could not be added to the project.', false);

            return;
        }

        fetch(url, {
            method,
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'Accept': 'application/json',
            },
            body: JSON.stringify(payload),
        })
        .then(async response => {
            const data = await response.json().catch(() => ({}));
            setLoading(false);

            if (response.status === 409) {
                const conflictMsg = data.message || 'This task changed after you opened it. Your update was not saved. Review the latest version and try again.';
                const latestTask = data.task;

                Swal.fire({
                    icon: 'warning',
                    title: 'Edit Conflict',
                    text: conflictMsg,
                    showCancelButton: true,
                    confirmButtonText: 'Reload latest task',
                    cancelButtonText: 'Keep viewing mine',
                    confirmButtonColor: '#3085d6',
                    cancelButtonColor: '#6c757d',
                }).then((choice) => {
                    if (choice.isConfirmed) {
                        if (latestTask) {
                            populateTaskEditForm(latestTask);
                        } else if (editTaskId) {
                            fetch(window.AppClient.appUrl(`/tasks/${editTaskId}/edit`), { headers: { 'Accept': 'application/json' } })
                                .then(r => r.json())
                                .then(latestData => {
                                    if (latestData.success && latestData.task) {
                                        populateTaskEditForm(latestData.task);
                                    }
                                });
                        }
                    }
                });
                return;
            }

            if (!response.ok) {
                const message = firstErrorMessage(data, 'An error occurred while saving the task.');
                showMessage(message, false);
                return;
            }

            if (data.task && editTaskId) {
                showMessage('Task updated.');
                if (data.moved) {
                    const card = document.querySelector(`.task-card[data-task-id='${editTaskId}']`);
                    if (card) card.remove();
                    const row = document.querySelector(`tr[data-task-id='${editTaskId}']`);
                    if (row) row.remove();
                    if (taskModal) taskModal.classList.remove('active');
                    resetModal();
                    return;
                }
                // Update the card in the DOM
                const card = document.querySelector(`.task-card[data-task-id='${editTaskId}']`);
                if (card) {
                    const titleEl = card.querySelector('.task-title');
                    if (titleEl) {
                        const opener = titleEl.querySelector('.task-open-btn');
                        if (opener) { opener.textContent = data.task.title; opener.setAttribute('aria-label', 'Edit ' + data.task.title); }
                        else titleEl.textContent = data.task.title;
                    }
                    const projectEl = card.querySelector('.task-project');
                    if (projectEl) projectEl.textContent = 'Project: ' + (data.task.project ? data.task.project.name : '-');
                    const priorityBadge = card.querySelector('.priority-badge');
                    if (priorityBadge) {
                        priorityBadge.textContent = data.task.priority;
                        priorityBadge.className = 'priority-badge priority-' + data.task.priority.toLowerCase();
                    }
                    const statusBadge = card.querySelector('.status-badge');
                    if (statusBadge) {
                        statusBadge.textContent = data.task.status_label || data.task.status.replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase());
                        statusBadge.className = 'status-badge status-' + data.task.status.toLowerCase().replace(/ /g, '-');
                    }
                    const progressHeader = card.querySelector('.progress-header span:last-child');
                    if (progressHeader) progressHeader.textContent = data.task.progress + '%';
                    const progressFill = card.querySelector('.progress-fill');
                    if (progressFill) progressFill.style.width = data.task.progress + '%';
                    const dueDateEl = card.querySelector('.due-date');
                    if (dueDateEl) dueDateEl.textContent = data.task.due_date ? new Date(data.task.due_date).toLocaleDateString() : '-';
                    const updatedDateEl = card.querySelector('.date-item .date-icon + span');
                    if (updatedDateEl) updatedDateEl.textContent = 'Updated ' + (data.task.updated_at ? new Date(data.task.updated_at).toLocaleDateString() : '-');
                    const commentsP = card.querySelector('.task-comments p');
                    if (commentsP) commentsP.textContent = data.task.comments || '';
                    card.dataset.priority = data.task.priority;
                    card.dataset.status = data.task.status;
                    card.dataset.projectId = data.task.project_id;
                    card.dataset.progress = data.task.progress;
                    card.dataset.dueDate = data.task.due_date;
                    card.dataset.startDate = data.task.start_date;
                }
                // Update the table row in the DOM
                const row = document.querySelector(`tr[data-task-id='${editTaskId}']`);
                if (row) {
                    const tds = row.querySelectorAll('td');
                    // tds[0]: checkbox (skip)
                    // tds[1]: Title
                    if (tds[1]) tds[1].textContent = data.task.title;
                    // tds[2]: Project
                    if (tds[2]) tds[2].textContent = data.task.project ? data.task.project.name : '-';
                    // tds[3]: Status
                    if (tds[3]) {
                        let statusBadge = tds[3].querySelector('.status-badge');
                        if (!statusBadge) {
                            statusBadge = document.createElement('span');
                            statusBadge.className = 'status-badge';
                            tds[3].appendChild(statusBadge);
                        }
                        statusBadge.textContent = data.task.status;
                        statusBadge.className = 'status-badge status-' + data.task.status.toLowerCase().replace(/ /g, '-');
                    }
                    // tds[4]: Priority
                    if (tds[4]) {
                        let priorityBadge = tds[4].querySelector('.priority-badge');
                        if (!priorityBadge) {
                            priorityBadge = document.createElement('span');
                            priorityBadge.className = 'priority-badge';
                            tds[4].appendChild(priorityBadge);
                        }
                        priorityBadge.textContent = data.task.priority;
                        priorityBadge.className = 'priority-badge priority-' + data.task.priority.toLowerCase();
                    }
                    // tds[5]: Assignee/Assigned By
                    const userRole = document.body.getAttribute('data-user-role');
                    if (userRole === 'team_member') {
                        if (tds[5]) tds[5].textContent = data.task.created_by_name || 'Manager';
                    } else {
                        if (tds[5]) tds[5].textContent = data.task.assignee ? data.task.assignee.name : '-';
                    }
                    // tds[6]: Due Date
                    if (tds[6]) tds[6].textContent = data.task.due_date ? new Date(data.task.due_date).toLocaleDateString() : '-';
                    // tds[7]: Progress
                    if (tds[7]) tds[7].textContent = data.task.progress + '%';
                    // tds[8]: Actions (skip)
                }
                taskModal.classList.remove('active');
                resetModal();
            } else if (data.task) {
                showMessage('Task created.');
                window.location.reload();
            } else if (data.message) {
                showMessage(data.message, false);
            } else {
                showMessage('An error occurred. Please try again.', false);
            }
        })
        .catch(error => {
            setLoading(false);
            showMessage('An error occurred: ' + (error.message || error), false);
        });
    });
    // Progress slider
    const progressSlider = document.getElementById('taskProgress');
    const progressValue = document.getElementById('progressValue');
    if (progressSlider && progressValue) {
        progressSlider.addEventListener('input', function() {
            progressValue.textContent = this.value;
        });
    }
    if (taskProjectSelect) {
        taskProjectSelect.addEventListener('change', function() {
            populateAssignees(this.value);
            populateReviewers(this.value);
        });
        populateAssignees(taskProjectSelect.value);
        populateReviewers(taskProjectSelect.value);
    }

    const tasksGrid = document.getElementById('tasksGrid');

    // Native edit buttons preserve keyboard semantics without nesting card actions.
    document.querySelectorAll('.task-open-btn').forEach(button => {
        button.addEventListener('click', function(e) {
            const card = this.closest('.task-card');
            const taskId = card.dataset.taskId;
            fetch(window.AppClient.appUrl(`/tasks/${taskId}/edit`), {
                headers: { 'Accept': 'application/json' }
            })
            .then(r => r.json())
            .then(data => {
                if (data.success && data.task) {
                    populateTaskEditForm(data.task);
                } else {
                    showMessage('Could not fetch task data.', false);
                }
            })
            .catch(() => showMessage('Could not fetch task data.', false));
        });
    });

    window.TaskFeatures.initLifecycleActions({ reviewerCandidates, showMessage, firstErrorMessage });
    window.TaskFeatures.initTimelineActions(message => showMessage(message, false));

    document.querySelectorAll('.progress-update-btn').forEach(button => {
        button.addEventListener('click', async function(e) {
            e.preventDefault();
            e.stopPropagation();
            const progress = await Swal.fire({
                title: 'Update progress',
                input: 'number',
                inputLabel: 'Progress must remain between 0 and 99 until approval.',
                inputValue: this.dataset.progress,
                inputAttributes: { min: '0', max: '99', step: '1' },
                showCancelButton: true,
                confirmButtonText: 'Continue',
                inputValidator: value => value === '' || Number(value) < 0 || Number(value) > 99
                    ? 'Enter a progress value from 0 to 99.'
                    : undefined,
            });
            if (!progress.isConfirmed) return;

            const comments = await Swal.fire({
                title: 'Visible comments',
                input: 'textarea',
                inputLabel: 'Optional',
                inputValue: this.dataset.comments || '',
                showCancelButton: true,
                confirmButtonText: 'Update Progress',
            });
            if (!comments.isConfirmed) return;

            this.disabled = true;
            try {
                const response = await fetch(this.dataset.url, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        progress: Number(progress.value),
                        comments: comments.value?.trim() || null,
                    }),
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok || !data.success) {
                    throw new Error(firstErrorMessage(data, 'Progress could not be updated.'));
                }
                window.location.reload();
            } catch (error) {
                showMessage(error.message || 'Progress could not be updated.', false);
                this.disabled = false;
            }
        });
    });

    // Toggle view logic
    const toggleViewBtn = document.getElementById('toggleViewBtn');
    const tasksTableWrapper = document.getElementById('tasksTableWrapper');
    const taskViewStorageKey = 'task-management.tasks.view';
    let isTable = false;

    function applyTaskView(view) {
        isTable = view === 'table';
        tasksGrid.style.display = isTable ? 'none' : '';
        tasksTableWrapper.style.display = isTable ? '' : 'none';
        toggleViewBtn.textContent = isTable ? 'Card View' : 'Table View';
        toggleViewBtn.setAttribute('aria-pressed', isTable ? 'true' : 'false');
    }

    if (toggleViewBtn && tasksGrid && tasksTableWrapper) {
        let savedView = 'cards';
        try {
            savedView = localStorage.getItem(taskViewStorageKey) || 'cards';
        } catch (error) {
            savedView = 'cards';
        }
        applyTaskView(savedView);

        toggleViewBtn.addEventListener('click', function() {
            const nextView = isTable ? 'cards' : 'table';
            applyTaskView(nextView);
            try {
                localStorage.setItem(taskViewStorageKey, nextView);
            } catch (error) {
                // The selected view still applies for this page load.
            }
        });
    }
    // Table edit/delete
    document.querySelectorAll('.table-edit-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            const row = this.closest('tr');
            const card = document.querySelector(`.task-card[data-task-id='${row.dataset.taskId}']`);
            if (card) card.click();
        });
    });
    document.querySelectorAll('.table-delete-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            const row = this.closest('tr');
            const card = document.querySelector(`.task-card[data-task-id='${row.dataset.taskId}']`);
            if (card) card.querySelector('.delete-btn').click();
        });
    });

    // Bulk Actions
    const selectAllTasksCheckbox = document.getElementById('selectAllTasks');
    const taskCheckboxes = document.querySelectorAll('.task-checkbox');
    const bulkActions = document.getElementById('bulkActions');
    const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');

    function updateBulkActions() {
        const checkedCount = document.querySelectorAll('.task-checkbox:checked').length;
        selectAllTasksCheckbox.checked = taskCheckboxes.length > 0 && checkedCount === taskCheckboxes.length;
        bulkDeleteBtn.disabled = checkedCount === 0;
        bulkActions.style.display = checkedCount > 0 ? '' : 'none';
    }

    selectAllTasksCheckbox.addEventListener('change', function() {
        taskCheckboxes.forEach(checkbox => {
            checkbox.checked = this.checked;
        });
        updateBulkActions();
    });

    taskCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateBulkActions);
    });

    bulkDeleteBtn.addEventListener('click', function() {
        const selectedTaskIds = Array.from(taskCheckboxes).filter(checkbox => checkbox.checked).map(checkbox => checkbox.value);
        Swal.fire({
            title: 'Delete selected tasks?',
            text: 'This action cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, delete them!'
        }).then((result) => {
            if (!result.isConfirmed) return;
            fetch(window.AppClient.appUrl(`/tasks/bulk-delete`), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ task_ids: selectedTaskIds }),
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    selectedTaskIds.forEach(id => {
                        const card = document.querySelector(`.task-card[data-task-id='${id}']`);
                        if (card) card.remove();
                        const row = document.querySelector(`tr[data-task-id='${id}']`);
                        if (row) row.remove();
                    });
                    updateBulkActions();
                    Swal.fire('Deleted!', 'Tasks deleted.', 'success');
                } else if (data.message) {
                    Swal.fire('Error', data.message, 'error');
                } else {
                    Swal.fire('Error', 'Error deleting tasks.', 'error');
                }
            })
            .catch(error => {
                Swal.fire('Error', 'Error deleting tasks: ' + (error.message || error), 'error');
            });
        });
    });

});
</script>
@endpush
@section('content')
<div class="tasks-container">

    <div class="tasks-header">
        <h1>Task Management</h1>
        <button id="toggleViewBtn" class="btn-secondary" style="margin-right: 1rem;">🔳 Table View</button>
        <button id="newTaskBtn" class="btn-primary">➕ New Task</button>
    </div>
    <div id="messageContainer" class="message-container" style="display: none;"></div>
    <!-- Filters -->
    <div class="filters-card">
        @if ($errors->any())
            <div class="filter-errors" role="alert">
                <strong>Some filters could not be applied.</strong>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        <form method="GET" action="{{ route('tasks') }}" id="taskFilters">
            <div class="filters-grid">
                <div class="search-group">
                    <div class="search-input">
                        <span class="search-icon">🔍</span>
                        <input
                            type="search"
                            id="searchTasks"
                            name="search"
                            value="{{ $filters['search'] ?? '' }}"
                            maxlength="200"
                            placeholder="Search tasks..."
                            aria-label="Search tasks"
                        >
                    </div>
                </div>
                <select id="statusFilter" name="status" aria-label="Filter tasks by status">
                    <option value="">All Status</option>
                    @foreach (\App\Enums\TaskState::options() as $status => $label)
                        <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $label }}</option>
                    @endforeach
                </select>
                <select id="priorityFilter" name="priority" aria-label="Filter tasks by priority">
                    <option value="">All Priority</option>
                    @foreach (\App\Models\Task::PRIORITIES as $priority)
                        <option value="{{ $priority }}" @selected(($filters['priority'] ?? '') === $priority)>{{ $priority }}</option>
                    @endforeach
                </select>
                <select id="projectFilter" name="project" aria-label="Filter tasks by project">
                    <option value="">All Projects</option>
                    @foreach ($filterProjects as $project)
                        <option value="{{ $project->id }}" @selected((string) ($filters['project'] ?? '') === (string) $project->id)>{{ $project->name }}</option>
                    @endforeach
                </select>
                <select id="assigneeFilter" name="assignee" aria-label="Filter tasks by assignee">
                    <option value="">All Assignees</option>
                    @foreach ($assignees as $assignee)
                        <option value="{{ $assignee->id }}" @selected((string) ($filters['assignee'] ?? '') === (string) $assignee->id)>{{ $assignee->name }}</option>
                    @endforeach
                </select>
                <select id="reviewerFilter" name="reviewer" aria-label="Filter tasks by reviewer">
                    <option value="">All Reviewers</option>
                    @foreach ($filterReviewers as $reviewer)
                        <option value="{{ $reviewer->id }}" @selected((string) ($filters['reviewer'] ?? '') === (string) $reviewer->id)>{{ $reviewer->name }}</option>
                    @endforeach
                </select>
                <select id="scopeFilter" name="scope" aria-label="Filter tasks by relationship">
                    <option value="">All Authorized Tasks</option>
                    <option value="assigned_to_me" @selected(($filters['scope'] ?? '') === 'assigned_to_me')>Assigned to me</option>
                    <option value="created_by_me" @selected(($filters['scope'] ?? '') === 'created_by_me')>Created by me</option>
                    <option value="waiting_for_review" @selected(($filters['scope'] ?? '') === 'waiting_for_review')>Waiting for my review</option>
                </select>
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn-primary">Apply filters</button>
                @if ($hasActiveFilters)
                    <a href="{{ route('tasks') }}" class="btn-secondary">Clear filters</a>
                @endif
            </div>
        </form>
    </div>
    <p class="task-results-summary" role="status">
        @if ($tasks->total() > 0)
            Showing {{ $tasks->firstItem() }}&ndash;{{ $tasks->lastItem() }} of {{ $tasks->total() }} {{ $hasActiveFilters ? 'matching ' : '' }}tasks
        @elseif ($hasActiveFilters)
            No tasks match the selected filters.
        @else
            No tasks are currently available.
        @endif
    </p>
    <!-- Tasks Grid (Card View) -->
    <div id="tasksGrid" class="tasks-grid">
        @forelse ($tasks as $task)
            @php
                $taskState = $task->machineState();
                $canExecuteTask = (int) $task->assignee_id === (int) $user->id;
                $activeRevisionCycle = $task->activeRevisionCycle;
                $activeDeadline = $task->activeDeadline();
            @endphp
            <div id="task-{{ $task->id }}" class="task-card" tabindex="-1"
                data-task-id="{{ $task->id }}"
                data-task-uid="{{ $task->task_uid }}"
                data-assignee-id="{{ $task->assignee_id }}"
                data-project-id="{{ $task->project_id }}"
                data-priority="{{ $task->priority }}"
                data-status="{{ $task->status }}"
                data-start-date="{{ $task->start_date }}"
                data-due-date="{{ $task->due_date }}"
                data-progress="{{ $task->progress }}"
                data-description="{{ htmlspecialchars($task->description ?? '', ENT_QUOTES) }}"
                data-comments="{{ htmlspecialchars($task->comments ?? '', ENT_QUOTES) }}"
                data-final-state="{{ $taskState->isFinal() ? 'true' : 'false' }}"
                data-generic-editable="{{ ! $user->hasRole('team_member') && $user->can('update', $task) && ! $taskState->isFinal() && ! $task->submitted_at && ! $task->active_revision_cycle_id ? 'true' : 'false' }}"
            >
                <div class="task-header">
                    <div class="task-status-icon">
                        <span class="status-icon {{ str_replace(' ', '-', strtolower($task->status)) }}">
                            @if($task->status === 'Completed')✅@elseif($task->status === 'In Progress')📈@elseif($task->status === 'On Hold')⏸️@else⭕@endif
                        </span>
                        <span class="task-number">#{{ $task->id }}</span>
                    </div>
                    <div class="task-actions">
                        <span class="priority-badge priority-{{ strtolower($task->priority) }}">{{ $task->priority }}</span>
                        <span class="status-badge status-{{ str_replace(' ', '-', strtolower($task->status)) }}">{{ $task->machineState()->label() }}</span>
                        @if ($taskState === \App\Enums\TaskState::NotStarted)
                            @can('delete', $task)
                                <button class="task-action-btn delete-btn" aria-label="Delete draft task {{ $task->title }}">🗑️</button>
                            @endcan
                        @endif
                    </div>
                </div>
                <h3 class="task-title">@if(! $user->hasRole('team_member') && $user->can('update', $task) && ! $taskState->isFinal() && ! $task->submitted_at && ! $task->active_revision_cycle_id)<button type="button" class="task-open-btn" aria-label="Edit {{ $task->title }}">{{ $task->title }}</button>@else{{ $task->title }}@endif</h3>
                <div class="task-details">
                    <span class="task-project">Project: {{ $task->project ? $task->project->name : '-' }}</span>
                    @php $user = auth()->user(); @endphp
                    @if($user && $user->role && $user->role->name === 'team_member')
                        <span class="task-assigned-by">Assigned by: {{ $task->creator?->name ?? 'Manager' }}</span>
                    @else
                        <span class="task-assignee">Assignee: {{ $task->assignee ? $task->assignee->name : '-' }}</span>
                    @endif
                </div>
                <div class="task-progress">
                    <div class="progress-header">
                        <span>Progress</span>
                        <span>{{ $task->progress }}%</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: {{ $task->progress }}%"></div>
                    </div>
                </div>
                <div class="task-dates">
                    <div class="date-item">
                        <span class="date-icon" aria-hidden="true">📅</span>
                        <span class="due-date">
                            {{ ucfirst($task->activeDeadlineKind() ?? '') }} deadline: {{ $activeDeadline?->format('M j, Y') ?? 'Not set' }}
                        </span>
                    </div>
                    <div class="date-item">
                        <span class="date-icon">⏰</span>
                        <span>Updated {{ $task->updated_at ? $task->updated_at->format('m/d/Y') : '-' }}</span>
                    </div>
                </div>
                <div class="task-details">
                    <span>Reviewer: {{ $task->reviewer?->name ?? 'Not assigned' }}</span>
                    <span>Review due: {{ $task->review_due_date?->format('m/d/Y') ?? '-' }}</span>
                </div>
                @if ($activeRevisionCycle)
                    <div class="task-details">
                        <span>Revision: {{ $activeRevisionCycle->cycle_number }} of {{ $task->revision_count }}</span>
                        <span>Revision due: {{ $task->revision_due_date?->format('m/d/Y') ?? '-' }}</span>
                    </div>
                    <div class="task-comments">
                        <strong>Revision feedback</strong>
                        <p>{{ $activeRevisionCycle->formal_feedback }}</p>
                    </div>
                @endif
                @if (in_array($taskState, [\App\Enums\TaskState::Submitted, \App\Enums\TaskState::InReview], true) && $task->latestSubmission?->submission_note)
                    <div class="task-comments">
                        <strong>Submission note</strong>
                        <p>{{ $task->latestSubmission->submission_note }}</p>
                        <small>Submitted by {{ $task->latestSubmission->submittedBy?->name ?? 'Assignee' }}</small>
                    </div>
                @endif
                @if($task->comments)
                <div class="task-comments">
                    <p>{{ $task->comments }}</p>
                </div>
                @endif
                @if ($canExecuteTask)
                    <div class="execution-actions" aria-label="Task execution actions">
                        @if ($taskState === \App\Enums\TaskState::NotStarted)
                            @can('start', $task)
                                <button type="button" class="btn-small btn-primary execution-transition-btn"
                                    data-task-version="{{ $task->lock_version }}" data-transition="start" data-url="{{ route('tasks.start', $task) }}">Start Work</button>
                            @endcan
                        @elseif ($taskState === \App\Enums\TaskState::InProgress)
                            @can('hold', $task)
                                <button type="button" class="btn-small btn-secondary execution-transition-btn"
                                    data-task-version="{{ $task->lock_version }}" data-transition="hold" data-url="{{ route('tasks.hold', $task) }}">Put On Hold</button>
                            @endcan
                            @if ($activeRevisionCycle)
                                @can('resubmit', $task)
                                    <button type="button" class="btn-small btn-primary execution-transition-btn"
                                        data-task-version="{{ $task->lock_version }}" data-transition="resubmit" data-url="{{ route('tasks.resubmit', $task) }}">Resubmit</button>
                                @endcan
                            @else
                                @can('submit', $task)
                                    <button type="button" class="btn-small btn-primary execution-transition-btn"
                                        data-task-version="{{ $task->lock_version }}" data-transition="submit" data-url="{{ route('tasks.submit', $task) }}">Submit for Review</button>
                                @endcan
                            @endif
                        @elseif ($taskState === \App\Enums\TaskState::OnHold)
                            @can('resume', $task)
                                <button type="button" class="btn-small btn-primary execution-transition-btn"
                                    data-task-version="{{ $task->lock_version }}" data-transition="resume" data-url="{{ route('tasks.resume', $task) }}">Resume</button>
                            @endcan
                        @elseif ($taskState === \App\Enums\TaskState::RevisionRequested)
                            @can('startRevision', $task)
                                <button type="button" class="btn-small btn-primary execution-transition-btn"
                                    data-task-version="{{ $task->lock_version }}" data-transition="revision-start" data-url="{{ route('tasks.revision.start', $task) }}">Begin Revision</button>
                            @endcan
                        @endif
                    </div>
                @endif
                @if ($taskState === \App\Enums\TaskState::InReview)
                    <div class="execution-actions">
                        @can('requestRevision', $task)
                            <button type="button" class="btn-small btn-secondary execution-transition-btn"
                                data-task-version="{{ $task->lock_version }}" data-transition="revision-request" data-url="{{ route('tasks.revision.request', $task) }}">Request Revision</button>
                        @endcan
                        @if ((int) $task->assignee_id !== (int) $user->id)
                            @can('approve', $task)
                                <button type="button" class="btn-small btn-primary execution-transition-btn"
                                    data-task-version="{{ $task->lock_version }}" data-transition="approve" data-url="{{ route('tasks.approve', $task) }}">Approve and Complete</button>
                            @endcan
                        @endif
                        @if ($user->hasRole('manager') && (int) $task->reviewer_id !== (int) $user->id)
                            @can('overrideApprove', $task)
                                <button type="button" class="btn-small btn-danger execution-transition-btn"
                                    data-task-version="{{ $task->lock_version }}" data-transition="override-approve" data-url="{{ route('tasks.approve.override', $task) }}">Emergency Override Approval</button>
                            @endcan
                        @endif
                    </div>
                @endif
                @if ($taskState === \App\Enums\TaskState::Submitted)
                    @can('startReview', $task)
                        <div class="execution-actions">
                            <button type="button" class="btn-small btn-primary execution-transition-btn"
                                data-task-version="{{ $task->lock_version }}" data-transition="review" data-url="{{ route('tasks.review.start', $task) }}">Start Review</button>
                        </div>
                    @endcan
                @endif
                @if (! $taskState->isFinal())
                    @can('cancel', $task)
                        <div class="execution-actions">
                            <button type="button" class="btn-small btn-danger execution-transition-btn"
                                data-task-version="{{ $task->lock_version }}" data-transition="cancel" data-url="{{ route('tasks.cancel', $task) }}">Cancel Task</button>
                        </div>
                    @endcan
                @endif
                <div class="execution-actions">
                    @if ($taskState === \App\Enums\TaskState::InProgress)
                        @can('updateProgress', $task)
                            <button type="button" class="btn-small btn-secondary progress-update-btn"
                                data-progress="{{ $task->progress }}"
                                data-comments="{{ $task->comments }}"
                                data-url="{{ route('tasks.update', $task) }}">Update Progress</button>
                        @endcan
                    @endif
                    <button type="button" class="btn-small btn-secondary timeline-btn"
                        data-url="{{ route('tasks.timeline', $task) }}">Timeline</button>
                    @if (! $taskState->isFinal())
                        @can('reassignReviewer', $task)
                            <button type="button" class="btn-small btn-secondary management-action-btn"
                                data-task-version="{{ $task->lock_version }}" data-action="reassign-reviewer"
                                data-reason-required="{{ $task->submitted_at || $task->active_revision_cycle_id ? 'true' : 'false' }}"
                                data-url="{{ route('tasks.reviewer.reassign', $task) }}">Reassign Reviewer</button>
                        @endcan
                        @php
                            $deadlineAction = app(\App\Services\TaskDeadlineAction::class)->for($task, $user);
                        @endphp
                        @if ($deadlineAction)
                            <button type="button" class="btn-small btn-secondary management-action-btn"
                                data-task-version="{{ $task->lock_version }}" data-action="change-deadline"
                                data-deadline-type="{{ $deadlineAction['type'] }}"
                                data-reason-required="{{ $deadlineAction['reason_required'] ? 'true' : 'false' }}"
                                data-url="{{ $deadlineAction['url'] }}">
                                {{ $deadlineAction['label'] }}
                            </button>
                        @endif
                    @endif
                </div>
                @if ($taskState === \App\Enums\TaskState::Cancelled)
                    <div class="task-meta">Cancelled {{ $task->cancelled_at?->format('m/d/Y H:i') }}</div>
                @endif
                @if(! $taskState->isFinal() && ($task->activeDeadlineGeneration()?->isOverdue() ?? false))
                <div class="overdue-warning">
                    <p><span aria-hidden="true">⚠️</span> Overdue</p>
                </div>
                @endif
            </div>
        @empty
            <div class="empty-state">
                <div class="empty-icon">📋</div>
                @if ($hasActiveFilters)
                    <h3>No tasks match your filters</h3>
                    <p>Try changing your filters or <a href="{{ route('tasks') }}">clear all filters</a>.</p>
                @else
                    <h3>No tasks available</h3>
                    <p>{{ $user->can('create', \App\Models\Task::class) ? 'Create your first task to get started!' : 'Tasks assigned to you will appear here.' }}</p>
                @endif
            </div>
        @endforelse
    </div>
    <!-- Bulk Actions -->
    <div id="bulkActions" style="display:none; margin-bottom:1rem;">
        <button id="bulkDeleteBtn" class="btn-small btn-danger">🗑️ Delete Selected</button>
    </div>
    <!-- Tasks Table View (hidden by default) -->
    <div id="tasksTableWrapper" style="display:none;">
        <table class="tasks-table">
            <thead>
                <tr>
                    <th><input type="checkbox" id="selectAllTasks"></th>
                    <th>Title</th>
                    <th>Project</th>
                    <th>Status</th>
                    <th>Priority</th>
                    <th>@php $user = auth()->user(); @endphp
                        @if($user && $user->role && $user->role->name === 'team_member') Assigned By @else Assignee @endif</th>
                    <th>Due Date</th>
                    <th>Progress</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($tasks as $task)
                    @php
                        $taskState = $task->machineState();
                        $canExecuteTask = (int) $task->assignee_id === (int) $user->id;
                        $activeRevisionCycle = $task->activeRevisionCycle;
                        $activeDeadline = $task->activeDeadline();
                    @endphp
                    <tr data-task-id="{{ $task->id }}">
                        <td>
                            @can('delete', $task)
                                <input type="checkbox" class="task-checkbox" value="{{ $task->id }}">
                            @endcan
                        </td>
                        <td>{{ $task->title }}</td>
                        <td>{{ $task->project ? $task->project->name : '-' }}</td>
                        <td><span class="status-badge status-{{ str_replace(' ', '-', strtolower($task->status)) }}">{{ $task->machineState()->label() }}</span></td>
                        <td><span class="priority-badge priority-{{ strtolower($task->priority) }}">{{ $task->priority }}</span></td>
                        <td>
                            @if($user && $user->role && $user->role->name === 'team_member')
                                {{ $task->creator?->name ?? 'Manager' }}
                            @else
                                {{ $task->assignee ? $task->assignee->name : '-' }}
                            @endif
                        </td>
                        <td>{{ $activeDeadline ? ucfirst($task->activeDeadlineKind()).': '.$activeDeadline->format('M j, Y') : '-' }}</td>
                        <td>{{ $task->progress }}%</td>
                        <td>
                            @if (! $taskState->isFinal())
                                <button class="btn-small btn-primary table-edit-btn">✏️</button>
                            @endif
                            @if ($taskState === \App\Enums\TaskState::NotStarted)
                                @can('delete', $task)
                                    <button class="btn-small btn-danger table-delete-btn">🗑️</button>
                                @endcan
                            @endif
                            @if ($canExecuteTask)
                                @if ($taskState === \App\Enums\TaskState::NotStarted)
                                    @can('start', $task)
                                        <button type="button" class="btn-small btn-primary execution-transition-btn"
                                            data-task-version="{{ $task->lock_version }}" data-transition="start" data-url="{{ route('tasks.start', $task) }}">Start Work</button>
                                    @endcan
                                @elseif ($taskState === \App\Enums\TaskState::InProgress)
                                    @can('hold', $task)
                                        <button type="button" class="btn-small btn-secondary execution-transition-btn"
                                            data-task-version="{{ $task->lock_version }}" data-transition="hold" data-url="{{ route('tasks.hold', $task) }}">Put On Hold</button>
                                    @endcan
                                    @if ($activeRevisionCycle)
                                        @can('resubmit', $task)
                                            <button type="button" class="btn-small btn-primary execution-transition-btn"
                                                data-task-version="{{ $task->lock_version }}" data-transition="resubmit" data-url="{{ route('tasks.resubmit', $task) }}">Resubmit</button>
                                        @endcan
                                    @else
                                        @can('submit', $task)
                                            <button type="button" class="btn-small btn-primary execution-transition-btn"
                                                data-task-version="{{ $task->lock_version }}" data-transition="submit" data-url="{{ route('tasks.submit', $task) }}">Submit for Review</button>
                                        @endcan
                                    @endif
                                @elseif ($taskState === \App\Enums\TaskState::OnHold)
                                    @can('resume', $task)
                                        <button type="button" class="btn-small btn-primary execution-transition-btn"
                                            data-task-version="{{ $task->lock_version }}" data-transition="resume" data-url="{{ route('tasks.resume', $task) }}">Resume</button>
                                    @endcan
                                @elseif ($taskState === \App\Enums\TaskState::RevisionRequested)
                                    @can('startRevision', $task)
                                        <button type="button" class="btn-small btn-primary execution-transition-btn"
                                            data-task-version="{{ $task->lock_version }}" data-transition="revision-start" data-url="{{ route('tasks.revision.start', $task) }}">Begin Revision</button>
                                    @endcan
                                @endif
                            @endif
                            @if ($taskState === \App\Enums\TaskState::InReview)
                                @can('requestRevision', $task)
                                    <button type="button" class="btn-small btn-secondary execution-transition-btn"
                                        data-task-version="{{ $task->lock_version }}" data-transition="revision-request" data-url="{{ route('tasks.revision.request', $task) }}">Request Revision</button>
                                @endcan
                                @if ((int) $task->assignee_id !== (int) $user->id)
                                    @can('approve', $task)
                                        <button type="button" class="btn-small btn-primary execution-transition-btn"
                                            data-task-version="{{ $task->lock_version }}" data-transition="approve" data-url="{{ route('tasks.approve', $task) }}">Approve and Complete</button>
                                    @endcan
                                @endif
                                @if ($user->hasRole('manager') && (int) $task->reviewer_id !== (int) $user->id)
                                    @can('overrideApprove', $task)
                                        <button type="button" class="btn-small btn-danger execution-transition-btn"
                                            data-task-version="{{ $task->lock_version }}" data-transition="override-approve" data-url="{{ route('tasks.approve.override', $task) }}">Emergency Override Approval</button>
                                    @endcan
                                @endif
                            @endif
                            @if ($taskState === \App\Enums\TaskState::Submitted)
                                @can('startReview', $task)
                                    <button type="button" class="btn-small btn-primary execution-transition-btn"
                                        data-task-version="{{ $task->lock_version }}" data-transition="review" data-url="{{ route('tasks.review.start', $task) }}">Start Review</button>
                                @endcan
                            @endif
                            @if (! $taskState->isFinal())
                                @can('cancel', $task)
                                    <button type="button" class="btn-small btn-danger execution-transition-btn"
                                        data-task-version="{{ $task->lock_version }}" data-transition="cancel" data-url="{{ route('tasks.cancel', $task) }}">Cancel Task</button>
                                @endcan
                            @endif
                            @if ($taskState === \App\Enums\TaskState::Cancelled)
                                <span>Cancelled {{ $task->cancelled_at?->format('m/d/Y H:i') }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="pagination-wrapper">
        {{ $tasks->onEachSide(1)->links() }}
    </div>
    <!-- History Button at the bottom -->
    <div style="margin: 3rem auto 0 auto; text-align: center;">
        <a href="{{ route('completed-tasks') }}" class="btn-primary" style="padding: 0.7rem 2.5rem; font-size: 1.1rem; border-radius: 8px;">History</a>
    </div>
    <!-- Task Form Modal -->
    <div id="taskModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Create New Task</h3>
                <button class="modal-close">&times;</button>
            </div>
            <form id="taskForm" class="modal-form">
                <div class="form-group">
                    <label for="taskTitle">Task Title *</label>
                    <input type="text" id="taskTitle" name="taskTitle" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="taskPriority">Priority *</label>
                        <select id="taskPriority" name="taskPriority" required>
                            <option value="High">High</option>
                            <option value="Medium" selected>Medium</option>
                            <option value="Low">Low</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label for="taskDescription">Task Description *</label>
                    <textarea id="taskDescription" name="taskDescription" rows="3" placeholder="Describe the task..." required maxlength="20000"></textarea>
                </div>
                <div class="form-group">
                    <label for="taskProject">Project *</label>
                    <select id="taskProject" name="taskProject" required>
                        <option value="">Select Project</option>
                        @foreach ($projects as $project)
                            <option value="{{ $project->id }}">{{ $project->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="taskAssignee">Assign To *</label>
                        <select id="taskAssignee" name="taskAssignee" required>
                            <option value="">Select Team Member</option>
                        </select>
                        <small class="form-help">
                            Active staff who are not yet members can be explicitly added to the selected project when you assign the task.
                        </small>
                    </div>
                    <div class="form-group">
                        <label>Initial State</label>
                        <p class="form-help">New tasks always begin as Not Started.</p>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="taskReviewer">Reviewer *</label>
                        <select id="taskReviewer" name="taskReviewer" required>
                            <option value="">Select Reviewer</option>
                        </select>
                    </div>
                    <p class="form-help">The review deadline is set when work is submitted for review.</p>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="taskStartDate">Start Date *</label>
                        <input type="date" id="taskStartDate" name="taskStartDate" required value="{{ old('taskStartDate') ?? now()->format('Y-m-d') }}">
                    </div>
                    <div class="form-group">
                        <label for="taskDueDate">Due Date *</label>
                        <input type="date" id="taskDueDate" name="taskDueDate" required>
                    </div>
                </div>
                <div class="form-group">
                    <label for="taskComments">Comments</label>
                    <textarea id="taskComments" name="taskComments" rows="3" placeholder="Add any additional comments..." maxlength="20000"></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-secondary" onclick="taskModal.classList.remove('active')">Cancel</button>
                    <button type="submit" class="btn-primary" id="submitBtn">➕ Create Task</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
