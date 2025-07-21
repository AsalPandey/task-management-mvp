@extends('layouts.app')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
<link rel="stylesheet" href="{{ asset('css/tasks.css') }}">
<link rel="stylesheet" href="{{ asset('css/common.css') }}">
<style>
.tasks-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 1.5rem;
    background: #fff;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 1px 6px 0 rgba(60,72,88,0.06);
}
.tasks-table th, .tasks-table td {
    padding: 0.7rem 1rem;
    border-bottom: 1px solid #e5e7eb;
    text-align: left;
}
.tasks-table th {
    background: #f8fafc;
    font-weight: 600;
    color: #22223b;
}
.tasks-table tr:last-child td {
    border-bottom: none;
}
.btn-small {
    font-size: 0.95rem;
    padding: 0.2rem 0.7rem;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    margin-right: 0.3rem;
}
.btn-small.btn-primary { background: #4f8cff; color: #fff; }
.btn-small.btn-danger { background: #ff6b6b; color: #fff; }
</style>
@endpush
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Modal logic
    const newTaskBtn = document.getElementById('newTaskBtn');
    const taskModal = document.getElementById('taskModal');
    const taskForm = document.getElementById('taskForm');
    const modalClose = taskModal ? taskModal.querySelector('.modal-close') : null;
    const messageContainer = document.getElementById('messageContainer');
    let editTaskId = null;

    function showMessage(msg, success = true) {
        if (!messageContainer) return;
        messageContainer.textContent = msg;
        messageContainer.style.display = 'block';
        messageContainer.className = 'message-container ' + (success ? 'success' : 'error');
        setTimeout(() => { messageContainer.style.display = 'none'; }, 2000);
    }

    if (newTaskBtn && taskModal) {
        newTaskBtn.addEventListener('click', function() {
            taskForm.reset();
            editTaskId = null;
            taskModal.classList.add('active');
            document.getElementById('submitBtn').textContent = '➕ Create Task';
            document.getElementById('modalTitle').textContent = 'Create New Task';
        });
    }
    if (modalClose) {
        modalClose.addEventListener('click', function() {
            taskModal.classList.remove('active');
        });
    }
    if (taskModal) {
        taskModal.addEventListener('click', function(e) {
            if (e.target === this) {
                taskModal.classList.remove('active');
            }
        });
    }
    // Edit task
    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const card = this.closest('.task-card');
            editTaskId = card.dataset.taskId;
            taskModal.classList.add('active');
            document.getElementById('submitBtn').textContent = '✏️ Update Task';
            document.getElementById('modalTitle').textContent = 'Edit Task';
            taskForm.querySelector('#taskTitle').value = card.querySelector('.task-title').textContent;
            taskForm.querySelector('#taskDescription').value = card.querySelector('.task-comments p')?.textContent || '';
            taskForm.querySelector('#taskProject').value = card.dataset.projectId || '';
            taskForm.querySelector('#taskAssignee').value = card.dataset.assigneeId || '';
            taskForm.querySelector('#taskPriority').value = card.dataset.priority || 'Medium';
            taskForm.querySelector('#taskStatus').value = card.dataset.status || 'Not Started';
            taskForm.querySelector('#taskStartDate').value = card.dataset.startDate || '';
            taskForm.querySelector('#taskDueDate').value = card.dataset.dueDate || '';
            taskForm.querySelector('#taskProgress').value = card.dataset.progress || 0;
            taskForm.querySelector('#progressValue').textContent = card.dataset.progress || 0;
            taskForm.querySelector('#taskComments').value = card.querySelector('.task-comments p')?.textContent || '';
        });
    });
    // Delete task
    document.querySelectorAll('.delete-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const card = this.closest('.task-card');
            const id = card.dataset.taskId;
            if (confirm('Delete this task?')) {
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
                        showMessage('Task deleted.');
                    }
                });
            }
        });
    });
    // Create/update task
    taskForm.addEventListener('submit', function(e) {
        e.preventDefault();
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
            if (data.task && editTaskId) {
                showMessage('Task updated.');
                // Update the card in the DOM
                const card = document.querySelector(`.task-card[data-task-id='${editTaskId}']`);
                if (card) {
                    card.querySelector('.task-title').textContent = data.task.title;
                    card.querySelector('.priority-badge').textContent = data.task.priority;
                    card.querySelector('.priority-badge').className = 'priority-badge priority-' + data.task.priority.toLowerCase();
                    card.querySelector('.status-badge').textContent = data.task.status;
                    card.querySelector('.status-badge').className = 'status-badge status-' + data.task.status.toLowerCase().replace(/ /g, '-');
                    card.querySelector('.progress-header span:last-child').textContent = data.task.progress + '%';
                    card.querySelector('.progress-fill').style.width = data.task.progress + '%';
                    card.querySelector('.due-date').textContent = data.task.due_date ? new Date(data.task.due_date).toLocaleDateString() : '-';
                    card.querySelector('.date-item .date-icon + span').textContent = 'Updated ' + (data.task.updated_at ? new Date(data.task.updated_at).toLocaleDateString() : '-');
                    if (card.querySelector('.task-comments p')) {
                        card.querySelector('.task-comments p').textContent = data.task.comments || '';
                    }
                    card.dataset.priority = data.task.priority;
                    card.dataset.status = data.task.status;
                    card.dataset.progress = data.task.progress;
                    card.dataset.dueDate = data.task.due_date;
                    card.dataset.startDate = data.task.start_date;
                }
                // Update the table row in the DOM
                const row = document.querySelector(`tr[data-task-id='${editTaskId}']`);
                if (row) {
                    row.querySelector('td:nth-child(1)').textContent = data.task.title;
                    row.querySelector('td:nth-child(2) .status-badge').textContent = data.task.status;
                    row.querySelector('td:nth-child(2) .status-badge').className = 'status-badge status-' + data.task.status.toLowerCase().replace(/ /g, '-');
                    row.querySelector('td:nth-child(3) .priority-badge').textContent = data.task.priority;
                    row.querySelector('td:nth-child(3) .priority-badge').className = 'priority-badge priority-' + data.task.priority.toLowerCase();
                    // Assignee/Assigned By
                    const userRole = document.body.getAttribute('data-user-role');
                    if (userRole === 'team_member') {
                        row.querySelector('td:nth-child(4)').textContent = data.task.created_by_name || 'Manager';
                    } else {
                        row.querySelector('td:nth-child(4)').textContent = data.task.assignee ? data.task.assignee.name : '-';
                    }
                    row.querySelector('td:nth-child(5)').textContent = data.task.project ? data.task.project.name : '-';
                    row.querySelector('td:nth-child(6)').textContent = data.task.due_date ? new Date(data.task.due_date).toLocaleDateString() : '-';
                    row.querySelector('td:nth-child(7)').textContent = data.task.progress + '%';
                }
                taskModal.classList.remove('active');
                editTaskId = null;
            } else if (data.task) {
                showMessage('Task created.');
                window.location.reload();
            }
        });
        // Only close modal for update, not for create
        // taskModal.classList.remove('active');
    });
    // Progress slider
    const progressSlider = document.getElementById('taskProgress');
    const progressValue = document.getElementById('progressValue');
    if (progressSlider && progressValue) {
        progressSlider.addEventListener('input', function() {
            progressValue.textContent = this.value;
        });
    }

    // --- Task Filtering ---
    const searchInput = document.getElementById('searchTasks');
    const statusFilter = document.getElementById('statusFilter');
    const priorityFilter = document.getElementById('priorityFilter');
    const assigneeFilter = document.getElementById('assigneeFilter');
    const projectFilter = document.getElementById('projectFilter');
    const tasksGrid = document.getElementById('tasksGrid');

    function filterTasks() {
        const search = searchInput.value.toLowerCase();
        const status = statusFilter.value;
        const priority = priorityFilter.value;
        const assignee = assigneeFilter.value;
        const project = projectFilter.value;
        const cards = tasksGrid.querySelectorAll('.task-card');
        let anyVisible = false;
        cards.forEach(card => {
            const title = card.querySelector('.task-title').textContent.toLowerCase();
            const cardStatus = card.dataset.status;
            const cardPriority = card.dataset.priority;
            const cardAssignee = card.dataset.assigneeId;
            const cardProject = card.dataset.projectId;
            let visible = true;
            if (search && !title.includes(search)) visible = false;
            if (status && cardStatus !== status) visible = false;
            if (priority && cardPriority !== priority) visible = false;
            if (assignee && cardAssignee !== assignee) visible = false;
            if (project && cardProject !== project) visible = false;
            card.style.display = visible ? '' : 'none';
            if (visible) anyVisible = true;
        });
        // Table view filtering
        const rows = document.querySelectorAll('#tasksTableWrapper tbody tr');
        let anyTableVisible = false;
        rows.forEach(row => {
            const tds = row.querySelectorAll('td');
            const title = tds[0].textContent.toLowerCase();
            const rowStatus = tds[1].textContent.trim();
            const rowPriority = tds[2].textContent.trim();
            const rowAssignee = tds[3].textContent.trim();
            const rowProject = tds[4].textContent.trim();
            let visible = true;
            if (search && !title.includes(search)) visible = false;
            if (status && rowStatus !== status) visible = false;
            if (priority && rowPriority !== priority) visible = false;
            if (assignee && assignee !== '' && rowAssignee !== assigneeFilter.options[assigneeFilter.selectedIndex].text) visible = false;
            if (project && rowProject !== projectFilter.options[projectFilter.selectedIndex].text) visible = false;
            row.style.display = visible ? '' : 'none';
            if (visible) anyTableVisible = true;
        });
        // Show/hide empty state
        const emptyState = document.querySelector('.empty-state');
        if (emptyState) emptyState.style.display = anyVisible ? 'none' : '';
    }
    [searchInput, statusFilter, priorityFilter, assigneeFilter, projectFilter].forEach(el => {
        if (el) el.addEventListener('input', filterTasks);
        if (el && el.tagName === 'SELECT') el.addEventListener('change', filterTasks);
    });

    // Make task cards clickable
    document.querySelectorAll('.task-card').forEach(card => {
        card.addEventListener('click', function(e) {
            // Prevent triggering when clicking the delete button
            if (e.target.closest('.delete-btn')) return;
            // Set editTaskId globally so update works
            editTaskId = card.dataset.taskId;
            // Fallback: open modal and populate fields manually
            const taskModal = document.getElementById('taskModal');
            const taskForm = document.getElementById('taskForm');
            taskModal.classList.add('active');
            document.getElementById('submitBtn').textContent = '✏️ Update Task';
            document.getElementById('modalTitle').textContent = 'Edit Task';
            taskForm.querySelector('#taskTitle').value = card.querySelector('.task-title').textContent;
            taskForm.querySelector('#taskDescription').value = card.querySelector('.task-comments p')?.textContent || '';
            taskForm.querySelector('#taskProject').value = card.dataset.projectId || '';
            taskForm.querySelector('#taskAssignee').value = card.dataset.assigneeId || '';
            taskForm.querySelector('#taskPriority').value = card.dataset.priority || 'Medium';
            taskForm.querySelector('#taskStatus').value = card.dataset.status || 'Not Started';
            taskForm.querySelector('#taskStartDate').value = card.dataset.startDate || '';
            taskForm.querySelector('#taskDueDate').value = card.dataset.dueDate || '';
            taskForm.querySelector('#taskProgress').value = card.dataset.progress || 0;
            taskForm.querySelector('#progressValue').textContent = card.dataset.progress || 0;
            taskForm.querySelector('#taskComments').value = card.querySelector('.task-comments p')?.textContent || '';
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
        <div class="filters-grid">
            <div class="search-group">
                <div class="search-input">
                    <span class="search-icon">🔍</span>
                    <input type="text" id="searchTasks" name="searchTasks" placeholder="Search tasks...">
                </div>
            </div>
            <select id="statusFilter" name="statusFilter">
                <option value="">All Status</option>
                <option value="Not Started">Not Started</option>
                <option value="In Progress">In Progress</option>
                <option value="Completed">Completed</option>
                <option value="On Hold">On Hold</option>
            </select>
            <select id="priorityFilter" name="priorityFilter">
                <option value="">All Priority</option>
                <option value="High">High</option>
                <option value="Medium">Medium</option>
                <option value="Low">Low</option>
            </select>
            <select id="assigneeFilter" name="assigneeFilter">
                <option value="">All Assignees</option>
                @foreach ($assignees as $user)
                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                @endforeach
            </select>
            <select id="projectFilter" name="projectFilter">
                <option value="">All Projects</option>
                @foreach ($projects as $project)
                    <option value="{{ $project->id }}">{{ $project->name }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <!-- Tasks Grid (Card View) -->
    <div id="tasksGrid" class="tasks-grid">
        @forelse ($tasks as $task)
            <div class="task-card" tabindex="0" style="cursor:pointer" data-task-id="{{ $task->id }}" data-project-id="{{ $task->project_id }}" data-assignee-id="{{ $task->assignee_id }}" data-priority="{{ $task->priority }}" data-status="{{ $task->status }}" data-start-date="{{ $task->start_date }}" data-due-date="{{ $task->due_date }}" data-progress="{{ $task->progress }}">
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
                <div class="task-project">
                    <span class="project-icon">👤</span>
                    <span>{{ $task->project ? $task->project->name : '-' }}</span>
                </div>
                <div class="task-details">
                    @php $user = auth()->user(); @endphp
                    @if($user && $user->role && $user->role->name === 'team_member')
                        <span class="task-assigned-by">Assigned by: {{ $task->created_by ? ($assignees->find($task->created_by)->name ?? 'Manager') : 'Manager' }}</span>
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
                <h3>No tasks found</h3>
                <p>Create your first task to get started!</p>
            </div>
        @endforelse
    </div>
    <!-- Tasks Table View (hidden by default) -->
    <div id="tasksTableWrapper" style="display:none;">
        <table class="tasks-table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Status</th>
                    <th>Priority</th>
                    <th>@php $user = auth()->user(); @endphp
                        @if($user && $user->role && $user->role->name === 'team_member') Assigned By @else Assignee @endif</th>
                    <th>Project</th>
                    <th>Due Date</th>
                    <th>Progress</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($tasks as $task)
                    <tr data-task-id="{{ $task->id }}">
                        <td>{{ $task->title }}</td>
                        <td><span class="status-badge status-{{ str_replace(' ', '-', strtolower($task->status)) }}">{{ $task->status }}</span></td>
                        <td><span class="priority-badge priority-{{ strtolower($task->priority) }}">{{ $task->priority }}</span></td>
                        <td>
                            @if($user && $user->role && $user->role->name === 'team_member')
                                {{ $task->created_by ? ($assignees->find($task->created_by)->name ?? 'Manager') : 'Manager' }}
                            @else
                                {{ $task->assignee ? $task->assignee->name : '-' }}
                            @endif
                        </td>
                        <td>{{ $task->project ? $task->project->name : '-' }}</td>
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
                        <label for="taskProject">Project *</label>
                        <select id="taskProject" name="taskProject" required>
                            <option value="">Select Project</option>
                            @foreach ($projects as $project)
                                <option value="{{ $project->id }}">{{ $project->name }}</option>
                            @endforeach
                        </select>
                    </div>
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
                <div class="form-row">
                    <div class="form-group">
                        <label for="taskAssignee">Assign To *</label>
                        <select id="taskAssignee" name="taskAssignee" required>
                            <option value="">Select Team Member</option>
                            @foreach ($assignees as $user)
                                <option value="{{ $user->id }}">{{ $user->name }}</option>
                            @endforeach
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
                        <input type="date" id="taskStartDate" name="taskStartDate" required>
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