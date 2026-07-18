// Team Management JavaScript
document.addEventListener('DOMContentLoaded', function() {
    // Initialize page
    initializePage();
    setupEventListeners();
    loadTeamMembers();

    function initializePage() {
        // Check if user has permission (manager only)
        const currentUser = window.authSystem.getCurrentUser();
        if (!currentUser || currentUser.role !== 'manager') {
            showMessage('Access denied. Manager role required.', 'error');
            return;
        }
    }

    function setupEventListeners() {
        // Add member button
        const addMemberBtn = document.getElementById('addMemberBtn');
        if (addMemberBtn) {
            addMemberBtn.addEventListener('click', openAddMemberModal);
        }

        // Add member form
        const addMemberForm = document.getElementById('addMemberForm');
        if (addMemberForm) {
            addMemberForm.addEventListener('submit', handleAddMember);
        }

        // Edit member form
        const editMemberForm = document.getElementById('editMemberForm');
        if (editMemberForm) {
            editMemberForm.addEventListener('submit', handleEditMember);
        }

        // Modal close buttons
        const modalCloses = document.querySelectorAll('.modal-close');
        modalCloses.forEach(close => {
            close.addEventListener('click', function() {
                closeAllModals();
            });
        });

        // Click outside modal to close
        const modals = document.querySelectorAll('.modal');
        modals.forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeAllModals();
                }
            });
        });

        // Confirm delete button
        const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
        if (confirmDeleteBtn) {
            confirmDeleteBtn.addEventListener('click', handleDeleteMember);
        }
    }

    function loadTeamMembers() {
        const teamMembers = window.authSystem.getTeamMembers();
        const teamGrid = document.getElementById('teamGrid');
        const emptyState = document.getElementById('emptyState');

        if (teamMembers.length === 0) {
            teamGrid.style.display = 'none';
            emptyState.style.display = 'block';
            return;
        }

        teamGrid.style.display = 'grid';
        emptyState.style.display = 'none';

        renderTeamMembers(teamMembers);
    }

    function renderTeamMembers(members) {
        const teamGrid = document.getElementById('teamGrid');
        
        teamGrid.innerHTML = members.map(member => `
            <div class="member-card" data-member-id="${member.id}">
                <div class="member-header">
                    <div class="member-avatar">${getInitials(member.name)}</div>
                    <div class="member-info">
                        <h3>${member.name}</h3>
                        <p>${member.email}</p>
                    </div>
                    <div class="member-actions">
                        <button class="action-btn edit-btn" onclick="openEditMemberModal('${member.id}')" title="Edit Member">✏️</button>
                        <button class="action-btn delete-btn" onclick="openDeleteModal('${member.id}')" title="Delete Member">🗑️</button>
                    </div>
                </div>
                
                <div class="member-details">
                    <div class="detail-item">
                        <span class="detail-label">Role:</span>
                        <span class="detail-value">${member.role}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Login ID:</span>
                        <span class="detail-value">${member.email}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Joined:</span>
                        <span class="detail-value">${new Date(member.createdAt).toLocaleDateString()}</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Last Login:</span>
                        <span class="detail-value">${member.lastLogin ? new Date(member.lastLogin).toLocaleDateString() : 'Never'}</span>
                    </div>
                </div>
            </div>
        `).join('');
    }

    function openAddMemberModal() {
        const modal = document.getElementById('addMemberModal');
        const form = document.getElementById('addMemberForm');
        
        // Reset form
        form.reset();
        
        // Show modal
        modal.classList.add('active');
    }

    function openEditMemberModal(memberId) {
        const teamMembers = window.authSystem.getTeamMembers();
        const member = teamMembers.find(m => m.id === memberId);
        
        if (!member) return;

        const modal = document.getElementById('editMemberModal');
        
        // Fill form with member data
        document.getElementById('editMemberId').value = member.id;
        document.getElementById('editMemberName').value = member.name;
        document.getElementById('editMemberEmail').value = member.email;
        document.getElementById('editMemberPassword').value = '';
        
        // Show modal
        modal.classList.add('active');
    }

    function openDeleteModal(memberId) {
        const teamMembers = window.authSystem.getTeamMembers();
        const member = teamMembers.find(m => m.id === memberId);
        
        if (!member) return;

        const modal = document.getElementById('deleteModal');
        const memberNameSpan = document.getElementById('deleteMemberName');
        const confirmBtn = document.getElementById('confirmDeleteBtn');
        
        memberNameSpan.textContent = member.name;
        confirmBtn.dataset.memberId = memberId;
        
        modal.classList.add('active');
    }

    function closeAllModals() {
        const modals = document.querySelectorAll('.modal');
        modals.forEach(modal => {
            modal.classList.remove('active');
        });
    }

    function handleAddMember(e) {
        e.preventDefault();
        
        const formData = new FormData(e.target);
        const memberData = {
            name: formData.get('name') || document.getElementById('memberName').value,
            email: formData.get('email') || document.getElementById('memberEmail').value,
            password: formData.get('password') || document.getElementById('memberPassword').value
        };

        // Validate required fields
        if (!memberData.name.trim() || !memberData.email.trim() || !memberData.password.trim()) {
            showMessage('All fields are required.', 'error');
            return;
        }

        // Validate email format
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(memberData.email)) {
            showMessage('Please enter a valid email address.', 'error');
            return;
        }

        const result = window.authSystem.addTeamMember(memberData);
        
        if (result.success) {
            closeAllModals();
            loadTeamMembers();
            showMessage(`✅ ${memberData.name} has been added to your team successfully!`, 'success');
        } else {
            showMessage(`❌ Failed to add team member: ${result.message}`, 'error');
        }
    }

    function handleEditMember(e) {
        e.preventDefault();
        
        const memberId = document.getElementById('editMemberId').value;
        const updateData = {
            name: document.getElementById('editMemberName').value,
            email: document.getElementById('editMemberEmail').value
        };

        const newPassword = document.getElementById('editMemberPassword').value;
        if (newPassword.trim()) {
            updateData.password = newPassword;
        }

        // Validate required fields
        if (!updateData.name.trim() || !updateData.email.trim()) {
            showMessage('Name and email are required.', 'error');
            return;
        }

        // Validate email format
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(updateData.email)) {
            showMessage('Please enter a valid email address.', 'error');
            return;
        }

        const result = window.authSystem.updateTeamMember(memberId, updateData);
        
        if (result.success) {
            closeAllModals();
            loadTeamMembers();
            showMessage(`✅ ${updateData.name}'s information has been updated successfully!`, 'success');
        } else {
            showMessage(`❌ Failed to update team member: ${result.message}`, 'error');
        }
    }

    function handleDeleteMember() {
        const memberId = document.getElementById('confirmDeleteBtn').dataset.memberId;
        const teamMembers = window.authSystem.getTeamMembers();
        const member = teamMembers.find(m => m.id === memberId);
        
        if (!member) return;

        const result = window.authSystem.removeTeamMember(memberId);
        
        if (result.success) {
            closeAllModals();
            loadTeamMembers();
            showMessage(`✅ ${member.name} has been removed from your team.`, 'success');
        } else {
            showMessage(`❌ Failed to remove team member: ${result.message}`, 'error');
        }
    }

    // Global functions for onclick handlers
    window.openEditMemberModal = openEditMemberModal;
    window.openDeleteModal = openDeleteModal;
    window.closeAddMemberModal = closeAllModals;
    window.closeEditMemberModal = closeAllModals;
    window.closeDeleteModal = closeAllModals;

    // Helper functions
    function getInitials(name) {
        return name.split(' ').map(n => n[0]).join('').toUpperCase();
    }

    function showMessage(message, type) {
        const messageContainer = document.getElementById('messageContainer');
        
        messageContainer.className = `message-container message-${type}`;
        messageContainer.textContent = message;
        messageContainer.style.display = 'block';

        // Auto-hide after 5 seconds
        setTimeout(() => {
            messageContainer.style.display = 'none';
        }, 5000);

        // Scroll to top to show message
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
});