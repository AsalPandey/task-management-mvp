@extends('layouts.app')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
<link rel="stylesheet" href="{{ asset('css/team.css') }}">
<link rel="stylesheet" href="{{ asset('css/common.css') }}">
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
        if (!messageContainer) return;
        messageContainer.textContent = msg;
        messageContainer.style.display = 'block';
        messageContainer.className = 'message-container ' + (success ? 'success' : 'error');
        setTimeout(() => { messageContainer.style.display = 'none'; }, 2000);
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
        });
    });
    // Delete member
    document.querySelectorAll('.delete-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const card = this.closest('.member-card');
            deleteMemberId = card.dataset.memberId;
            document.getElementById('deleteMemberName').textContent = card.querySelector('h3').textContent;
            deleteModal.classList.add('active');
        });
    });
    // Add member
    addMemberForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(addMemberForm);
        const payload = {
            name: formData.get('memberName'),
            email: formData.get('memberEmail'),
            password: formData.get('memberPassword'),
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
    });
    // Edit member
    editMemberForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(editMemberForm);
        const id = formData.get('editMemberId');
        const payload = {
            name: formData.get('editMemberName'),
            email: formData.get('editMemberEmail'),
            password: formData.get('editMemberPassword'),
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
    });
    // Delete member
    document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
        fetch(`/team-management/${deleteMemberId}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showMessage('Member deleted.');
                window.location.reload();
            }
        });
        deleteModal.classList.remove('active');
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
    <div id="messageContainer" class="message-container" style="display: none;"></div>
    <div id="teamGrid" class="team-grid">
        @forelse ($users as $user)
            <div class="member-card" data-member-id="{{ $user->id }}">
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
                        <span class="detail-value">{{ $user->created_at ? $user->created_at->format('m/d/Y') : '-' }}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Last Login:</span>
                        <span class="detail-value">{{ $user->last_login_at ?? '-' }}</span>
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
                <div class="modal-actions">
                    <button type="button" class="btn-secondary" onclick="addMemberModal.classList.remove('active')">Cancel</button>
                    <button type="submit" class="btn-primary">👤 Create Account</button>
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