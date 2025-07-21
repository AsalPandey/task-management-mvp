// Tasks Page JavaScript
document.addEventListener('DOMContentLoaded', function() {
    let currentEditingTask = null;
    
    // Initialize page
    initializePage();
    setupEventListeners();
    loadTasks();

    function initializePage() {
        // Set today's date as default for start date
        const startDateInput = document.getElementById('taskStartDate');
        if (startDateInput) {
            startDateInput.value = new Date().toISOString().split('T')[0];
        }
    }

    function setupEventListeners() {
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

        // Filter inputs
        setupFilters();
    }

    function setupFilters() {
        const searchInput = document.getElementById('searchTasks');
        const statusFilter = document.getElementById('statusFilter');
        const priorityFilter = document.getElementById('priorityFilter');
        const assigneeFilter = document.getElementById('assigneeFilter');
        const projectFilter = document.getElementById('projectFilter');

        [searchInput, statusFilter, priorityFilter, assigneeFilter, projectFilter].forEach(input => {
            if (input) {
                input.addEventListener('input', filterTasks);
                input.addEventListener('change', filterTasks);
            }
        });
    }

    function loadTasks() {
        const tasks = window.storageManager.getTasks();
        const tasksGrid = document.getElementById('tasksGrid');
        const emptyState = document.getElementById('emptyState');

        if (tasks.length === 0) {
            tasksGrid.style.display = 'none';
            emptyState.style.display = 'block';
            return;
        }

        tasksGrid.style.display = 'grid';
        emptyState.style.display = 'none';

        renderTasks(tasks);
    }

    function renderTasks(tasks) {
        const tasksGrid = document.getElementById('tasksGrid');
        
        tasksGrid.innerHTML = tasks.map(task => {
            const isOverdue = new Date(task.dueDate) < new Date() && task.status !== 'Completed';
            
            return `
                <div class="task-card priority-${task.priority.toLowerCase()}" data-task-id="${task.id}">
                    <div class="task-header">
                        <div class="task-status-icon">
                            <span class="status-icon ${task.status.toLowerCase().replace(' ', '-')}">${getStatusIcon(task.status)}</span>
                            <span class="task-number">#${task.sn}</span>
                        </div>
                        <div class="task-actions">
                            <span class="priority-badge priority-${task.priority.toLowerCase()}">${task.priority}</span>
                            <button class="task-action-btn delete-btn" onclick="deleteTask('${task.id}')" title="Delete Task">🗑️</button>
                        </div>
                    </div>

                    <h3 class="task-title" onclick="editTask('${task.id}')">${task.toDo}</h3>
                    
                    <div class="task-project">
                        <span class="project-icon">👤</span>
                        <span>${task.project}</span>
                    </div>

                    <div class="task-assignee">
                        <div class="assignee-info">
                            <div class="assignee-avatar">${getInitials(task.assignee)}</div>
                            <span class="assignee-name">${task.assignee}</span>
                        </div>
                        <span class="status-badge status-${task.status.toLowerCase().replace(' ', '-')}">${task.status}</span>
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
        }).join('');
    }

    function filterTasks() {
        const searchTerm = document.getElementById('searchTasks').value.toLowerCase();
        const statusFilter = document.getElementById('statusFilter').value;
        const priorityFilter = document.getElementById('priorityFilter').value;
        const assigneeFilter = document.getElementById('assigneeFilter').value;
        const projectFilter = document.getElementById('projectFilter').value;

        const tasks = window.storageManager.getTasks();
        
        const filteredTasks = tasks.filter(task => {
            const matchesSearch = task.toDo.toLowerCase().includes(searchTerm) ||
                                task.project.toLowerCase().includes(searchTerm) ||
                                task.assignee.toLowerCase().includes(searchTerm);
            const matchesStatus = !statusFilter || task.status === statusFilter;
            const matchesPriority = !priorityFilter || task.priority === priorityFilter;
            const matchesAssignee = !assigneeFilter || task.assignee === assigneeFilter;
            const matchesProject = !projectFilter || task.project === projectFilter;

            return matchesSearch && matchesStatus && matchesPriority && matchesAssignee && matchesProject;
        });

        renderTasks(filteredTasks);

        // Show/hide empty state
        const tasksGrid = document.getElementById('tasksGrid');
        const emptyState = document.getElementById('emptyState');
        
        if (filteredTasks.length === 0) {
            tasksGrid.style.display = 'none';
            emptyState.style.display = 'block';
        } else {
            tasksGrid.style.display = 'grid';
            emptyState.style.display = 'none';
        }
    }

    function openTaskModal(task = null) {
        const modal = document.getElementById('taskModal');
        const form = document.getElementById('taskForm');
        const modalTitle = document.getElementById('modalTitle');
        const submitBtn = document.getElementById('submitBtn');

        currentEditingTask = task;

        if (task) {
            // Edit mode
            modalTitle.textContent = 'Edit Task';
            submitBtn.innerHTML = '💾 Update Task';
            
            // Fill form with task data
            document.getElementById('taskProject').value = task.project;
            document.getElementById('taskPriority').value = task.priority;
            document.getElementById('taskDescription').value = task.toDo;
            document.getElementById('taskAssignee').value = task.assignee;
            document.getElementById('taskStatus').value = task.status;
            document.getElementById('taskStartDate').value = task.startDate;
            document.getElementById('taskDueDate').value = task.dueDate;
            document.getElementById('taskProgress').value = task.progress;
            document.getElementById('progressValue').textContent = task.progress;
            document.getElementById('taskComments').value = task.comments || '';
        } else {
            // Create mode
            modalTitle.textContent = 'Create New Task';
            submitBtn.innerHTML = '➕ Create Task';
            
            // Reset form
            form.reset();
            document.getElementById('progressValue').textContent = '0';
            document.getElementById('taskStartDate').value = new Date().toISOString().split('T')[0];
        }

        modal.classList.add('active');
    }

    function closeTaskModal() {
        const modal = document.getElementById('taskModal');
        modal.classList.remove('active');
        currentEditingTask = null;
    }

    function handleTaskSubmit(e) {
        e.preventDefault();
        
        const taskData = {
            project: document.getElementById('taskProject').value,
            toDo: document.getElementById('taskDescription').value,
            assignee: document.getElementById('taskAssignee').value,
            assignedTo: document.getElementById('taskAssignee').value,
            dueDate: document.getElementById('taskDueDate').value,
            priority: document.getElementById('taskPriority').value,
            status: document.getElementById('taskStatus').value,
            comments: document.getElementById('taskComments').value,
            startDate: document.getElementById('taskStartDate').value,
            progress: parseInt(document.getElementById('taskProgress').value)
        };

        let result;
        if (currentEditingTask) {
            result = window.storageManager.updateTask(currentEditingTask.id, taskData);
        } else {
            result = window.storageManager.addTask(taskData);
        }

        if (result.success) {
            closeTaskModal();
            loadTasks();
            showNotification(
                currentEditingTask ? 'Task updated successfully!' : 'Task created successfully!', 
                'success'
            );
        } else {
            showNotification('Failed to save task: ' + result.message, 'error');
        }
    }

    // Global functions for onclick handlers
    window.editTask = function(taskId) {
        const tasks = window.storageManager.getTasks();
        const task = tasks.find(t => t.id === taskId);
        if (task) {
            openTaskModal(task);
        }
    };

    window.deleteTask = function(taskId) {
        const tasks = window.storageManager.getTasks();
        const task = tasks.find(t => t.id === taskId);
        
        if (task && confirm(`Are you sure you want to delete "${task.toDo}"?`)) {
            const result = window.storageManager.deleteTask(taskId);
            
            if (result.success) {
                loadTasks();
                showNotification('Task deleted successfully!', 'success');
            } else {
                showNotification('Failed to delete task: ' + result.message, 'error');
            }
        }
    };

    window.closeTaskModal = closeTaskModal;

    // Helper functions
    function getStatusIcon(status) {
        switch(status) {
            case 'Completed': return '✅';
            case 'In Progress': return '📈';
            case 'On Hold': return '⏸️';
            default: return '⭕';
        }
    }

    function getInitials(name) {
        return name.split(' ').map(n => n[0]).join('').toUpperCase();
    }

    function showNotification(message, type) {
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