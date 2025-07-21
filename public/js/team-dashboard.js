// Team Dashboard JavaScript
document.addEventListener('DOMContentLoaded', function() {
    // Check authentication
    if (!window.authSystem.requireAuth()) {
        return;
    }

    const currentUser = window.authSystem.getCurrentUser();
    
    // Redirect managers to manager dashboard
    if (currentUser.role === 'manager') {
        window.location.href = 'manager-dashboard.html';
        return;
    }

    // Initialize dashboard
    initializeDashboard();
    setupEventListeners();

    function initializeDashboard() {
        // Update user info in header
        const userNameSpan = document.querySelector('.user-info span');
        const userAvatar = document.querySelector('.user-avatar');
        const welcomeName = document.getElementById('welcomeName');
        const userName = document.getElementById('userName');
        
        if (userNameSpan) userNameSpan.textContent = currentUser.name;
        if (userName) userName.textContent = currentUser.name;
        if (welcomeName) welcomeName.textContent = currentUser.name;
        if (userAvatar) {
            userAvatar.textContent = currentUser.name.split(' ').map(n => n[0]).join('').toUpperCase();
        }

        // Load dashboard data
        loadDashboardData();
    }

    function setupEventListeners() {
        // Tab navigation
        const navTabs = document.querySelectorAll('.nav-tab');
        navTabs.forEach(tab => {
            tab.addEventListener('click', function() {
                const tabName = this.dataset.tab;
                switchTab(tabName);
            });
        });

        // Logout button
        const logoutBtn = document.getElementById('logoutBtn');
        if (logoutBtn) {
            logoutBtn.addEventListener('click', function() {
                if (confirm('Are you sure you want to logout?')) {
                    window.authSystem.logout();
                }
            });
        }

        // Update progress buttons
        const updateButtons = document.querySelectorAll('.btn-small');
        updateButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                // Simulate task update
                showNotification('Task progress updated!', 'success');
            });
        });
    }

    function loadDashboardData() {
        // Get user's tasks
        const userTasks = window.storageManager.getTasksByAssignee(currentUser.name);
        
        // Calculate user stats
        const totalTasks = userTasks.length;
        const completedTasks = userTasks.filter(t => t.status === 'Completed').length;
        const inProgressTasks = userTasks.filter(t => t.status === 'In Progress').length;
        const overdueTasks = userTasks.filter(t => 
            new Date(t.dueDate) < new Date() && t.status !== 'Completed'
        ).length;
        const averageProgress = totalTasks > 0 
            ? Math.round(userTasks.reduce((sum, task) => sum + task.progress, 0) / totalTasks)
            : 0;

        // Update metrics
        document.getElementById('myTotalTasks').textContent = totalTasks;
        document.getElementById('myCompletedTasks').textContent = completedTasks;
        document.getElementById('myInProgressTasks').textContent = inProgressTasks;
        document.getElementById('myOverdueTasks').textContent = overdueTasks;

        // Update completion rate
        const completionRate = totalTasks > 0 
            ? Math.round((completedTasks / totalTasks) * 100) 
            : 0;
        
        const completedCard = document.querySelector('.metric-card.green .metric-subtitle');
        if (completedCard) {
            completedCard.textContent = `${completionRate}% completion rate`;
        }

        // Update progress bar
        const progressFill = document.querySelector('.progress-fill');
        const progressPercentage = document.querySelector('.progress-percentage');
        if (progressFill && progressPercentage) {
            progressFill.style.width = `${averageProgress}%`;
            progressPercentage.textContent = `${averageProgress}%`;
        }

        // Update progress description
        const progressDescription = document.querySelector('.progress-card p');
        if (progressDescription) {
            progressDescription.textContent = `Average completion across ${totalTasks} ${totalTasks === 1 ? 'task' : 'tasks'}`;
        }
    }

    function switchTab(tabName) {
        // Update active tab
        document.querySelectorAll('.nav-tab').forEach(tab => {
            tab.classList.remove('active');
        });
        document.querySelector(`[data-tab="${tabName}"]`).classList.add('active');

        // Update active content
        document.querySelectorAll('.tab-content').forEach(content => {
            content.classList.remove('active');
        });
        document.getElementById(tabName).classList.add('active');

        // Load content based on tab
        switch(tabName) {
            case 'tasks':
                loadMyTasksPage();
                break;
            case 'analytics':
                loadMyAnalyticsPage();
                break;
            case 'settings':
                loadMySettingsPage();
                break;
        }
    }

    function loadMyTasksPage() {
        const tasksContent = document.getElementById('tasks');
        tasksContent.innerHTML = '<div class="loading">Loading my tasks...</div>';
        
        setTimeout(() => {
            const userTasks = window.storageManager.getTasksByAssignee(currentUser.name);
            
            if (userTasks.length === 0) {
                tasksContent.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-icon">📋</div>
                        <h3>No tasks assigned</h3>
                        <p>You don't have any tasks assigned to you yet.</p>
                    </div>
                `;
                return;
            }

            let tasksHTML = `
                <div class="page-header">
                    <h2>My Tasks</h2>
                    <p>Tasks assigned to you</p>
                </div>
                <div class="tasks-grid">
            `;

            userTasks.forEach(task => {
                const isOverdue = new Date(task.dueDate) < new Date() && task.status !== 'Completed';
                tasksHTML += `
                    <div class="task-card priority-${task.priority.toLowerCase()}" data-task-id="${task.id}">
                        <div class="task-header">
                            <div class="task-status-icon">
                                <span class="status-icon ${task.status.toLowerCase().replace(' ', '-')}">${getStatusIcon(task.status)}</span>
                                <span class="task-number">#${task.sn}</span>
                            </div>
                            <div class="task-actions">
                                <span class="priority-badge priority-${task.priority.toLowerCase()}">${task.priority}</span>
                            </div>
                        </div>
                        <h3 class="task-title">${task.toDo}</h3>
                        <div class="task-project">
                            <span class="project-icon">👤</span>
                            <span>${task.project}</span>
                        </div>
                        <div class="task-progress">
                            <div class="progress-header">
                                <span>Progress</span>
                                <span>${task.progress}%</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: ${task.progress}%"></div>
                            </div>
                        </div>
                        <div class="task-dates">
                            <div class="date-item">
                                <span class="date-icon">📅</span>
                                <span class="${isOverdue ? 'overdue' : ''}">${new Date(task.dueDate).toLocaleDateString()}</span>
                            </div>
                            <div class="date-item">
                                <span class="date-icon">🕐</span>
                                <span>Updated ${new Date(task.updatedAt).toLocaleDateString()}</span>
                            </div>
                        </div>
                        ${task.comments ? `
                            <div class="task-comments">
                                <p>${task.comments}</p>
                            </div>
                        ` : ''}
                        ${isOverdue ? `
                            <div class="overdue-warning">
                                <p>⚠️ Overdue</p>
                            </div>
                        ` : ''}
                    </div>
                `;
            });

            tasksHTML += '</div>';
            tasksContent.innerHTML = tasksHTML;
        }, 500);
    }

    function loadMyAnalyticsPage() {
        const analyticsContent = document.getElementById('analytics');
        analyticsContent.innerHTML = '<div class="loading">Loading analytics...</div>';
        
        setTimeout(() => {
            analyticsContent.innerHTML = `
                <div class="page-header">
                    <h2>My Performance</h2>
                    <p>Personal productivity dashboard for ${currentUser.name}</p>
                </div>
                <div class="analytics-content">
                    <div class="chart-card">
                        <h3>📈 My Performance Insights</h3>
                        <p>Personal analytics coming soon...</p>
                    </div>
                </div>
            `;
        }, 500);
    }

    function loadMySettingsPage() {
        const settingsContent = document.getElementById('settings');
        settingsContent.innerHTML = '<div class="loading">Loading settings...</div>';
        
        setTimeout(() => {
            settingsContent.innerHTML = `
                <div class="page-header">
                    <h2>Settings</h2>
                    <p>Manage your account and preferences</p>
                </div>
                <div class="settings-content">
                    <div class="card" style="padding: 24px;">
                        <h3>Profile Settings</h3>
                        <div class="form-group">
                            <label>Name</label>
                            <input type="text" value="${currentUser.name}" readonly>
                        </div>
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" value="${currentUser.email}" readonly>
                        </div>
                        <div class="form-group">
                            <label>Role</label>
                            <input type="text" value="Team Member" readonly>
                        </div>
                        <p style="color: #6b7280; font-size: 14px; margin-top: 16px;">
                            Contact your manager to update your profile information.
                        </p>
                    </div>
                </div>
            `;
        }, 500);
    }

    function getStatusIcon(status) {
        switch(status) {
            case 'Completed': return '✅';
            case 'In Progress': return '📈';
            case 'On Hold': return '⏸️';
            default: return '⭕';
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
        .tasks-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 24px;
            margin-top: 24px;
        }
    `;
    document.head.appendChild(style);
});