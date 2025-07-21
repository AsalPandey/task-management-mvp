@extends('layouts.app')

@push('styles')
<link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
<link rel="stylesheet" href="{{ asset('css/common.css') }}">
@endpush

@push('scripts')
<script>
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

    if (newTaskBtn && taskModal) {
        newTaskBtn.addEventListener('click', function() {
            taskForm.reset();
            progressValue.textContent = '0';
            document.getElementById('taskStartDate').value = new Date().toISOString().split('T')[0];
            taskModal.classList.add('active');
        });
    }
    if (modalCloses) {
        modalCloses.forEach(close => {
            close.addEventListener('click', function() {
                taskModal.classList.remove('active');
            });
        });
    }
    if (taskModal) {
        taskModal.addEventListener('click', function(e) {
            if (e.target === this) {
                taskModal.classList.remove('active');
            }
        });
    }
    if (progressSlider && progressValue) {
        progressSlider.addEventListener('input', function() {
            progressValue.textContent = this.value;
        });
    }
});
</script>
@endpush

@section('content')
<div class="app-container">
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
                    <div class="metric-value">{{ $tasks->count() }}</div>
                    <div class="metric-label">Total Tasks</div>
                    <div class="metric-subtitle">Across all projects</div>
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
                    <div class="metric-value">{{ $tasks->where('status', 'Completed')->count() }}</div>
                    <div class="metric-label">Completed</div>
                    <div class="metric-subtitle">{{ $progress }}% completion rate</div>
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
                    <div class="metric-value">{{ $tasks->where('status', 'In Progress')->count() }}</div>
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
                    <div class="metric-value">{{ $tasks->where('due_date', '<', now())->where('status', '!=', 'Completed')->count() }}</div>
                    <div class="metric-label">Overdue</div>
                    <div class="metric-subtitle">Needs attention</div>
                </div>
            </div>
            <div class="progress-card">
                <div class="progress-header">
                    <h3>Overall Team Progress</h3>
                    <span class="progress-percentage">{{ $progress }}%</span>
                </div>
                <div class="progress-bar-container">
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: {{ $progress }}%"></div>
                    </div>
                </div>
                <p id="progressDescription">Average completion across {{ $tasks->count() }} tasks</p>
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
                                    <div class="status-fill" style="width: {{ $tasks->count() ? round($count / $tasks->count() * 100) : 0 }}%"></div>
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
                                    <div class="priority-fill" style="width: {{ $tasks->count() ? round($count / $tasks->count() * 100) : 0 }}%"></div>
                                </div>
                                <span class="priority-count">{{ $count }}</span>
                            </div>
                        @empty
                            <div>No data</div>
                        @endforelse
                    </div>
                </div>
                <div class="chart-card">
                    <h3>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z" stroke="currentColor" stroke-width="2"/>
                        </svg>
                        Active Projects
                    </h3>
                    <div class="projects-list" id="projectsList">
                        @forelse ($activeProjects as $project)
                            <div class="project-list-item">
                                <span class="project-name">{{ $project->name }}</span>
                                <span class="project-progress">{{ $project->tasks->count() }} tasks</span>
                            </div>
                        @empty
                            <div>No active projects</div>
                        @endforelse
                    </div>
                </div>
            </div>

            <!-- Team Workload and Overdue Tasks -->
            <div class="bottom-grid">
                <div class="workload-card">
                    <h3>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2" stroke="currentColor" stroke-width="2"/>
                            <circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="2"/>
                            <path d="M23 21v-2a4 4 0 00-3-3.87" stroke="currentColor" stroke-width="2"/>
                            <path d="M16 3.13a4 4 0 010 7.75" stroke="currentColor" stroke-width="2"/>
                        </svg>
                        Team Workload
                    </h3>
                    <div class="team-workload" id="teamWorkload">
                        @forelse ($teamWorkload as $assignee => $count)
                            <div class="workload-item">
                                <span class="assignee-label">{{ $assignee }}</span>
                                <div class="workload-bar">
                                    <div class="workload-fill" style="width: {{ $tasks->count() ? round($count / $tasks->count() * 100) : 0 }}%"></div>
                                </div>
                                <span class="workload-count">{{ $count }}</span>
                            </div>
                        @empty
                            <div>No workload data</div>
                        @endforelse
                    </div>
                </div>
                <div class="overdue-card">
                    <h3>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
                            <polyline points="12,6 12,12 16,14" stroke="currentColor" stroke-width="2"/>
                        </svg>
                        Overdue Tasks (<span id="overdueCount">{{ $overdueTasks->count() }}</span>)
                    </h3>
                    <div class="overdue-tasks" id="overdueTasks">
                        @forelse ($overdueTasks as $task)
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
                            <span>{!! $note['text'] !!}</span>
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
            <div id="projects" class="tab-content">
                <div class="loading">
                    <div class="loading-spinner"></div>
                    Loading projects...
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
                            @foreach (\App\Models\User::all() as $user)
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