// Manager Dashboard JavaScript
document.addEventListener('DOMContentLoaded', function() {
    // Check authentication and role
    if (!window.authSystem.requireAuth() || !window.authSystem.requireManagerRole()) {
        return;
    }

    const currentUser = window.authSystem.getCurrentUser();
    
    // Initialize dashboard
    initializeDashboard();
    setupEventListeners();
    updateLastUpdated();

    function initializeDashboard() {
        // Update user info in header
        const userNameSpan = document.querySelector('.user-info span');
        const userAvatar = document.querySelector('.user-avatar');
        
        if (userNameSpan) userNameSpan.textContent = currentUser.name;
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

        // New task button
        const newTaskBtn = document.getElementById('newTaskBtn');
        if (newTaskBtn) {
            newTaskBtn.addEventListener('click', openTaskModal);
        }

        // Task form
        const taskForm = document.getElementById('taskForm');
        if (taskForm) {
            taskForm.addEventListener('submit', handleTaskSubmit);
        }

        // Progress slider
        const progressSlider = document.getElementById('taskProgress');
        const progressValue = document.getElementById('progressValue');
        if (progressSlider && progressValue) {
            progressSlider.addEventListener('input', function() {
                progressValue.textContent = this.value;
            });
        }

        // Modal close buttons
        const modalCloses = document.querySelectorAll('.modal-close');
        modalCloses.forEach(close => {
            close.addEventListener('click', closeTaskModal);
        });

        // Click outside modal to close
        const modal = document.getElementById('taskModal');
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeTaskModal();
                }
            });
        }
    }

    function loadDashboardData() {
        const stats = window.storageManager.getDashboardStats();
        
        // Update metrics
        document.getElementById('totalTasks').textContent = stats.totalTasks;
        document.getElementById('completedTasks').textContent = stats.completedTasks;
        document.getElementById('inProgressTasks').textContent = stats.inProgressTasks;
        document.getElementById('overdueTasks').textContent = stats.overdueTasks;

        // Update completion rate
        const completionRate = stats.totalTasks > 0 
            ? Math.round((stats.completedTasks / stats.totalTasks) * 100) 
            : 0;
        
        const completedCard = document.querySelector('.metric-card.green .metric-subtitle');
        if (completedCard) {
            completedCard.textContent = `${completionRate}% completion rate`;
        }

        // Update progress bar
        const progressFill = document.querySelector('.progress-fill');
        const progressPercentage = document.querySelector('.progress-percentage');
        if (progressFill && progressPercentage) {
            progressFill.style.width = `${stats.averageProgress}%`;
            progressPercentage.textContent = `${stats.averageProgress}%`;
        }

        // Update progress description
        const progressDescription = document.querySelector('.progress-card p');
        if (progressDescription) {
            progressDescription.textContent = `Average completion across ${stats.totalTasks} ${stats.totalTasks === 1 ? 'task' : 'tasks'}`;
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
                loadTasksPage();
                break;
            case 'projects':
                loadProjectsPage();
                break;
            case 'analytics':
                loadAnalyticsPage();
                break;
            case 'team':
                loadTeamPage();
                break;
            case 'settings':
                loadSettingsPage();
                break;
        }
    }

    function loadTasksPage() {
        const tasksContent = document.getElementById('tasks');
        tasksContent.innerHTML = '<iframe src="tasks.html" style="width: 100%; height: 80vh; border: none; border-radius: 8px;"></iframe>';
    }

    function loadProjectsPage() {
        const projectsContent = document.getElementById('projects');
        projectsContent.innerHTML = '<div class="loading">Loading projects...</div>';
        
        // Simulate loading
        setTimeout(() => {
            projectsContent.innerHTML = `
                <div class="page-header">
                    <h2>Projects</h2>
                    <p>Manage and track your project portfolio</p>
                </div>
                <div class="projects-grid">
                    <div class="project-card">
                        <h3>Website Revamp</h3>
                        <p>Complete redesign of company website</p>
                        <div class="project-progress">
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: 65%; background: #3B82F6;"></div>
                            </div>
                            <span>65%</span>
                        </div>
                    </div>
                    <div class="project-card">
                        <h3>Marketing Campaign</h3>
                        <p>Q1 marketing initiatives</p>
                        <div class="project-progress">
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: 40%; background: #10B981;"></div>
                            </div>
                            <span>40%</span>
                        </div>
                    </div>
                </div>
            `;
        }, 500);
    }

    function loadAnalyticsPage() {
        const analyticsContent = document.getElementById('analytics');
        analyticsContent.innerHTML = '<div class="loading">Loading analytics...</div>';
        
        setTimeout(() => {
            analyticsContent.innerHTML = `
                <div class="page-header">
                    <h2>Team Analytics</h2>
                    <p>Comprehensive team performance insights</p>
                </div>
                <div class="analytics-content">
                    <div class="chart-card">
                        <h3>📈 Performance Trends</h3>
                        <p>Detailed analytics coming soon...</p>
                    </div>
                </div>
            `;
        }, 500);
    }

    function loadTeamPage() {
        const teamContent = document.getElementById('team');
        teamContent.innerHTML = '<iframe src="team-management.html" style="width: 100%; height: 80vh; border: none; border-radius: 8px;"></iframe>';
    }

    function loadSettingsPage() {
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
                        <p>Settings functionality coming soon...</p>
                    </div>
                </div>
            `;
        }, 500);
    }

    function openTaskModal() {
        const modal = document.getElementById('taskModal');
        const form = document.getElementById('taskForm');
        
        // Reset form
        form.reset();
        document.getElementById('progressValue').textContent = '0';
        document.getElementById('taskStartDate').value = new Date().toISOString().split('T')[0];
        
        // Show modal
        modal.classList.add('active');
    }

    function closeTaskModal() {
        const modal = document.getElementById('taskModal');
        modal.classList.remove('active');
    }

    function handleTaskSubmit(e) {
        e.preventDefault();
        
        const formData = new FormData(e.target);
        const taskData = {
            project: formData.get('project') || document.getElementById('taskProject').value,
            toDo: formData.get('description') || document.getElementById('taskDescription').value,
            assignee: formData.get('assignee') || document.getElementById('taskAssignee').value,
            assignedTo: formData.get('assignee') || document.getElementById('taskAssignee').value,
            dueDate: formData.get('dueDate') || document.getElementById('taskDueDate').value,
            priority: formData.get('priority') || document.getElementById('taskPriority').value,
            status: formData.get('status') || document.getElementById('taskStatus').value,
            comments: formData.get('comments') || document.getElementById('taskComments').value,
            startDate: formData.get('startDate') || document.getElementById('taskStartDate').value,
            progress: parseInt(formData.get('progress') || document.getElementById('taskProgress').value)
        };

        const result = window.storageManager.addTask(taskData);
        
        if (result.success) {
            closeTaskModal();
            loadDashboardData(); // Refresh dashboard
            showNotification('Task created successfully!', 'success');
        } else {
            showNotification('Failed to create task: ' + result.message, 'error');
        }
    }

    function updateLastUpdated() {
        const lastUpdatedElement = document.getElementById('lastUpdated');
        if (lastUpdatedElement) {
            lastUpdatedElement.textContent = new Date().toLocaleDateString();
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
    `;
    document.head.appendChild(style);
});