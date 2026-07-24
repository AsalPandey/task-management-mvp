@extends('layouts.app')

@push('styles')
<link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
<link rel="stylesheet" href="{{ asset('css/projects.css') }}">
<link rel="stylesheet" href="{{ asset('css/common.css') }}">
<style>
body { background: #f7f8fa; }
.projects-container { max-width: 1120px; margin: 0 auto; padding: 2rem 1rem; }
.projects-header { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 1.5rem; }
.projects-header h1 { font-size: 1.75rem; margin: 0; color: #22223b; }
.projects-header p { color: #667085; margin: 0.25rem 0 0; }
.projects-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(290px, 1fr)); gap: 1rem; }
.project-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 1rem; box-shadow: 0 1px 8px rgba(60,72,88,0.05); }
.project-card-header { display: flex; justify-content: space-between; gap: 1rem; align-items: flex-start; }
.project-title { display: flex; align-items: center; gap: 0.55rem; }
.project-color-dot { width: 14px; height: 14px; border-radius: 50%; border: 1px solid #d0d5dd; flex: 0 0 auto; }
.project-title h3 { margin: 0; font-size: 1.1rem; color: #111827; }
.project-description { color: #667085; min-height: 2.8rem; margin: 0.7rem 0 1rem; line-height: 1.45; }
.project-meta { display: grid; gap: 0.45rem; color: #475467; font-size: 0.92rem; margin-bottom: 1rem; }
.project-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem; margin-bottom: 1rem; }
.project-stat { background: #f8fafc; border: 1px solid #eef2f6; border-radius: 8px; padding: 0.65rem; text-align: center; }
.project-stat strong { display: block; color: #111827; font-size: 1.1rem; }
.project-stat span { color: #667085; font-size: 0.8rem; }
.project-members { border-top: 1px solid #eef2f6; padding-top: 0.9rem; }
.project-members h4 { margin: 0 0 0.6rem; color: #344054; font-size: 0.95rem; }
.member-list { display: flex; flex-direction: column; gap: 0.4rem; margin-bottom: 0.75rem; }
.member-row { display: flex; justify-content: space-between; align-items: center; background: #f8fafc; border-radius: 7px; padding: 0.45rem 0.55rem; color: #344054; font-size: 0.9rem; }
.member-row button { border: 0; background: transparent; color: #d92d20; cursor: pointer; font-weight: 600; }
.member-add { display: flex; gap: 0.5rem; }
.member-add select { flex: 1; min-width: 0; }
.project-actions { display: flex; gap: 0.35rem; }
.badge { border-radius: 999px; padding: 0.18rem 0.55rem; background: #eef4ff; color: #3538cd; font-size: 0.8rem; text-transform: capitalize; }
.empty-state { text-align: center; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 2.5rem 1rem; color: #667085; }
.modal .modal-content { max-width: 560px; }
@media (max-width: 640px) {
    .projects-header { align-items: stretch; flex-direction: column; }
    .member-add { flex-direction: column; }
}
</style>
@endpush

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('projectModal');
    const form = document.getElementById('projectForm');
    const newProjectBtn = document.getElementById('newProjectBtn');
    const modalTitle = document.getElementById('projectModalTitle');
    const submitBtn = document.getElementById('projectSubmitBtn');
    let editingProjectId = null;

    function csrfHeaders() {
        return {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
            'Accept': 'application/json',
        };
    }

    function showMessage(message, success = true) {
        Swal.fire({
            icon: success ? 'success' : 'error',
            title: success ? 'Success' : 'Error',
            text: message,
            timer: 2200,
            showConfirmButton: false,
        });
    }

    function openModal(project = null) {
        editingProjectId = project ? project.id : null;
        form.reset();
        modalTitle.textContent = project ? 'Edit Project' : 'Create Project';
        submitBtn.textContent = project ? 'Update Project' : 'Create Project';

        if (project) {
            form.querySelector('[name="name"]').value = project.name || '';
            form.querySelector('[name="description"]').value = project.description || '';
            form.querySelector('[name="project_manager_id"]').value = project.project_manager_id || '';
            form.querySelector('[name="status"]').value = project.status || 'active';
            form.querySelector('[name="color"]').value = project.color || '#4f8cff';
            form.querySelector('[name="start_date"]').value = project.start_date || '';
            form.querySelector('[name="end_date"]').value = project.end_date || '';
        }

        modal.classList.add('active');
    }

    function projectFromCard(card) {
        return {
            id: card.dataset.projectId,
            name: card.dataset.name,
            description: card.dataset.description,
            project_manager_id: card.dataset.projectManagerId,
            status: card.dataset.status,
            color: card.dataset.color,
            start_date: card.dataset.startDate,
            end_date: card.dataset.endDate,
        };
    }

    if (newProjectBtn) {
        newProjectBtn.addEventListener('click', () => openModal());
    }

    document.querySelectorAll('.modal-close, [data-close-project-modal]').forEach(button => {
        button.addEventListener('click', () => modal.classList.remove('active'));
    });

    modal.addEventListener('click', event => {
        if (event.target === modal) {
            modal.classList.remove('active');
        }
    });

    document.querySelectorAll('.project-edit-btn').forEach(button => {
        button.addEventListener('click', () => openModal(projectFromCard(button.closest('.project-card'))));
    });

    document.querySelectorAll('.project-delete-btn').forEach(button => {
        button.addEventListener('click', function() {
            const card = this.closest('.project-card');
            Swal.fire({
                title: 'Delete this project?',
                text: 'Projects with tasks cannot be deleted.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Delete',
                confirmButtonColor: '#d92d20',
            }).then(result => {
                if (!result.isConfirmed) return;
                fetch(`/projects/${card.dataset.projectId}`, {
                    method: 'DELETE',
                    headers: csrfHeaders(),
                })
                    .then(async response => ({ ok: response.ok, data: await response.json() }))
                    .then(({ ok, data }) => {
                        if (ok && data.success) {
                            card.remove();
                            showMessage('Project deleted.');
                        } else {
                            showMessage(data.message || 'Project could not be deleted.', false);
                        }
                    })
                    .catch(error => showMessage(error.message || 'Project could not be deleted.', false));
            });
        });
    });

    form.addEventListener('submit', function(event) {
        event.preventDefault();
        const formData = new FormData(form);
        const payload = Object.fromEntries(formData.entries());
        const url = editingProjectId ? `/projects/${editingProjectId}` : '/projects';
        const method = editingProjectId ? 'PUT' : 'POST';

        fetch(url, {
            method,
            headers: csrfHeaders(),
            body: JSON.stringify(payload),
        })
            .then(async response => ({ ok: response.ok, data: await response.json() }))
            .then(({ ok, data }) => {
                if (ok && data.success) {
                    showMessage(editingProjectId ? 'Project updated.' : 'Project created.');
                    window.location.reload();
                    return;
                }
                showMessage(data.message || 'Project could not be saved.', false);
            })
            .catch(error => showMessage(error.message || 'Project could not be saved.', false));
    });

    document.querySelectorAll('.member-add-form').forEach(memberForm => {
        memberForm.addEventListener('submit', function(event) {
            event.preventDefault();
            const projectId = this.dataset.projectId;
            const userId = this.querySelector('[name="user_id"]').value;
            if (!userId) return;

            fetch(`/projects/${projectId}/add-member`, {
                method: 'POST',
                headers: csrfHeaders(),
                body: JSON.stringify({ user_id: userId }),
            })
                .then(async response => ({ ok: response.ok, data: await response.json() }))
                .then(({ ok, data }) => {
                    if (ok && data.success) {
                        showMessage('Project member added.');
                        window.location.reload();
                        return;
                    }
                    showMessage(data.message || 'Member could not be added.', false);
                })
                .catch(error => showMessage(error.message || 'Member could not be added.', false));
        });
    });

    document.querySelectorAll('.member-remove-btn').forEach(button => {
        button.addEventListener('click', function() {
            const projectId = this.dataset.projectId;
            const userId = this.dataset.userId;
            Swal.fire({
                title: 'Remove this member?',
                text: 'Members with active project tasks cannot be removed.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Remove',
                confirmButtonColor: '#d92d20',
            }).then(result => {
                if (!result.isConfirmed) return;
                fetch(`/projects/${projectId}/remove-member`, {
                    method: 'DELETE',
                    headers: csrfHeaders(),
                    body: JSON.stringify({ user_id: userId }),
                })
                    .then(async response => ({ ok: response.ok, data: await response.json() }))
                    .then(({ ok, data }) => {
                        if (ok && data.success) {
                            showMessage('Project member removed.');
                            window.location.reload();
                            return;
                        }
                        showMessage(data.message || 'Member could not be removed.', false);
                    })
                    .catch(error => showMessage(error.message || 'Member could not be removed.', false));
            });
        });
    });
});
</script>
@endpush

@section('content')
<div class="projects-container">
    <div class="projects-header">
        <div>
            <h1>Projects</h1>
            <p>Manage project ownership, memberships, and task assignment boundaries.</p>
        </div>
        @can('create', \App\Models\Project::class)
            <button id="newProjectBtn" class="btn-primary">New Project</button>
        @endcan
    </div>

    @if ($projects->count())
        <div class="projects-grid">
            @foreach ($projects as $project)
                @php
                    $activeTasks = $project->tasks
                        ->whereNotIn('status', ['Completed', 'Cancelled'])
                        ->count();
                    $completedTasks = $project->tasks->where('status', 'Completed')->count();
                @endphp
                <article class="project-card"
                    data-project-id="{{ $project->id }}"
                    data-name="{{ $project->name }}"
                    data-description="{{ $project->description }}"
                    data-project-manager-id="{{ $project->project_manager_id }}"
                    data-status="{{ $project->status }}"
                    data-color="{{ $project->color }}"
                    data-start-date="{{ $project->start_date?->format('Y-m-d') }}"
                    data-end-date="{{ $project->end_date?->format('Y-m-d') }}"
                >
                    <div class="project-card-header">
                        <div class="project-title">
                            <span class="project-color-dot" style="background: {{ $project->color ?: '#4f8cff' }}"></span>
                            <div>
                                <h3>{{ $project->name }}</h3>
                                <span class="badge">{{ str_replace('_', ' ', $project->status) }}</span>
                            </div>
                        </div>
                        <div class="project-actions">
                            @can('update', $project)
                                <button type="button" class="btn-small btn-primary project-edit-btn">Edit</button>
                            @endcan
                            @can('delete', $project)
                                <button type="button" class="btn-small btn-danger project-delete-btn">Delete</button>
                            @endcan
                        </div>
                    </div>

                    <p class="project-description">{{ $project->description ?: 'No description added.' }}</p>

                    <div class="project-meta">
                        <div>Manager: {{ $project->projectManager?->name ?: 'Unassigned' }}</div>
                        <div>Start: {{ $project->start_date?->format('M d, Y') ?: '-' }}</div>
                        <div>End: {{ $project->end_date?->format('M d, Y') ?: '-' }}</div>
                    </div>

                    <div class="project-stats">
                        <div class="project-stat">
                            <strong>{{ $project->members->count() }}</strong>
                            <span>Members</span>
                        </div>
                        <div class="project-stat">
                            <strong>{{ $activeTasks }}</strong>
                            <span>Active Tasks</span>
                        </div>
                        <div class="project-stat">
                            <strong>{{ $completedTasks }}</strong>
                            <span>Done</span>
                        </div>
                    </div>

                    <div class="project-members">
                        <h4>Members</h4>
                        <div class="member-list">
                            @forelse ($project->members as $member)
                                <div class="member-row">
                                    <span>{{ $member->name }} ({{ $member->role?->label ?: str_replace('_', ' ', $member->role?->name ?? 'member') }})</span>
                                    @can('manageMembers', $project)
                                        <button type="button" class="member-remove-btn" data-project-id="{{ $project->id }}" data-user-id="{{ $member->id }}">Remove</button>
                                    @endcan
                                </div>
                            @empty
                                <div class="member-row"><span>No members yet</span></div>
                            @endforelse
                        </div>

                        @can('manageMembers', $project)
                            <form class="member-add-form member-add" data-project-id="{{ $project->id }}">
                                <select name="user_id" required>
                                    <option value="">Add member</option>
                                    @foreach ($teamMembers as $member)
                                        <option value="{{ $member->id }}">{{ $member->name }} ({{ $member->role?->label ?: str_replace('_', ' ', $member->role?->name ?? 'member') }})</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="btn-secondary">Add</button>
                            </form>
                        @endcan
                    </div>
                </article>
            @endforeach
        </div>

        <div style="margin-top: 1.5rem;">
            {{ $projects->links() }}
        </div>
    @else
        <div class="empty-state">
            <h3>No projects yet</h3>
            <p>Create a project first, then add members so task assignment can be enforced correctly.</p>
        </div>
    @endif
</div>

<div id="projectModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="projectModalTitle">Create Project</h3>
            <button type="button" class="modal-close">&times;</button>
        </div>
        <form id="projectForm" class="modal-form">
            <div class="form-group">
                <label for="projectName">Project Name *</label>
                <input id="projectName" name="name" type="text" required maxlength="255">
            </div>
            <div class="form-group">
                <label for="projectDescription">Description</label>
                <textarea id="projectDescription" name="description" rows="3"></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="projectManager">Project Manager</label>
                    <select id="projectManager" name="project_manager_id">
                        <option value="">Unassigned</option>
                        @foreach ($projectManagers as $manager)
                            <option value="{{ $manager->id }}">{{ $manager->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="projectStatus">Status *</label>
                    <select id="projectStatus" name="status" required>
                        <option value="active">Active</option>
                        <option value="on_hold">On Hold</option>
                        <option value="completed">Completed</option>
                        <option value="archived">Archived</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="projectStartDate">Start Date</label>
                    <input id="projectStartDate" name="start_date" type="date">
                </div>
                <div class="form-group">
                    <label for="projectEndDate">End Date</label>
                    <input id="projectEndDate" name="end_date" type="date">
                </div>
            </div>
            <div class="form-group">
                <label for="projectColor">Color</label>
                <input id="projectColor" name="color" type="color" value="#4f8cff">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" data-close-project-modal>Cancel</button>
                <button type="submit" class="btn-primary" id="projectSubmitBtn">Create Project</button>
            </div>
        </form>
    </div>
</div>
@endsection
