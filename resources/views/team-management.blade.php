@extends('layouts.app')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
<link rel="stylesheet" href="{{ asset('css/team.css') }}">
<link rel="stylesheet" href="{{ asset('css/common.css') }}">
<style>
body { background: #f7f8fa; }
.team-container { max-width: 900px; margin: 0 auto; padding: 2rem 1rem; background: #fff; border-radius: 16px; box-shadow: 0 2px 16px 0 rgba(60,72,88,0.05); }
.team-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
.header-content h1 { font-size: 2rem; font-weight: 700; color: #22223b; margin-bottom: 0.2rem; }
.header-content p { color: #888; font-size: 1.1rem; }
#addMemberBtn { background: #4f8cff; color: #fff; border: none; border-radius: 8px; padding: 0.6rem 1.5rem; font-size: 1rem; font-weight: 600; cursor: pointer; transition: background 0.2s; }
#addMemberBtn:hover { background: #2563eb; }
#teamSearch { border: 1px solid #e5e7eb; border-radius: 8px; font-size: 1rem; padding: 0.6rem 1rem; margin-bottom: 1.5rem; }
.team-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 1.5rem; }
.member-card { background: #f8fafc; border-radius: 10px; box-shadow: none; padding: 0.7rem 0.7rem; display: flex; flex-direction: column; align-items: flex-start; transition: box-shadow 0.2s, border 0.2s; border: 1px solid #e5e7eb; position: relative; min-height: 90px; min-width: 0; }
.member-card:hover { box-shadow: 0 2px 8px 0 rgba(60,72,88,0.08); border: 1.5px solid #4f8cff33; }
.member-header { display: flex; align-items: center; width: 100%; margin-bottom: 0.3rem; }
.member-avatar { width: 32px; height: 32px; border-radius: 50%; background: #4f8cff22; color: #4f8cff; display: flex; align-items: center; justify-content: center; font-size: 1rem; font-weight: 700; margin-right: 0.7rem; }
.member-info h3 { font-size: 1rem; font-weight: 600; margin: 0; color: #22223b; }
.member-info p { font-size: 0.92rem; color: #888; margin: 0; }
.member-actions { margin-left: auto; display: flex; gap: 0.2rem; }
.action-btn { background: none; border: none; font-size: 1rem; cursor: pointer; color: #4f8cff; transition: color 0.2s; padding: 0.1rem; }
.action-btn:hover { color: #2563eb; }
.member-details { width: 100%; margin-top: 0.2rem; }
.detail-item { display: flex; justify-content: space-between; align-items: center; font-size: 0.92rem; margin-bottom: 0.1rem; color: #555; }
.detail-label { color: #888; font-weight: 500; }
.status-badge { border-radius: 7px; padding: 1px 8px; font-size: 0.9rem; font-weight: 600; background: #e5e7eb; color: #555; margin-left: 0.3rem; }
.status-active { background: #e0f7fa; color: #059669; }
.status-inactive { background: #fbe9e7; color: #d32f2f; }
.btn-small { font-size: 0.9rem; padding: 0.15rem 0.6rem; border-radius: 6px; border: none; cursor: pointer; margin-left: 0.3rem; transition: background 0.2s; }
.btn-small.btn-primary { background: #4f8cff; color: #fff; }
.btn-small.btn-danger { background: #ff6b6b; color: #fff; }
.btn-small.btn-primary:hover { background: #2563eb; }
.btn-small.btn-danger:hover { background: #c62828; }
.current-user { border: 2px solid #4f8cff !important; background: #e3f0ff; }
.current-user::after { content: 'You'; position: absolute; top: 7px; right: 7px; background: #4f8cff; color: #fff; font-size: 0.8rem; padding: 1px 6px; border-radius: 7px; font-weight: 600; }
.empty-state { text-align: center; color: #888; margin: 2rem 0; }
.empty-icon { font-size: 2.5rem; margin-bottom: 0.7rem; }
.pagination-wrapper { margin-top: 2rem; text-align: center; }
.modal { background: rgba(60,72,88,0.13); }
.modal-content { border-radius: 14px; box-shadow: 0 2px 16px 0 rgba(60,72,88,0.09); }
.modal-header h3 { font-size: 1.2rem; font-weight: 700; color: #22223b; }
.modal-form input, .modal-form select, .modal-form textarea { border-radius: 8px; border: 1px solid #e5e7eb; font-size: 1rem; padding: 0.6rem 1rem; margin-bottom: 1rem; background: #f8fafc; }
.modal-actions { display: flex; justify-content: flex-end; gap: 1rem; }
@media (max-width: 900px) { .team-grid { grid-template-columns: 1fr 1fr; } }
@media (max-width: 600px) { .team-grid { grid-template-columns: 1fr; } .team-container { padding: 1rem 0.2rem; } }
</style>
@endpush
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Modal logic
    const addMemberBtn = document.getElementById('addMemberBtn');
    const addMemberModal = document.getElementById('addMemberModal');
    const addMemberForm = document.getElementById('addMemberForm');
    const editMemberModal = document.getElementById('editMemberModal');
    const editMemberForm = document.getElementById('editMemberForm');
    const deleteModal = document.getElementById('deleteModal');
    const messageContainer = document.getElementById('messageContainer');
    let editMemberId = null;
    let deleteMemberId = null;

    function showMessage(msg, success = true) {
        Swal.fire({
            icon: success ? 'success' : 'error',
            title: success ? 'Success' : 'Error',
            text: msg,
            timer: 2000,
            showConfirmButton: false
        });
    }

    if (addMemberBtn && addMemberModal) {
        addMemberBtn.addEventListener('click', function() {
            addMemberForm.reset();
            addMemberModal.classList.add('active');
        });
    }
    if (addMemberModal) {
        addMemberModal.querySelector('.modal-close').addEventListener('click', function() {
            addMemberModal.classList.remove('active');
        });
        addMemberModal.addEventListener('click', function(e) {
            if (e.target === this) addMemberModal.classList.remove('active');
        });
    }
    if (editMemberModal) {
        editMemberModal.querySelector('.modal-close').addEventListener('click', function() {
            editMemberModal.classList.remove('active');
        });
        editMemberModal.addEventListener('click', function(e) {
            if (e.target === this) editMemberModal.classList.remove('active');
        });
    }
    if (deleteModal) {
        deleteModal.querySelector('.modal-close').addEventListener('click', function() {
            deleteModal.classList.remove('active');
        });
        deleteModal.addEventListener('click', function(e) {
            if (e.target === this) deleteModal.classList.remove('active');
        });
    }
    // Edit member
    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const card = this.closest('.member-card');
            editMemberId = card.dataset.memberId;
            editMemberModal.classList.add('active');
            editMemberForm.querySelector('#editMemberId').value = editMemberId;
            editMemberForm.querySelector('#editMemberName').value = card.querySelector('h3').textContent;
            editMemberForm.querySelector('#editMemberEmail').value = card.querySelector('.member-info p').textContent;
            // Set role in edit modal
            const roleValue = card.querySelector('.detail-value').textContent.trim();
            const editRoleSelect = editMemberForm.querySelector('#editMemberRole');
            if (editRoleSelect) {
                for (let i = 0; i < editRoleSelect.options.length; i++) {
                    if (editRoleSelect.options[i].textContent.trim().toLowerCase() === roleValue.toLowerCase()) {
                        editRoleSelect.selectedIndex = i;
                        break;
                    }
                }
            }
        });
    });
    // Make member cards clickable for analytics
    document.querySelectorAll('.member-card').forEach(card => {
        card.addEventListener('click', function(e) {
            // Prevent click if edit/delete button is clicked
            if (e.target.closest('.edit-btn') || e.target.closest('.delete-btn')) return;
            const memberId = this.dataset.memberId;
            window.location.href = `/team-management/${memberId}/analytics`;
        });
    });
    // Delete member
    document.querySelectorAll('.delete-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const card = this.closest('.member-card');
            deleteMemberId = card.dataset.memberId;
            const memberName = card.querySelector('h3').textContent;
            Swal.fire({
                title: `Remove ${memberName}?`,
                text: 'This action cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, remove!'
            }).then((result) => {
                if (!result.isConfirmed) return;
                fetch(`/team-management/${deleteMemberId}`, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        card.remove();
                        Swal.fire('Deleted!', 'Member removed.', 'success');
                    } else {
                        Swal.fire('Error', 'Error removing member.', 'error');
                    }
                });
            });
        });
    });
    // Add member
    // Enable/disable Create Account button based on form validity
    const addMemberFormEl = document.getElementById('addMemberForm');
    const createAccountBtn = document.getElementById('createAccountBtn');
    if (addMemberFormEl && createAccountBtn) {
        addMemberFormEl.addEventListener('input', function() {
            const name = addMemberFormEl.querySelector('#memberName').value.trim();
            const email = addMemberFormEl.querySelector('#memberEmail').value.trim();
            const password = addMemberFormEl.querySelector('#memberPassword').value.trim();
            const role = addMemberFormEl.querySelector('#memberRole').value;
            createAccountBtn.disabled = !(name && email && password && role);
        });
    }
    // Fix add member form submission
    addMemberFormEl?.addEventListener('submit', function(e) {
        e.preventDefault();
        if (createAccountBtn.disabled) return;
        const formData = new FormData(addMemberForm);
        const selectedRole = formData.get('memberRole');
        const selectedRoleText = addMemberForm.querySelector('#memberRole option:checked').textContent.trim();
        // If assigning manager, confirm
        if (['manager'].includes(selectedRoleText.toLowerCase())) {
            Swal.fire({
                title: `Assign ${selectedRoleText} role?`,
                text: `Are you sure you want to assign the ${selectedRoleText} role to this member?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#aaa',
                confirmButtonText: 'Yes, assign!'
            }).then((result) => {
                if (!result.isConfirmed) return;
                submitAddMember();
            });
        } else {
            submitAddMember();
        }
        function submitAddMember() {
            const payload = {
                name: formData.get('memberName'),
                email: formData.get('memberEmail'),
                password: formData.get('memberPassword'),
                role_id: selectedRole,
            };
            fetch('/team-management', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                },
                body: JSON.stringify(payload),
            })
            .then(r => r.json())
            .then(data => {
                if (data.id) {
                    showMessage('Member created.');
                    window.location.reload();
                }
            });
            addMemberModal.classList.remove('active');
        }
    });
    // Edit member
    editMemberForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(editMemberForm);
        const id = formData.get('editMemberId');
        const selectedRole = formData.get('editMemberRole');
        const selectedRoleText = editMemberForm.querySelector('#editMemberRole option:checked').textContent.trim();
        const currentRole = editMemberForm.querySelector('#editMemberRole').getAttribute('data-current-role');
        // If role changed, confirm
        if (currentRole && selectedRoleText.toLowerCase() !== currentRole.toLowerCase()) {
            Swal.fire({
                title: `Change role to ${selectedRoleText}?`,
                text: `Are you sure you want to change this member's role to ${selectedRoleText}?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#aaa',
                confirmButtonText: 'Yes, change!'
            }).then((result) => {
                if (!result.isConfirmed) return;
                submitEditMember();
            });
        } else {
            submitEditMember();
        }
        function submitEditMember() {
            const payload = {
                name: formData.get('editMemberName'),
                email: formData.get('editMemberEmail'),
                password: formData.get('editMemberPassword'),
                role_id: selectedRole,
            };
            fetch(`/team-management/${id}`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                },
                body: JSON.stringify(payload),
            })
            .then(r => r.json())
            .then(data => {
                if (data.id) {
                    showMessage('Member updated.');
                    window.location.reload();
                }
            });
            editMemberModal.classList.remove('active');
        }
    });
    // Delete member (modal confirm)
    document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
        fetch(`/team-management/${deleteMemberId}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                Swal.fire('Deleted!', 'Member removed.', 'success');
                window.location.reload();
            } else {
                Swal.fire('Error', 'Error removing member.', 'error');
            }
        });
        deleteModal.classList.remove('active');
    });
});
document.getElementById('teamSearch')?.addEventListener('input', function() {
    const search = this.value.toLowerCase();
    document.querySelectorAll('.member-card').forEach(card => {
        const name = card.querySelector('h3').textContent.toLowerCase();
        const email = card.querySelector('.member-info p').textContent.toLowerCase();
        card.style.display = (!search || name.includes(search) || email.includes(search)) ? '' : 'none';
    });
});
document.querySelectorAll('.deactivate-btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        const id = this.dataset.id;
        Swal.fire({
            title: 'Deactivate this member?',
            text: 'This will prevent the member from logging in.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, deactivate!'
        }).then((result) => {
            if (!result.isConfirmed) return;
            fetch(`/team-management/${id}/deactivate`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Deactivated!', 'Member deactivated.', 'success').then(() => window.location.reload());
                } else {
                    Swal.fire('Error', data.message || 'Error deactivating member.', 'error');
                }
            });
        });
    });
});
document.querySelectorAll('.activate-btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        const id = this.dataset.id;
        Swal.fire({
            title: 'Activate this member?',
            text: 'This will allow the member to log in again.',
            icon: 'info',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#aaa',
            confirmButtonText: 'Yes, activate!'
        }).then((result) => {
            if (!result.isConfirmed) return;
            fetch(`/team-management/${id}/activate`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Activated!', 'Member activated.', 'success').then(() => window.location.reload());
                } else {
                    Swal.fire('Error', data.message || 'Error activating member.', 'error');
                }
            });
        });
    });
});
</script>
@endpush
@section('content')
<div class="team-container">
    <div class="team-header">
        <div class="header-content">
            <h1>Team Management</h1>
            <p>Manage your team members and their access</p>
        </div>
        <button id="addMemberBtn" class="btn-primary">➕ Add Team Member</button>
    </div>
    <div style="margin:1rem 0; max-width:400px;">
        <input type="text" id="teamSearch" class="form-control" placeholder="Search team members by name or email..." style="width:100%; padding:0.5rem 1rem; border-radius:6px; border:1px solid #ccc;">
    </div>
    <div id="messageContainer" class="message-container" style="display: none;"></div>
    <div id="teamGrid" class="team-grid">
        @forelse ($users as $user)
            <div class="member-card @if(auth()->id() === $user->id) current-user @endif" data-member-id="{{ $user->id }}" style="cursor:pointer;">
                <div class="member-header">
                    <div class="member-avatar">{{ strtoupper(substr($user->name, 0, 2)) }}</div>
                    <div class="member-info">
                        <h3>{{ $user->name }}</h3>
                        <p>{{ $user->email }}</p>
                    </div>
                    <div class="member-actions">
                        <button class="action-btn edit-btn" title="Edit Member">✏️</button>
                        <button class="action-btn delete-btn" title="Delete Member">🗑️</button>
                    </div>
                </div>
                <div class="member-details">
                    <div class="detail-item">
                        <span class="detail-label">Role:</span>
                        <span class="detail-value">{{ $user->role ? $user->role->name : '-' }}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Login ID:</span>
                        <span class="detail-value">{{ $user->email }}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Joined:</span>
                        <span class="detail-value">
                            {{ $user->created_at ? $user->created_at->format('m/d/Y') : '-' }}
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Last Login:</span>
                        <span class="detail-value">{{ $user->last_login_at ?? '-' }}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Status:</span>
                        <span class="detail-value status-badge status-{{ $user->isActive() ? 'active' : 'inactive' }}">{{ $user->isActive() ? 'Active' : 'Inactive' }}</span>
                        @if(auth()->user()->role->name === 'manager' && auth()->id() !== $user->id)
                            @if($user->isActive())
                                <button class="btn-small btn-danger deactivate-btn" data-id="{{ $user->id }}" style="margin-left:1rem;">Deactivate</button>
                            @else
                                <button class="btn-small btn-primary activate-btn" data-id="{{ $user->id }}" style="margin-left:1rem;">Activate</button>
                            @endif
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="empty-state">
                <div class="empty-icon">👥</div>
                <h3>No team members yet</h3>
                <p>Add your first team member to get started!</p>
            </div>
        @endforelse
    </div>
    @if(method_exists($users, 'links'))
        <div class="pagination-wrapper" style="margin-top:2rem; text-align:center;">
            {{ $users->links() }}
        </div>
    @endif
    <!-- Add Member Modal -->
    <div id="addMemberModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Create Team Member Account</h3>
                <button class="modal-close">&times;</button>
            </div>
            <form id="addMemberForm" class="modal-form">
                <div class="form-group">
                    <label for="memberName">Full Name *</label>
                    <input type="text" id="memberName" name="memberName" placeholder="Enter full name" required>
                </div>
                <div class="form-group">
                    <label for="memberEmail">Login ID (Email) *</label>
                    <input type="email" id="memberEmail" name="memberEmail" placeholder="Enter login email" required>
                </div>
                <div class="form-group">
                    <label for="memberPassword">Password *</label>
                    <input type="password" id="memberPassword" name="memberPassword" placeholder="Enter password" required>
                </div>
                <div class="form-group">
                    <label for="memberRole">Role *</label>
                    <select id="memberRole" name="memberRole" required>
                        <option value="">Select Role</option>
                        @foreach($roles as $role)
                            <option value="{{ $role->id }}">{{ ucfirst($role->name) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-secondary" onclick="addMemberModal.classList.remove('active')">Cancel</button>
                    <button type="submit" class="btn-primary" id="createAccountBtn" disabled>👤 Create Account</button>
                </div>
            </form>
        </div>
    </div>
    <!-- Edit Member Modal -->
    <div id="editMemberModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Member Details</h3>
                <button class="modal-close">&times;</button>
            </div>
            <form id="editMemberForm" class="modal-form">
                <input type="hidden" id="editMemberId">
                <div class="form-group">
                    <label for="editMemberName">Full Name *</label>
                    <input type="text" id="editMemberName" name="editMemberName" placeholder="Enter full name" required>
                </div>
                <div class="form-group">
                    <label for="editMemberEmail">Login ID (Email) *</label>
                    <input type="email" id="editMemberEmail" name="editMemberEmail" placeholder="Enter login email" required>
                </div>
                <div class="form-group">
                    <label for="editMemberPassword">Password</label>
                    <input type="password" id="editMemberPassword" name="editMemberPassword" placeholder="Enter new password (leave blank to keep current)">
                </div>
                <div class="form-group">
                    <label for="editMemberRole">Role *</label>
                    <select id="editMemberRole" name="editMemberRole" required>
                        <option value="">Select Role</option>
                        @foreach($roles as $role)
                            <option value="{{ $role->id }}">{{ ucfirst($role->name) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-secondary" onclick="editMemberModal.classList.remove('active')">Cancel</button>
                    <button type="submit" class="btn-primary">💾 Update</button>
                </div>
            </form>
        </div>
    </div>
    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content small">
            <div class="modal-header">
                <h3>Confirm Delete</h3>
                <button class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to remove <strong id="deleteMemberName"></strong> from your team?</p>
                <p class="warning-text">This action cannot be undone.</p>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="deleteModal.classList.remove('active')">Cancel</button>
                <button type="button" class="btn-danger" id="confirmDeleteBtn">🗑️ Remove Member</button>
            </div>
        </div>
    </div>
</div>
@endsection 