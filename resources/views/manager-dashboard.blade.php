@extends('layouts.app')

@push('styles')
<link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
<link rel="stylesheet" href="{{ asset('css/common.css') }}">
<style>
body { background: #f7f8fa; }
.app-container { max-width: 1100px; margin: 0 auto; padding: 2rem 1rem; background: #fff; border-radius: 16px; box-shadow: 0 2px 16px 0 rgba(60,72,88,0.05); }
.page-header h2 { font-size: 1.7rem; font-weight: 700; color: #22223b; margin-bottom: 0.2rem; }
.page-header p { color: #888; font-size: 1.05rem; }
.metrics-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.2rem; margin-bottom: 2rem; }
.metric-card { background: #f8fafc; border-radius: 12px; box-shadow: none; padding: 1.1rem 1rem; display: flex; flex-direction: column; align-items: center; min-width: 0; }
.metric-icon { font-size: 1.5rem; margin-bottom: 0.4rem; }
.metric-value { font-size: 1.7rem; font-weight: 600; color: #22223b; }
.metric-label { font-size: 1rem; color: #6c757d; margin-bottom: 0.2rem; }
.charts-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.2rem; margin-bottom: 2rem; }
.chart-card { background: #f8fafc; border-radius: 12px; padding: 1rem 1rem 0.5rem 1rem; box-shadow: none; min-width: 0; }
.bottom-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.2rem; margin-bottom: 2rem; }
.workload-card, .overdue-card { background: #f8fafc; border-radius: 12px; box-shadow: none; padding: 1.2rem 1rem; }
.notifications-card, .insights-card { background: #f8fafc; border-radius: 12px; box-shadow: none; padding: 1.2rem 1rem; margin-bottom: 2rem; }
.insights-card h3 { font-size: 1.1rem; font-weight: 600; color: #22223b; margin-bottom: 0.7rem; }
.insights-grid { display: flex; gap: 2rem; }
.insight-section { flex: 1; }
.insight-section h4 { font-size: 1rem; color: #4f8cff; margin-bottom: 0.5rem; }
.insight-section ul { padding-left: 1.1rem; color: #555; font-size: 0.97rem; }
@media (max-width: 900px) { .metrics-grid, .charts-grid, .bottom-grid { grid-template-columns: 1fr 1fr; } }
@media (max-width: 600px) { .metrics-grid, .charts-grid, .bottom-grid { grid-template-columns: 1fr; } .app-container { padding: 1rem 0.2rem; } .insights-grid { flex-direction: column; gap: 1rem; } }
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
    // Tab switching logic
    const navTabs = document.querySelectorAll('.nav-tab');
    const tabContents = document.querySelectorAll('.tab-content');
    navTabs.forEach(tab => {
        tab.addEventListener('click', function() {
            // Remove active from all tabs
            navTabs.forEach(t => t.classList.remove('active'));
            // Hide all tab contents
            tabContents.forEach(tc => tc.classList.remove('active'));
            // Set active tab
            this.classList.add('active');
            const tab = this.getAttribute('data-tab');
            const content = document.getElementById(tab);
            if (content) content.classList.add('active');
        });
    });

    // Modal logic (existing)
    const newTaskBtn = document.getElementById('newTaskBtn');
    const taskModal = document.getElementById('taskModal');
    const taskForm = document.getElementById('taskForm');
    const modalCloses = document.querySelectorAll('.modal-close');
    const progressSlider = document.getElementById('taskProgress');
    const progressValue = document.getElementById('progressValue');
    const messageContainer = document.getElementById('messageContainer');
    const taskProjectSelect = document.getElementById('taskProject');
    const taskAssigneeSelect = document.getElementById('taskAssignee');
    const projectMembers = window.projectMembers || {};

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
            submitBtn.textContent = 'Create Task';
        }
    }
    function resetModal() {
        taskForm.reset();
        setLoading(false);
        if (progressValue) progressValue.textContent = '0';
    }

    if (newTaskBtn && taskModal) {
        newTaskBtn.addEventListener('click', function() {
            resetModal();
            document.getElementById('taskStartDate').value = new Date().toISOString().split('T')[0];
            if (taskProjectSelect) {
                populateAssignees(taskProjectSelect.value);
            }
            taskModal.classList.add('active');
        });
    }
    if (modalCloses) {
        modalCloses.forEach(close => {
            close.addEventListener('click', function() {
                taskModal.classList.remove('active');
                resetModal();
            });
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
    // Add submit handler for feedback (AJAX example, adapt as needed)
    if (taskForm) {
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
            fetch('/tasks', {
                method: 'POST',
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
                if (data.task) {
                    showMessage('Task created successfully!');
                    taskModal.classList.remove('active');
                    resetModal();
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
    }

    // AJAX: Delete task (card view)
    document.querySelectorAll('.task-card .delete-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
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
                this.disabled = true;
                this.textContent = '⏳';
                fetch(`/tasks/${id}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
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
                        this.disabled = false;
                        this.textContent = '🗑️';
                    } else {
                        Swal.fire('Error', 'Error deleting task.', 'error');
                        this.disabled = false;
                        this.textContent = '🗑️';
                    }
                })
                .catch(error => {
                    Swal.fire('Error', 'Error deleting task: ' + (error.message || error), 'error');
                    this.disabled = false;
                    this.textContent = '🗑️';
                });
            });
        });
    });
    // AJAX: Delete task (table view)
    document.querySelectorAll('.table-delete-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
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
                const row = this.closest('tr');
                const id = row.dataset.taskId;
                this.disabled = true;
                this.textContent = '⏳';
                fetch(`/tasks/${id}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        row.remove();
                        Swal.fire('Deleted!', 'Task deleted.', 'success');
                    } else if (data.message) {
                        Swal.fire('Error', data.message, 'error');
                        this.disabled = false;
                        this.textContent = '🗑️';
                    } else {
                        Swal.fire('Error', 'Error deleting task.', 'error');
                        this.disabled = false;
                        this.textContent = '🗑️';
                    }
                })
                .catch(error => {
                    Swal.fire('Error', 'Error deleting task: ' + (error.message || error), 'error');
                    this.disabled = false;
                    this.textContent = '🗑️';
                });
            });
        });
    });

});
</script>
@endpush

@section('content')
<div class="app-container">

    <div id="messageContainer" class="message-container" style="display: none;"></div>
    <main class="main-content">
        <div id="dashboard" class="tab-content active">
            <div class="page-header">
                <div>
                    <h2>Manager Dashboard</h2>
                    <p>Complete overview of team performance and project status</p>
                </div>
                <div class="header-meta">
                    <div class="last-updated">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2" stroke="currentColor" stroke-width="2"/>
                            <line x1="16" y1="2" x2="16" y2="6" stroke="currentColor" stroke-width="2"/>
                            <line x1="8" y1="2" x2="8" y2="6" stroke="currentColor" stroke-width="2"/>
                            <line x1="3" y1="10" x2="21" y2="10" stroke="currentColor" stroke-width="2"/>
                        </svg>
                        Last updated: <span>{{ $recentTasks->first() ? $recentTasks->first()->updated_at->format('M d, Y H:i') : 'Never' }}</span>
                    </div>
                </div>
                <a href="{{ route('completed-tasks') }}" class="btn-primary" style="margin-top:1rem; display:inline-block;">View Task History</a>
            </div>
            <div class="metrics-grid">
                <div class="metric-card blue">
                    <div class="metric-header">
                        <div class="metric-icon">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <rect x="3" y="3" width="7" height="7" stroke="currentColor" stroke-width="2"/>
                                <rect x="14" y="3" width="7" height="7" stroke="currentColor" stroke-width="2"/>
                                <rect x="14" y="14" width="7" height="7" stroke="currentColor" stroke-width="2"/>
                                <rect x="3" y="14" width="7" height="7" stroke="currentColor" stroke-width="2"/>
                            </svg>
                        </div>
                        <div class="metric-trend">+12%</div>
                    </div>
                    <div class="metric-value">{{ $currentActiveCount }}</div>
                    <div class="metric-label">Active Tasks</div>
                    <div class="metric-subtitle">Currently Active</div>
                </div>
                <div class="metric-card green">
                    <div class="metric-header">
                        <div class="metric-icon">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <div class="metric-trend">+8%</div>
                    </div>
                    <div class="metric-value">{{ $todayCompletedCount }}</div>
                    <div class="metric-label">Completed Today</div>
                    <div class="metric-subtitle">{{ $todayProgress }}% today's progress</div>
                </div>
                <div class="metric-card purple">
                    <div class="metric-header">
                        <div class="metric-icon">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <polyline points="22,12 18,12 15,21 9,3 6,12 2,12" stroke="currentColor" stroke-width="2"/>
                            </svg>
                        </div>
                        <div class="metric-trend">+5%</div>
                    </div>
                    <div class="metric-value">{{ $statusCounts['In Progress'] ?? 0 }}</div>
                    <div class="metric-label">In Progress</div>
                    <div class="metric-subtitle">Active development</div>
                </div>
                <div class="metric-card red">
                    <div class="metric-header">
                        <div class="metric-icon">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
                                <line x1="15" y1="9" x2="9" y2="15" stroke="currentColor" stroke-width="2"/>
                                <line x1="9" y1="9" x2="15" y2="15" stroke="currentColor" stroke-width="2"/>
                            </svg>
                        </div>
                        <div class="metric-trend">-2%</div>
                    </div>
                    <div class="metric-value">{{ $todayOverdueTasks->count() }}</div>
                    <div class="metric-label">Overdue</div>
                    <div class="metric-subtitle">Needs attention</div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="charts-grid">
                <div class="chart-card">
                    <h3>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <rect x="3" y="3" width="7" height="7" stroke="currentColor" stroke-width="2"/>
                            <rect x="14" y="3" width="7" height="7" stroke="currentColor" stroke-width="2"/>
                            <rect x="14" y="14" width="7" height="7" stroke="currentColor" stroke-width="2"/>
                            <rect x="3" y="14" width="7" height="7" stroke="currentColor" stroke-width="2"/>
                        </svg>
                        Tasks by Status
                    </h3>
                    <div class="status-chart" id="statusChart">
                        @forelse ($statusCounts as $status => $count)
                            <div class="status-item">
                                <span class="status-label">{{ $status }}</span>
                                <div class="status-bar">
                                    <div class="status-fill" style="width: {{ $currentActiveCount ? round($count / $currentActiveCount * 100) : 0 }}%"></div>
                                </div>
                                <span class="status-count">{{ $count }}</span>
                            </div>
                        @empty
                            <div>No data</div>
                        @endforelse
                    </div>
                </div>
                <div class="chart-card">
                    <h3>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
                            <line x1="15" y1="9" x2="9" y2="15" stroke="currentColor" stroke-width="2"/>
                            <line x1="9" y1="9" x2="15" y2="15" stroke="currentColor" stroke-width="2"/>
                        </svg>
                        Tasks by Priority
                    </h3>
                    <div class="priority-chart" id="priorityChart">
                        @forelse ($priorityCounts as $priority => $count)
                            <div class="priority-item">
                                <span class="priority-label">{{ $priority }}</span>
                                <div class="priority-bar">
                                    <div class="priority-fill" style="width: {{ $currentActiveCount ? round($count / $currentActiveCount * 100) : 0 }}%"></div>
                                </div>
                                <span class="priority-count">{{ $count }}</span>
                            </div>
                        @empty
                            <div>No data</div>
                        @endforelse
                    </div>
                </div>
            </div>

            <!-- Overdue Tasks -->
            <div class="overdue-card">
                <h3>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
                        <polyline points="12,6 12,12 16,14" stroke="currentColor" stroke-width="2"/>
                    </svg>
                    Overdue Tasks (<span id="overdueCount">{{ $todayOverdueTasks->count() }}</span>)
                </h3>
                <div class="overdue-tasks" id="overdueTasks">
                    @forelse ($todayOverdueTasks as $task)
                        <div class="overdue-task-item">
                            <span class="overdue-title">{{ $task->title }}</span>
                            <span class="overdue-assignee">{{ $task->assignee ? $task->assignee->name : '-' }}</span>
                            <span class="overdue-due">Due: {{ $task->due_date }}</span>
                        </div>
                    @empty
                        <div>No overdue tasks</div>
                    @endforelse
                </div>
            </div>

            <!-- Notifications -->
            <div class="notifications-card">
                <h3>🔔 Notifications</h3>
                <div class="notification-list">
                    @forelse($notifications as $note)
                        <div class="notification-item">
                            <span class="notification-icon">
                                @if($note['type'] === 'completed')✅@elseif($note['type'] === 'overdue')⏰@else📥@endif
                            </span>
                            <span>{{ $note['text'] }}</span>
                        </div>
                    @empty
                        <div class="notification-item">No recent notifications.</div>
                    @endforelse
                </div>
            </div>

            <!-- Performance Insights -->
            <div class="insights-card">
                <h3>✨ Performance Insights</h3>
                <div class="insights-grid">
                    <div class="insight-section">
                        <h4>Key Achievements</h4>
                        <div class="achievements" id="achievements">
                            @foreach ($achievements as $item)
                                <div class="achievement-item">{{ $item }}</div>
                            @endforeach
                        </div>
                    </div>
                    <div class="insight-section">
                        <h4>Areas for Improvement</h4>
                        <div class="improvements" id="improvements">
                            @foreach ($improvements as $item)
                                <div class="improvement-item">{{ $item }}</div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <!-- Other tabs content will be loaded dynamically -->
            <div id="tasks" class="tab-content">
                <div class="loading">
                    <div class="loading-spinner"></div>
                    Loading tasks...
                </div>
            </div>
            <div id="analytics" class="tab-content">
                <div class="loading">
                    <div class="loading-spinner"></div>
                    Loading analytics...
                </div>
            </div>
            <div id="team" class="tab-content">
                <div class="loading">
                    <div class="loading-spinner"></div>
                    Loading team...
                </div>
            </div>
            <div id="settings" class="tab-content">
                <div class="loading">
                    <div class="loading-spinner"></div>
                    Loading settings...
                </div>
            </div>
            <!-- Place My Overall Progress here so it only shows on dashboard tab -->
            <div class="progress-card" style="margin: 3rem auto 0 auto; max-width: 600px;">
                <div class="progress-header">
                    <h3>My Overall Progress</h3>
                    <span class="progress-percentage">{{ $todayProgress }}%</span>
                </div>
                <div class="progress-bar-container">
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: {{ $todayProgress }}%"></div>
                    </div>
                </div>
                <p id="progressDescription">Average completion across {{ $currentActiveCount }} tasks</p>
            </div>
        </div>
    </main>
    <!-- Task Form Modal -->
    <div id="taskModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Create New Task</h3>
                <button class="modal-close">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <line x1="18" y1="6" x2="6" y2="18" stroke="currentColor" stroke-width="2"/>
                        <line x1="6" y1="6" x2="18" y2="18" stroke="currentColor" stroke-width="2"/>
                    </svg>
                </button>
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
                    <button type="button" class="btn-secondary" onclick="closeTaskModal()">Cancel</button>
                    <button type="submit" class="btn-primary" id="submitBtn">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <line x1="12" y1="5" x2="12" y2="19" stroke="currentColor" stroke-width="2"/>
                            <line x1="5" y1="12" x2="19" y2="12" stroke="currentColor" stroke-width="2"/>
                        </svg>
                        Create Task
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
