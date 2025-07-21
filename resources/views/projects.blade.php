@extends('layouts.app')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
<link rel="stylesheet" href="{{ asset('css/projects.css') }}">
<link rel="stylesheet" href="{{ asset('css/common.css') }}">
<style>
.projects-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 1.5rem;
    background: #fff;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 1px 6px 0 rgba(60,72,88,0.06);
}
.projects-table th, .projects-table td {
    padding: 0.7rem 1rem;
    border-bottom: 1px solid #e5e7eb;
    text-align: left;
}
.projects-table th {
    background: #f8fafc;
    font-weight: 600;
    color: #22223b;
}
.projects-table tr:last-child td {
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
    const newProjectBtn = document.getElementById('newProjectBtn');
    const projectModal = document.getElementById('projectModal');
    const projectForm = document.getElementById('projectForm');
    const modalClose = projectModal ? projectModal.querySelector('.modal-close') : null;
    const messageContainer = document.getElementById('messageContainer');
    let editProjectId = null;

    function showMessage(msg, success = true) {
        if (!messageContainer) return;
        messageContainer.textContent = msg;
        messageContainer.style.display = 'block';
        messageContainer.className = 'message-container ' + (success ? 'success' : 'error');
        setTimeout(() => { messageContainer.style.display = 'none'; }, 2000);
    }

    if (newProjectBtn && projectModal) {
        newProjectBtn.addEventListener('click', function() {
            projectForm.reset();
            editProjectId = null;
            projectModal.classList.add('active');
            document.getElementById('submitBtn').textContent = '➕ Create Project';
            document.getElementById('modalTitle').textContent = 'Create New Project';
        });
    }
    if (modalClose) {
        modalClose.addEventListener('click', function() {
            projectModal.classList.remove('active');
        });
    }
    if (projectModal) {
        projectModal.addEventListener('click', function(e) {
            if (e.target === this) {
                projectModal.classList.remove('active');
            }
        });
    }
    // Color picker logic
    const colorOptions = document.querySelectorAll('.color-option');
    const projectColorInput = document.getElementById('projectColor');
    colorOptions.forEach(option => {
        option.addEventListener('click', function() {
            colorOptions.forEach(o => o.classList.remove('selected'));
            this.classList.add('selected');
            if (projectColorInput) projectColorInput.value = this.dataset.color;
        });
    });

    // Edit project
    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const card = this.closest('.project-card');
            editProjectId = card.dataset.projectId;
            projectModal.classList.add('active');
            document.getElementById('submitBtn').textContent = '✏️ Update Project';
            document.getElementById('modalTitle').textContent = 'Edit Project';
            document.getElementById('projectName').value = card.querySelector('h3').textContent;
            document.getElementById('projectDescription').value = card.querySelector('p').textContent;
            document.getElementById('projectStatus').value = card.querySelector('.status-badge').textContent.trim();
            const color = card.style.borderLeftColor || '#3B82F6';
            document.getElementById('projectColor').value = color;
            colorOptions.forEach(o => o.classList.toggle('selected', o.dataset.color === color));
        });
    });
    // Delete project
    document.querySelectorAll('.delete-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const card = this.closest('.project-card');
            const id = card.dataset.projectId;
            if (confirm('Delete this project?')) {
                fetch(`/projects/${id}`, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                       'Accept': 'application/json',
                    },
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        card.remove();
                        showMessage('Project deleted.');
                    }
                });
            }
        });
    });
    // Create/update project
    projectForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(projectForm);
        const payload = {
            name: formData.get('projectName'),
            description: formData.get('projectDescription'),
            color: formData.get('projectColor'),
            status: formData.get('projectStatus'),
        };
        // Debug output
        console.log('Project payload:', payload);
        // Simple validation before sending
        if (!payload.name || !payload.status) {
            showMessage('Project name and status are required.', false);
            return;
        }
        const url = editProjectId ? `/projects/${editProjectId}` : '/projects';
        const method = editProjectId ? 'PUT' : 'POST';
        fetch(url, {
            method,
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'Accept': 'application/json',
            },
            body: JSON.stringify(payload),
        })
        .then(async r => {
            const text = await r.text();
            try {
                return JSON.parse(text);
            } catch {
                showMessage('Server error: ' + text, false);
                throw new Error('Server error: ' + text);
            }
        })
        .then(data => {
            if (data && data.id) {
                showMessage(editProjectId ? 'Project updated.' : 'Project created.');
                // Real-time update for edit
                if (editProjectId) {
                    const card = document.querySelector(`.project-card[data-project-id='${editProjectId}']`);
                    const row = document.querySelector(`tr[data-project-id='${editProjectId}']`);
                    if (card) {
                        card.querySelector('h3').textContent = data.name;
                        card.querySelector('p').textContent = data.description;
                        card.querySelector('.status-badge').textContent = data.status;
                        card.querySelector('.status-badge').className = 'status-badge status-' + data.status.toLowerCase();
                        card.style.borderLeftColor = data.color || '#3B82F6';
                    }
                    if (row) {
                        row.querySelector('td:nth-child(1)').textContent = data.name;
                        row.querySelector('td:nth-child(2) .status-badge').textContent = data.status;
                        row.querySelector('td:nth-child(2) .status-badge').className = 'status-badge status-' + data.status.toLowerCase();
                        // Progress, tasks, done, overdue, created can be updated if returned in response
                    }
                    projectModal.classList.remove('active');
                    editProjectId = null;
                } else {
                    window.location.reload();
                }
            } else if (data && data.error) {
                showMessage(data.error, false);
            }
        })
        .catch(err => {
            showMessage('Unexpected error: ' + err.message, false);
        });
        projectModal.classList.remove('active');
    });
    // Toggle view logic
    const toggleProjectViewBtn = document.getElementById('toggleProjectViewBtn');
    const projectsGrid = document.getElementById('projectsGrid');
    const projectsTableWrapper = document.getElementById('projectsTableWrapper');
    let isTable = false;
    toggleProjectViewBtn.addEventListener('click', function() {
        isTable = !isTable;
        if (isTable) {
            projectsGrid.style.display = 'none';
            projectsTableWrapper.style.display = '';
            toggleProjectViewBtn.textContent = '📋 Card View';
        } else {
            projectsGrid.style.display = '';
            projectsTableWrapper.style.display = 'none';
            toggleProjectViewBtn.textContent = '🔳 Table View';
        }
    });
    // Table edit/delete
    document.querySelectorAll('.table-edit-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            const row = this.closest('tr');
            const card = document.querySelector(`.project-card[data-project-id='${row.dataset.projectId}']`);
            if (card) card.querySelector('.edit-btn').click();
        });
    });
    document.querySelectorAll('.table-delete-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            const row = this.closest('tr');
            const card = document.querySelector(`.project-card[data-project-id='${row.dataset.projectId}']`);
            if (card) card.querySelector('.delete-btn').click();
        });
    });
    // Filtering logic
    const searchInput = document.getElementById('searchProjects');
    const statusFilter = document.getElementById('statusProjectFilter');
    function filterProjects() {
        const search = searchInput.value.toLowerCase();
        const status = statusFilter.value;
        // Card view
        document.querySelectorAll('.project-card').forEach(card => {
            const name = card.querySelector('h3').textContent.toLowerCase();
            const cardStatus = card.dataset.status;
            let visible = true;
            if (search && !name.includes(search)) visible = false;
            if (status && cardStatus !== status) visible = false;
            card.style.display = visible ? '' : 'none';
        });
        // Table view
        document.querySelectorAll('#projectsTableWrapper tbody tr').forEach(row => {
            const name = row.querySelector('td:nth-child(1)').textContent.toLowerCase();
            const rowStatus = row.dataset.status;
            let visible = true;
            if (search && !name.includes(search)) visible = false;
            if (status && rowStatus !== status) visible = false;
            row.style.display = visible ? '' : 'none';
        });
    }
    [searchInput, statusFilter].forEach(el => {
        if (el) el.addEventListener('input', filterProjects);
        if (el && el.tagName === 'SELECT') el.addEventListener('change', filterProjects);
    });
});
</script>
@endpush
@section('content')
<div class="projects-container">
    <div class="projects-header">
        <div class="header-content">
            <h1>Projects</h1>
            <p>Manage and track your project portfolio</p>
        </div>
        <button id="toggleProjectViewBtn" class="btn-secondary" style="margin-right: 1rem;">🔳 Table View</button>
        <button id="newProjectBtn" class="btn-primary">➕ New Project</button>
    </div>
    <div id="messageContainer" class="message-container" style="display: none;"></div>
    <!-- Filters -->
    <div class="filters-card">
        <div class="filters-grid">
            <div class="search-group">
                <div class="search-input">
                    <span class="search-icon">🔍</span>
                    <input type="text" id="searchProjects" name="searchProjects" placeholder="Search projects...">
                </div>
            </div>
            <select id="statusProjectFilter" name="statusProjectFilter">
                <option value="">All Status</option>
                <option value="Active">Active</option>
                <option value="On Hold">On Hold</option>
                <option value="Completed">Completed</option>
            </select>
        </div>
    </div>
    <!-- Projects Grid (Card View) -->
    <div id="projectsGrid" class="projects-grid">
        @forelse ($projects as $project)
            <div class="project-card" data-project-id="{{ $project->id }}" data-status="{{ $project->status }}" style="border-left-color: {{ $project->color ?? '#3B82F6' }};">
                <div class="project-header">
                    <div class="project-info">
                        <h3>{{ $project->name }}</h3>
                        <p>{{ $project->description }}</p>
                    </div>
                    <div class="project-actions">
                        <span class="status-badge status-{{ strtolower($project->status) }}">{{ ucfirst($project->status) }}</span>
                        <button class="action-btn edit-btn" title="Edit Project">✏️</button>
                        <button class="action-btn delete-btn" title="Delete Project">🗑️</button>
                    </div>
                </div>
                <div class="project-progress">
                    <div class="progress-header">
                        <span>Progress</span>
                        @php
                            $total = $project->tasks->count();
                            $done = $project->tasks->where('status', 'Completed')->count();
                            $progress = $total ? round($project->tasks->avg('progress')) : 0;
                        @endphp
                        <span>{{ $progress }}%</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: {{ $progress }}%; background-color: {{ $project->color ?? '#3B82F6' }};"></div>
                    </div>
                </div>
                <div class="project-stats">
                    <div class="stat-item">
                        <div class="stat-icon">📊</div>
                        <div class="stat-info">
                            <span class="stat-value">{{ $total }}</span>
                            <span class="stat-label">Tasks</span>
                        </div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-icon">✅</div>
                        <div class="stat-info">
                            <span class="stat-value">{{ $done }}</span>
                            <span class="stat-label">Done</span>
                        </div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-icon">⚠️</div>
                        <div class="stat-info">
                            <span class="stat-value">{{ $project->tasks->where('status', '!=', 'Completed')->where('due_date', '<', now())->count() }}</span>
                            <span class="stat-label">Overdue</span>
                        </div>
                    </div>
                </div>
                <div class="project-footer">
                    <div class="project-date">
                        <span class="date-icon">📅</span>
                        <span>Created {{ $project->created_at ? $project->created_at->format('d/m/Y') : '-' }}</span>
                    </div>
                </div>
            </div>
        @empty
            <div class="empty-state">
                <div class="empty-icon">📁</div>
                <h3>No projects yet</h3>
                <p>Create your first project to get started!</p>
            </div>
        @endforelse
    </div>
    <!-- Projects Table View (hidden by default) -->
    <div id="projectsTableWrapper" style="display:none;">
        <table class="projects-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Status</th>
                    <th>Progress</th>
                    <th>Tasks</th>
                    <th>Done</th>
                    <th>Overdue</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($projects as $project)
                    @php
                        $total = $project->tasks->count();
                        $done = $project->tasks->where('status', 'Completed')->count();
                        $progress = $total ? round($project->tasks->avg('progress')) : 0;
                        $overdue = $project->tasks->where('status', '!=', 'Completed')->where('due_date', '<', now())->count();
                    @endphp
                    <tr data-project-id="{{ $project->id }}" data-status="{{ $project->status }}">
                        <td>{{ $project->name }}</td>
                        <td><span class="status-badge status-{{ strtolower($project->status) }}">{{ ucfirst($project->status) }}</span></td>
                        <td>{{ $progress }}%</td>
                        <td>{{ $total }}</td>
                        <td>{{ $done }}</td>
                        <td>{{ $overdue }}</td>
                        <td>{{ $project->created_at ? $project->created_at->format('d/m/Y') : '-' }}</td>
                        <td>
                            <button class="btn-small btn-primary table-edit-btn">✏️</button>
                            <button class="btn-small btn-danger table-delete-btn">🗑️</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <!-- Project Form Modal -->
    <div id="projectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Create New Project</h3>
                <button class="modal-close">&times;</button>
            </div>
            <form id="projectForm" class="modal-form">
                <div class="form-group">
                    <label for="projectName">Project Name *</label>
                    <input type="text" id="projectName" name="projectName" placeholder="Enter project name" required>
                </div>
                <div class="form-group">
                    <label for="projectDescription">Description</label>
                    <textarea id="projectDescription" name="projectDescription" rows="3" placeholder="Project description..."></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="projectColor">Color Theme</label>
                        <div class="color-picker">
                            <div class="color-option" data-color="#3B82F6" style="background-color: #3B82F6;" title="Blue"></div>
                            <div class="color-option" data-color="#10B981" style="background-color: #10B981;" title="Green"></div>
                            <div class="color-option" data-color="#F59E0B" style="background-color: #F59E0B;" title="Yellow"></div>
                            <div class="color-option" data-color="#EF4444" style="background-color: #EF4444;" title="Red"></div>
                            <div class="color-option" data-color="#8B5CF6" style="background-color: #8B5CF6;" title="Purple"></div>
                            <div class="color-option" data-color="#06B6D4" style="background-color: #06B6D4;" title="Cyan"></div>
                            <div class="color-option" data-color="#84CC16" style="background-color: #84CC16;" title="Lime"></div>
                            <div class="color-option" data-color="#F97316" style="background-color: #F97316;" title="Orange"></div>
                        </div>
                        <input type="hidden" id="projectColor" name="projectColor" value="#3B82F6">
                    </div>
                    <div class="form-group">
                        <label for="projectStatus">Status</label>
                        <select id="projectStatus" name="projectStatus">
                            <option value="Active">Active</option>
                            <option value="On Hold">On Hold</option>
                            <option value="Completed">Completed</option>
                        </select>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-secondary" onclick="projectModal.classList.remove('active')">Cancel</button>
                    <button type="submit" class="btn-primary" id="submitBtn">➕ Create Project</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection 