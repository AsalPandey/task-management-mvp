// Analytics Page JavaScript
document.addEventListener('DOMContentLoaded', function() {
    // Check authentication
    if (!window.authSystem.requireAuth()) {
        return;
    }

    const currentUser = window.authSystem.getCurrentUser();
    
    // Initialize analytics
    initializeAnalytics();
    loadAnalyticsData();

    function initializeAnalytics() {
        // Update page title based on user role
        const analyticsTitle = document.getElementById('analyticsTitle');
        const analyticsSubtitle = document.getElementById('analyticsSubtitle');
        
        if (currentUser.role === 'team') {
            if (analyticsTitle) analyticsTitle.textContent = 'My Performance';
            if (analyticsSubtitle) analyticsSubtitle.textContent = `Personal productivity dashboard for ${currentUser.name}`;
            
            // Hide team performance section for team members
            const teamPerformanceSection = document.getElementById('teamPerformanceSection');
            if (teamPerformanceSection) {
                teamPerformanceSection.style.display = 'none';
            }
        }

        // Update last updated timestamp
        const lastUpdated = document.getElementById('lastUpdated');
        if (lastUpdated) {
            lastUpdated.textContent = new Date().toLocaleDateString();
        }
    }

    function loadAnalyticsData() {
        // Get data based on user role
        let tasks, stats;
        
        if (currentUser.role === 'manager') {
            tasks = window.storageManager.getTasks();
            stats = window.storageManager.getDashboardStats();
        } else {
            tasks = window.storageManager.getTasksByAssignee(currentUser.name);
            stats = calculateUserStats(tasks);
        }

        // Update metrics
        updateMetrics(stats, tasks);
        
        // Update charts
        updatePriorityChart(tasks);
        updateProductivityChart(tasks);
        
        // Update insights
        updateInsights(stats, tasks);
        
        // Update team performance (manager only)
        if (currentUser.role === 'manager') {
            updateTeamPerformance();
        }
        
        // Remove all project-related JS logic and UI
    }

    function calculateUserStats(userTasks) {
        const totalTasks = userTasks.length;
        const completedTasks = userTasks.filter(t => t.status === 'Completed').length;
        const inProgressTasks = userTasks.filter(t => t.status === 'In Progress').length;
        const overdueTasks = userTasks.filter(t => 
            new Date(t.dueDate) < new Date() && t.status !== 'Completed'
        ).length;
        const averageProgress = totalTasks > 0 
            ? Math.round(userTasks.reduce((sum, task) => sum + task.progress, 0) / totalTasks)
            : 0;

        return {
            totalTasks,
            completedTasks,
            inProgressTasks,
            overdueTasks,
            averageProgress
        };
    }

    function updateMetrics(stats, tasks) {
        // Total Tasks
        const totalTasksMetric = document.getElementById('totalTasksMetric');
        const totalTasksSubtitle = document.getElementById('totalTasksSubtitle');
        if (totalTasksMetric) totalTasksMetric.textContent = stats.totalTasks;
        if (totalTasksSubtitle) {
            totalTasksSubtitle.textContent = currentUser.role === 'manager' 
                ? 'Across all projects' 
                : 'Assigned to you';
        }

        // Completion Rate
        const completionRateMetric = document.getElementById('completionRateMetric');
        const completionRateSubtitle = document.getElementById('completionRateSubtitle');
        const completionRate = stats.totalTasks > 0 
            ? Math.round((stats.completedTasks / stats.totalTasks) * 100)
            : 0;
        if (completionRateMetric) completionRateMetric.textContent = `${completionRate}%`;
        if (completionRateSubtitle) {
            completionRateSubtitle.textContent = `${stats.completedTasks} of ${stats.totalTasks} completed`;
        }

        // Average Progress
        const avgProgressMetric = document.getElementById('avgProgressMetric');
        if (avgProgressMetric) avgProgressMetric.textContent = `${stats.averageProgress}%`;

        // Overdue Tasks
        const overdueTasksMetric = document.getElementById('overdueTasksMetric');
        const overdueSubtitle = document.getElementById('overdueSubtitle');
        if (overdueTasksMetric) overdueTasksMetric.textContent = stats.overdueTasks;
        if (overdueSubtitle) {
            overdueSubtitle.textContent = stats.overdueTasks > 0 ? 'Needs attention' : 'All on track!';
        }
    }

    function updatePriorityChart(tasks) {
        const priorityCounts = { High: 0, Medium: 0, Low: 0 };
        tasks.forEach(task => {
            priorityCounts[task.priority]++;
        });

        const totalTasks = tasks.length;
        const priorityChart = document.getElementById('priorityChart');
        
        if (priorityChart && totalTasks > 0) {
            priorityChart.innerHTML = `
                <div class="priority-item">
                    <div class="priority-indicator high"></div>
                    <span class="priority-label">High Priority</span>
                    <div class="priority-bar">
                        <div class="priority-fill high" style="width: ${(priorityCounts.High / totalTasks) * 100}%"></div>
                    </div>
                    <span class="priority-count">${priorityCounts.High}</span>
                </div>
                <div class="priority-item">
                    <div class="priority-indicator medium"></div>
                    <span class="priority-label">Medium Priority</span>
                    <div class="priority-bar">
                        <div class="priority-fill medium" style="width: ${(priorityCounts.Medium / totalTasks) * 100}%"></div>
                    </div>
                    <span class="priority-count">${priorityCounts.Medium}</span>
                </div>
                <div class="priority-item">
                    <div class="priority-indicator low"></div>
                    <span class="priority-label">Low Priority</span>
                    <div class="priority-bar">
                        <div class="priority-fill low" style="width: ${(priorityCounts.Low / totalTasks) * 100}%"></div>
                    </div>
                    <span class="priority-count">${priorityCounts.Low}</span>
                </div>
            `;
        }
    }

    function updateProductivityChart(tasks) {
        // Generate 7-day productivity data
        const last7Days = Array.from({ length: 7 }, (_, i) => {
            const date = new Date();
            date.setDate(date.getDate() - (6 - i));
            return {
                date: date.toISOString().split('T')[0],
                dayName: date.toLocaleDateString('en-US', { weekday: 'short' }),
                completed: Math.floor(Math.random() * 6) // Simulated data
            };
        });

        const maxCompleted = Math.max(...last7Days.map(d => d.completed), 1);
        const productivityChart = document.getElementById('productivityChart');
        
        if (productivityChart) {
            productivityChart.innerHTML = last7Days.map(day => `
                <div class="productivity-day">
                    <span class="day-label">${day.dayName}</span>
                    <div class="productivity-bar">
                        <div class="productivity-fill" style="width: ${(day.completed / maxCompleted) * 100}%"></div>
                    </div>
                    <span class="day-count">${day.completed}</span>
                </div>
            `).join('');
        }
    }

    function updateInsights(stats, tasks) {
        const achievements = document.getElementById('achievements');
        const improvements = document.getElementById('improvements');

        // Generate achievements
        const achievementsList = [];
        if (stats.completedTasks > 0) {
            achievementsList.push(`Completed ${stats.completedTasks} tasks successfully`);
        }
        if (stats.averageProgress >= 75) {
            achievementsList.push(`Maintaining ${stats.averageProgress}% average progress rate`);
        }
        if (stats.overdueTasks === 0 && stats.totalTasks > 0) {
            achievementsList.push('All tasks are on schedule');
        }

        if (achievements) {
            achievements.innerHTML = achievementsList.map(achievement => `
                <div class="achievement-item">
                    <span class="achievement-icon">✅</span>
                    <span>${achievement}</span>
                </div>
            `).join('');
        }

        // Generate improvements
        const improvementsList = [];
        if (stats.overdueTasks > 0) {
            improvementsList.push(`Focus on ${stats.overdueTasks} overdue task${stats.overdueTasks > 1 ? 's' : ''}`);
        }
        if (stats.averageProgress < 50 && stats.totalTasks > 0) {
            improvementsList.push('Consider breaking down complex tasks');
        }
        
        const priorityCounts = { High: 0, Medium: 0, Low: 0 };
        tasks.forEach(task => priorityCounts[task.priority]++);
        if (priorityCounts.High > priorityCounts.Medium + priorityCounts.Low) {
            improvementsList.push('Balance high-priority task load');
        }

        if (improvements) {
            improvements.innerHTML = improvementsList.map(improvement => `
                <div class="improvement-item">
                    <span class="improvement-icon">⚠️</span>
                    <span>${improvement}</span>
                </div>
            `).join('');
        }
    }

    function updateTeamPerformance() {
        const teamMembers = window.authSystem.getTeamMembers();
        const allTasks = window.storageManager.getTasks();
        const teamPerformanceGrid = document.getElementById('teamPerformanceGrid');

        if (teamPerformanceGrid) {
            teamPerformanceGrid.innerHTML = teamMembers.map(member => {
                const memberTasks = allTasks.filter(task => task.assignee === member.name);
                const totalTasks = memberTasks.length;
                const completedTasks = memberTasks.filter(task => task.status === 'Completed').length;
                const overdueTasks = memberTasks.filter(task => 
                    new Date(task.dueDate) < new Date() && task.status !== 'Completed'
                ).length;
                const completionRate = totalTasks > 0 ? Math.round((completedTasks / totalTasks) * 100) : 0;

                return `
                    <div class="team-member-performance">
                        <div class="member-header">
                            <div class="member-avatar">${member.name.split(' ').map(n => n[0]).join('').toUpperCase()}</div>
                            <div class="member-info">
                                <h4>${member.name}</h4>
                                <p>${completionRate}% completion rate</p>
                            </div>
                        </div>
                        <div class="performance-stats">
                            <div class="stat-row">
                                <span class="stat-label">Total Tasks:</span>
                                <span class="stat-value">${totalTasks}</span>
                            </div>
                            <div class="stat-row">
                                <span class="stat-label">Completed:</span>
                                <span class="stat-value completed">${completedTasks}</span>
                            </div>
                            <div class="stat-row">
                                <span class="stat-label">Overdue:</span>
                                <span class="stat-value overdue">${overdueTasks}</span>
                            </div>
                        </div>
                        <div class="completion-bar">
                            <div class="completion-fill" style="width: ${completionRate}%"></div>
                        </div>
                    </div>
                `;
            }).join('');
        }
    }

    // Remove all project-related JS logic and UI
});