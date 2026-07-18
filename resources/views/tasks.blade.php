@extends('layouts.app')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
<link rel="stylesheet" href="{{ asset('css/tasks.css') }}">
<link rel="stylesheet" href="{{ asset('css/common.css') }}">
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
@endphp
<script>
window.projectMembers = @json($projectMembersForScript);

document.addEventListener('DOMContentLoaded', function() {
    // Modal logic
    const newTaskBtn = document.getElementById('newTaskBtn');
    const taskModal = document.getElementById('taskModal');
    const taskForm = document.getElementById('taskForm');
    const modalClose = taskModal ? taskModal.querySelector('.modal-close') : null;
    const messageContainer = document.getElementById('messageContainer');
    const taskProjectSelect = document.getElementById('taskProject');
    const taskAssigneeSelect = document.getElementById('taskAssignee');
    const projectMembers = window.projectMembers || {};
    const canManageTasks = document.body.dataset.userRole !== 'team_member';
    let editTaskId = null;

    if (!canManageTasks) {
        newTaskBtn?.style.setProperty('display', 'none');
        document.querySelectorAll('.delete-btn, .table-delete-btn, #bulkActions, #selectAllTasks, .task-checkbox').forEach(el => {
            el.style.display = 'none';
        });
    }

    function populateAssignees(projectId, selectedId = '') {
        if (!taskAssigneeSelect) return;

        taskAssigneeSelect.innerHTML = '<option value="">Select Team Member</option>';
        (projectMembers[projectId] || []).forEach(member => {
            const option = document.createElement('option');
            option.value = member.id;
            option.textContent = member.name;
            if (String(member.id) === String(selectedId)) {
                option.selected = true;
            }
            taskAssigneeSelect.appendChild(option);
        });
    }

    function showMessage(msg, success = true) {
        Swal.fire({
            icon: success ? 'success' : 'error',
            title: success ? 'Success' : 'Error',
            text: msg,
            timer: 2000,
            showConfirmButton: false
        });
    }

    function setLoading(isLoading) {
        const submitBtn = document.getElementById('submitBtn');
        if (submitBtn) submitBtn.disabled = isLoading;
        if (isLoading) {
            submitBtn.textContent = '⏳ Please wait...';
        } else {
            submitBtn.textContent = editTaskId ? '✏️ Update Task' : '➕ Create Task';
        }
    }

    function resetModal() {
        taskForm.reset();
        editTaskId = null;
        setLoading(false);
        // Do not show any message on modal reset/close
    }

    if (newTaskBtn && taskModal) {
        newTaskBtn.addEventListener('click', function() {
            taskForm.reset();
            editTaskId = null;
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
            fetch(`/tasks/${taskId}/edit`, {
                headers: { 'Accept': 'application/json' }
            })
            .then(r => r.json())
            .then(data => {
                if (data.success && data.task) {
                    const task = data.task;
                    taskModal.classList.add('active');
                    document.getElementById('submitBtn').textContent = '✏️ Update Task';
                    document.getElementById('modalTitle').textContent = 'Edit Task';
                    taskForm.querySelector('#taskTitle').value = task.title || '';
                    taskForm.querySelector('#taskDescription').value = task.description || '';
                    taskForm.querySelector('#taskProject').value = task.project_id || '';
                    populateAssignees(task.project_id || '', task.assignee_id || '');
                    taskForm.querySelector('#taskPriority').value = task.priority || 'Medium';
                    taskForm.querySelector('#taskStatus').value = task.status || 'Not Started';
                    taskForm.querySelector('#taskStartDate').value = task.start_date || '';
                    taskForm.querySelector('#taskDueDate').value = task.due_date || '';
                    taskForm.querySelector('#taskProgress').value = task.progress || 0;
                    taskForm.querySelector('#progressValue').textContent = task.progress || 0;
                    taskForm.querySelector('#taskComments').value = task.comments || '';
                    editTaskId = task.id;
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
                fetch(`/tasks/${id}`, {
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
    taskForm.addEventListener('submit', function(e) {
        e.preventDefault();
        setLoading(true);
        const formData = new FormData(taskForm);
        const payload = {
            title: formData.get('taskTitle'),
            description: formData.get('taskDescription'),
            project_id: formData.get('taskProject'),
            assignee_id: formData.get('taskAssignee'),
            priority: formData.get('taskPriority'),
            status: formData.get('taskStatus'),
            progress: formData.get('taskProgress'),
            start_date: formData.get('taskStartDate'),
            due_date: formData.get('taskDueDate'),
            comments: formData.get('taskComments'),
        };
        const url = editTaskId ? `/tasks/${editTaskId}` : '/tasks';
        const method = editTaskId ? 'PUT' : 'POST';
        fetch(url, {
            method,
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'Accept': 'application/json',
            },
            body: JSON.stringify(payload),
        })
        .then(r => r.json())
        .then(data => {
            setLoading(false);
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
                    if (titleEl) titleEl.textContent = data.task.title;
                    const projectEl = card.querySelector('.task-project');
                    if (projectEl) projectEl.textContent = 'Project: ' + (data.task.project ? data.task.project.name : '-');
                    const priorityBadge = card.querySelector('.priority-badge');
                    if (priorityBadge) {
                        priorityBadge.textContent = data.task.priority;
                        priorityBadge.className = 'priority-badge priority-' + data.task.priority.toLowerCase();
                    }
                    const statusBadge = card.querySelector('.status-badge');
                    if (statusBadge) {
                        statusBadge.textContent = data.task.status;
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
        });
        populateAssignees(taskProjectSelect.value);
    }

    const tasksGrid = document.getElementById('tasksGrid');

    // Make task cards clickable
    document.querySelectorAll('.task-card').forEach(card => {
        card.addEventListener('click', function(e) {
            // Prevent triggering when clicking the delete button
            if (e.target.closest('.delete-btn')) return;
            const taskId = card.dataset.taskId;
            fetch(`/tasks/${taskId}/edit`, {
                headers: { 'Accept': 'application/json' }
            })
            .then(r => r.json())
            .then(data => {
                if (data.success && data.task) {
                    const task = data.task;
                    const taskModal = document.getElementById('taskModal');
                    const taskForm = document.getElementById('taskForm');
                    taskModal.classList.add('active');
                    document.getElementById('submitBtn').textContent = '✏️ Update Task';
                    document.getElementById('modalTitle').textContent = 'Edit Task';
                    taskForm.querySelector('#taskTitle').value = task.title || '';
                    taskForm.querySelector('#taskDescription').value = task.description || '';
                    taskForm.querySelector('#taskProject').value = task.project_id || '';
                    populateAssignees(task.project_id || '', task.assignee_id || '');
                    taskForm.querySelector('#taskPriority').value = task.priority || 'Medium';
                    taskForm.querySelector('#taskStatus').value = task.status || 'Not Started';
                    taskForm.querySelector('#taskStartDate').value = task.start_date || '';
                    taskForm.querySelector('#taskDueDate').value = task.due_date || '';
                    taskForm.querySelector('#taskProgress').value = task.progress || 0;
                    taskForm.querySelector('#progressValue').textContent = task.progress || 0;
                    taskForm.querySelector('#taskComments').value = task.comments || '';
                    editTaskId = task.id;
                } else {
                    showMessage('Could not fetch task data.', false);
                }
            })
            .catch(() => showMessage('Could not fetch task data.', false));
        });
    });
    // Toggle view logic
    const toggleViewBtn = document.getElementById('toggleViewBtn');
    const tasksTableWrapper = document.getElementById('tasksTableWrapper');
    let isTable = false;
    if (toggleViewBtn && tasksGrid && tasksTableWrapper) {
        toggleViewBtn.addEventListener('click', function() {
            isTable = !isTable;
            if (isTable) {
                tasksGrid.style.display = 'none';
                tasksTableWrapper.style.display = '';
                toggleViewBtn.textContent = '📋 Card View';
            } else {
                tasksGrid.style.display = '';
                tasksTableWrapper.style.display = 'none';
                toggleViewBtn.textContent = '🔳 Table View';
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
    const bulkCompleteBtn = document.getElementById('bulkCompleteBtn');

    function updateBulkActions() {
        const checkedCount = document.querySelectorAll('.task-checkbox:checked').length;
        selectAllTasksCheckbox.checked = taskCheckboxes.length > 0 && checkedCount === taskCheckboxes.length;
        bulkDeleteBtn.disabled = checkedCount === 0;
        bulkCompleteBtn.disabled = checkedCount === 0;
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
            fetch(`/tasks/bulk-delete`, {
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

    // Single task complete (card)
    document.querySelectorAll('.complete-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            Swal.fire({
                title: 'Mark this task as completed?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#aaa',
                confirmButtonText: 'Yes, complete it!'
            }).then((result) => {
                if (!result.isConfirmed) return;
                const card = this.closest('.task-card');
                const id = card.dataset.taskId;
                // Fetch the full task data first
                fetch(`/tasks/${id}/edit`, {
                    headers: { 'Accept': 'application/json' }
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.task) {
                        const task = data.task;
                        // Prepare full payload with status/progress overridden
                        const payload = {
                            title: task.title,
                            description: task.description,
                            project_id: task.project_id,
                            assignee_id: task.assignee_id,
                            priority: task.priority,
                            status: 'Completed',
                            progress: 100,
                            start_date: task.start_date,
                            due_date: task.due_date,
                            comments: task.comments
                        };
                        fetch(`/tasks/${id}`, {
                            method: 'PUT',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify(payload),
                        })
                        .then(r => r.json())
                        .then(data => {
                            if (data.success || (data.task && data.task.status === 'Completed')) {
                                card.remove();
                                Swal.fire('Completed!', 'Task marked as completed and moved to history.', 'success');
                            } else if (data.message) {
                                Swal.fire('Error', data.message, 'error');
                            } else {
                                Swal.fire('Error', 'Error marking task as completed.', 'error');
                            }
                        })
                        .catch(error => {
                            Swal.fire('Error', 'Error marking task as completed: ' + (error.message || error), 'error');
                        });
                    } else {
                        Swal.fire('Error', 'Could not fetch task data.', 'error');
                    }
                })
                .catch(error => {
                    Swal.fire('Error', 'Error fetching task data: ' + (error.message || error), 'error');
                });
            });
        });
    });
    // Single task complete (table)
    document.querySelectorAll('.table-complete-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            Swal.fire({
                title: 'Mark this task as completed?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#aaa',
                confirmButtonText: 'Yes, complete it!'
            }).then((result) => {
                if (!result.isConfirmed) return;
                const row = this.closest('tr');
                const id = row.dataset.taskId;
                const card = document.querySelector(`.task-card[data-task-id='${id}']`);
                fetch(`/tasks/${id}`, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ status: 'Completed', progress: 100 }),
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success || (data.task && data.task.status === 'Completed')) {
                        row.remove();
                        if (card) card.remove();
                        Swal.fire('Completed!', 'Task marked as completed and moved to history.', 'success');
                    } else if (data.message) {
                        Swal.fire('Error', data.message, 'error');
                    } else {
                        Swal.fire('Error', 'Error marking task as completed.', 'error');
                    }
                })
                .catch(error => {
                    Swal.fire('Error', 'Error marking task as completed: ' + (error.message || error), 'error');
            });
            });
    });
    });
    bulkCompleteBtn.addEventListener('click', function() {
        const selectedTaskIds = Array.from(taskCheckboxes).filter(checkbox => checkbox.checked).map(checkbox => checkbox.value);
        Swal.fire({
            title: 'Mark selected tasks as completed?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#aaa',
            confirmButtonText: 'Yes, complete them!'
        }).then((result) => {
            if (!result.isConfirmed) return;
            fetch(`/tasks/bulk-complete`, {
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
                    Swal.fire('Completed!', 'Tasks marked as completed and moved to history.', 'success');
                } else if (data.message) {
                    Swal.fire('Error', data.message, 'error');
                } else {
                    Swal.fire('Error', 'Error marking tasks as completed.', 'error');
                }
            })
            .catch(error => {
                Swal.fire('Error', 'Error marking tasks as completed: ' + (error.message || error), 'error');
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
                    @foreach (\App\Models\Task::STATUSES as $status)
                        <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $status }}</option>
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
            <div class="task-card" tabindex="0" style="cursor:pointer"
                data-task-id="{{ $task->id }}"
                data-assignee-id="{{ $task->assignee_id }}"
                data-project-id="{{ $task->project_id }}"
                data-priority="{{ $task->priority }}"
                data-status="{{ $task->status }}"
                data-start-date="{{ $task->start_date }}"
                data-due-date="{{ $task->due_date }}"
                data-progress="{{ $task->progress }}"
                data-description="{{ htmlspecialchars($task->description ?? '', ENT_QUOTES) }}"
                data-comments="{{ htmlspecialchars($task->comments ?? '', ENT_QUOTES) }}"
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
                        <span class="status-badge status-{{ str_replace(' ', '-', strtolower($task->status)) }}">{{ $task->status }}</span>
                        <button class="task-action-btn delete-btn">🗑️</button>
                    </div>
                </div>
                <h3 class="task-title">{{ $task->title }}</h3>
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
                        <span class="date-icon">📅</span>
                        <span class="due-date">{{ $task->due_date ? \Carbon\Carbon::parse($task->due_date)->format('m/d/Y') : '-' }}</span>
                    </div>
                    <div class="date-item">
                        <span class="date-icon">⏰</span>
                        <span>Updated {{ $task->updated_at ? $task->updated_at->format('m/d/Y') : '-' }}</span>
                    </div>
                </div>
                @if($task->comments)
                <div class="task-comments">
                    <p>{{ $task->comments }}</p>
                </div>
                @endif
                @if($task->status !== 'Completed' && $task->due_date && \Carbon\Carbon::parse($task->due_date)->isPast())
                <div class="overdue-warning">
                    <p>⚠️ Overdue</p>
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
        <button id="bulkCompleteBtn" class="btn-small btn-primary">✅ Mark Completed</button>
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
                    <tr data-task-id="{{ $task->id }}">
                        <td><input type="checkbox" class="task-checkbox" value="{{ $task->id }}"></td>
                        <td>{{ $task->title }}</td>
                        <td>{{ $task->project ? $task->project->name : '-' }}</td>
                        <td><span class="status-badge status-{{ str_replace(' ', '-', strtolower($task->status)) }}">{{ $task->status }}</span></td>
                        <td><span class="priority-badge priority-{{ strtolower($task->priority) }}">{{ $task->priority }}</span></td>
                        <td>
                            @if($user && $user->role && $user->role->name === 'team_member')
                                {{ $task->creator?->name ?? 'Manager' }}
                            @else
                                {{ $task->assignee ? $task->assignee->name : '-' }}
                            @endif
                        </td>
                        <td>{{ $task->due_date ? \Carbon\Carbon::parse($task->due_date)->format('m/d/Y') : '-' }}</td>
                        <td>{{ $task->progress }}%</td>
                        <td>
                            <button class="btn-small btn-primary table-edit-btn">✏️</button>
                            <button class="btn-small btn-danger table-delete-btn">🗑️</button>
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
                    <textarea id="taskDescription" name="taskDescription" rows="3" placeholder="Describe the task..." required></textarea>
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
                    </div>
                    <div class="form-group">
                        <label for="taskStatus">Status *</label>
                        <select id="taskStatus" name="taskStatus" required>
                            <option value="Not Started" selected>Not Started</option>
                            <option value="In Progress">In Progress</option>
                            <option value="Completed">Completed</option>
                            <option value="On Hold">On Hold</option>
                        </select>
                    </div>
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
                    <label for="taskProgress">Progress: <span id="progressValue">0</span>%</label>
                    <input type="range" id="taskProgress" name="taskProgress" min="0" max="100" value="0" step="10">
                    <div class="progress-markers">
                        <span>0%</span>
                        <span>25%</span>
                        <span>50%</span>
                        <span>75%</span>
                        <span>100%</span>
                    </div>
                </div>
                <div class="form-group">
                    <label for="taskComments">Comments</label>
                    <textarea id="taskComments" name="taskComments" rows="3" placeholder="Add any additional comments..."></textarea>
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
