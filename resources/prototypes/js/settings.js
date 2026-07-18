// Settings Page JavaScript
document.addEventListener('DOMContentLoaded', function() {
    // Check authentication
    if (!window.authSystem.requireAuth()) {
        return;
    }

    const currentUser = window.authSystem.getCurrentUser();
    
    // Initialize settings
    initializeSettings();
    setupEventListeners();
    loadUserData();

    function initializeSettings() {
        // Hide team updates notification for team members
        if (currentUser.role === 'team') {
            const teamUpdatesItem = document.getElementById('teamUpdatesItem');
            if (teamUpdatesItem) {
                teamUpdatesItem.style.display = 'none';
            }
        }
    }

    function setupEventListeners() {
        // Tab navigation
        const navItems = document.querySelectorAll('.nav-item');
        navItems.forEach(item => {
            item.addEventListener('click', function() {
                const tabName = this.dataset.tab;
                switchTab(tabName);
            });
        });

        // Profile form
        const profileForm = document.getElementById('profileForm');
        if (profileForm) {
            profileForm.addEventListener('submit', handleProfileUpdate);
        }

        // Password form
        const passwordForm = document.getElementById('passwordForm');
        if (passwordForm) {
            passwordForm.addEventListener('submit', handlePasswordChange);
        }
    }

    function loadUserData() {
        // Update profile information
        const profileName = document.getElementById('profileName');
        const profileRole = document.getElementById('profileRole');
        const profileAvatar = document.getElementById('profileAvatar');
        const profileFullName = document.getElementById('profileFullName');
        const profileEmail = document.getElementById('profileEmail');
        const profileTimezone = document.getElementById('profileTimezone');
        const accountCreated = document.getElementById('accountCreated');
        const lastLogin = document.getElementById('lastLogin');

        if (profileName) profileName.textContent = currentUser.name;
        if (profileRole) profileRole.textContent = currentUser.role === 'manager' ? 'Manager' : 'Team Member';
        if (profileAvatar) {
            profileAvatar.textContent = currentUser.name.split(' ').map(n => n[0]).join('').toUpperCase();
        }
        if (profileFullName) profileFullName.value = currentUser.name;
        if (profileEmail) profileEmail.value = currentUser.email;
        if (profileTimezone) profileTimezone.value = currentUser.timezone;
        if (accountCreated) accountCreated.textContent = new Date(currentUser.createdAt).toLocaleDateString();
        if (lastLogin && currentUser.lastLogin) {
            lastLogin.textContent = new Date(currentUser.lastLogin).toLocaleDateString();
        }
    }

    function switchTab(tabName) {
        // Update active nav item
        document.querySelectorAll('.nav-item').forEach(item => {
            item.classList.remove('active');
        });
        document.querySelector(`[data-tab="${tabName}"]`).classList.add('active');

        // Update active tab content
        document.querySelectorAll('.settings-tab').forEach(tab => {
            tab.classList.remove('active');
        });
        document.getElementById(tabName).classList.add('active');
    }

    function handleProfileUpdate(e) {
        e.preventDefault();
        
        const profileFullName = document.getElementById('profileFullName').value;
        const profileEmail = document.getElementById('profileEmail').value;
        // const profileTimezone = document.getElementById('profileTimezone').value; // removed

        if (!profileFullName.trim() || !profileEmail.trim()) {
            showMessage('profileMessage', 'All fields are required.', 'error');
            return;
        }

        // Validate email format
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(profileEmail)) {
            showMessage('profileMessage', 'Please enter a valid email address.', 'error');
            return;
        }

        fetch('/settings/profile', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                profileFullName,
                profileEmail
                // profileTimezone // removed
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update UI
                const profileName = document.getElementById('profileName');
                const profileAvatar = document.getElementById('profileAvatar');
                if (profileName) profileName.textContent = profileFullName;
                if (profileAvatar) {
                    profileAvatar.textContent = profileFullName.split(' ').map(n => n[0]).join('').toUpperCase();
                }
                showMessage('profileMessage', '✅ Profile updated successfully!', 'success');
            } else {
                showMessage('profileMessage', data.message || '❌ Failed to update profile. Please try again.', 'error');
            }
        })
        .catch(() => {
            showMessage('profileMessage', '❌ Failed to update profile. Please try again.', 'error');
        });
    }

    function handlePasswordChange(e) {
        e.preventDefault();
        
        const currentPassword = document.getElementById('currentPassword').value;
        const newPassword = document.getElementById('newPassword').value;
        const confirmPassword = document.getElementById('confirmPassword').value;

        if (!currentPassword || !newPassword || !confirmPassword) {
            showMessage('securityMessage', 'All password fields are required.', 'error');
            return;
        }

        if (newPassword !== confirmPassword) {
            showMessage('securityMessage', '❌ New passwords do not match.', 'error');
            return;
        }

        if (newPassword.length < 6) {
            showMessage('securityMessage', '❌ Password must be at least 6 characters long.', 'error');
            return;
        }

        const result = window.authSystem.changePassword(currentPassword, newPassword);

        if (result.success) {
            // Clear form
            document.getElementById('currentPassword').value = '';
            document.getElementById('newPassword').value = '';
            document.getElementById('confirmPassword').value = '';
            
            showMessage('securityMessage', '✅ Password changed successfully!', 'success');
        } else {
            showMessage('securityMessage', '❌ Current password is incorrect.', 'error');
        }
    }

    // Global functions for onclick handlers
    window.saveNotificationSettings = function() {
        const taskAssigned = document.getElementById('taskAssigned').checked;
        const taskCompleted = document.getElementById('taskCompleted').checked;
        const deadlineReminder = document.getElementById('deadlineReminder').checked;
        const teamUpdates = document.getElementById('teamUpdates').checked;

        // Save to localStorage (in a real app, this would be sent to the server)
        const notificationSettings = {
            taskAssigned,
            taskCompleted,
            deadlineReminder,
            teamUpdates
        };

        localStorage.setItem('taskflow_notification_settings', JSON.stringify(notificationSettings));
        
        showNotification('✅ Notification preferences saved successfully!', 'success');
    };

    window.savePreferences = function() {
        const theme = document.getElementById('themeSelect').value;
        const language = document.getElementById('languageSelect').value;
        const timezone = document.getElementById('timezoneSelect').value;
        const dateFormat = document.getElementById('dateFormatSelect').value;

        // Save to localStorage (in a real app, this would be sent to the server)
        const preferences = {
            theme,
            language,
            timezone,
            dateFormat
        };

        localStorage.setItem('taskflow_preferences', JSON.stringify(preferences));
        
        showNotification('✅ Preferences saved successfully!', 'success');
    };

    function showMessage(containerId, message, type) {
        const messageContainer = document.getElementById(containerId);
        
        if (messageContainer) {
            messageContainer.className = `message-container message-${type}`;
            messageContainer.textContent = message;
            messageContainer.style.display = 'block';

            // Auto-hide after 5 seconds
            setTimeout(() => {
                messageContainer.style.display = 'none';
            }, 5000);
        }
    }

    function showNotification(message, type) {
        // Create notification element
        const notification = document.createElement('div');
        notification.className = `notification ${type}`;
        notification.textContent = message;
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 16px 24px;
            border-radius: 8px;
            color: white;
            font-weight: 600;
            z-index: 10000;
            animation: slideIn 0.3s ease;
            background: ${type === 'success' ? '#10b981' : '#ef4444'};
        `;

        document.body.appendChild(notification);

        // Remove after 3 seconds
        setTimeout(() => {
            notification.remove();
        }, 3000);
    }

    // Load saved preferences
    function loadSavedPreferences() {
        // Load notification settings
        const savedNotifications = localStorage.getItem('taskflow_notification_settings');
        if (savedNotifications) {
            const settings = JSON.parse(savedNotifications);
            document.getElementById('taskAssigned').checked = settings.taskAssigned;
            document.getElementById('taskCompleted').checked = settings.taskCompleted;
            document.getElementById('deadlineReminder').checked = settings.deadlineReminder;
            document.getElementById('teamUpdates').checked = settings.teamUpdates;
        }

        // Load preferences
        const savedPreferences = localStorage.getItem('taskflow_preferences');
        if (savedPreferences) {
            const preferences = JSON.parse(savedPreferences);
            document.getElementById('themeSelect').value = preferences.theme || 'light';
            document.getElementById('languageSelect').value = preferences.language || 'en';
            document.getElementById('timezoneSelect').value = preferences.timezone || 'UTC';
            document.getElementById('dateFormatSelect').value = preferences.dateFormat || 'MM/DD/YYYY';
        }
    }

    // Load saved preferences on page load
    loadSavedPreferences();

    // Add CSS for notification animation
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(100%);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
    `;
    document.head.appendChild(style);
});