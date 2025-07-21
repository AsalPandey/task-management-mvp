// Projects Page JavaScript
document.addEventListener('DOMContentLoaded', function() {
    let currentEditingProject = null;
    
    // Initialize page
    initializePage();
    setupEventListeners();
    loadProjects();

    function initializePage() {
        // Check if user has permission (manager only)
        const currentUser = window.authSystem.getCurrentUser();
        if (!currentUser || currentUser.role !== 'manager') {
            showMessage('Access denied. Manager role required.', 'error');
            return;
        }
    }

    function setupEventListeners() {
        // New project button
        const newProjectBtn = document.getElementById('newProjectBtn');
        if (newProjectBtn) {
            newProjectBtn.addEventListener('click', openProjectModal);
        }

        // Project form
        const projectForm = document.getElementById('projectForm');
        if (projectForm) {
            projectForm.addEventListener('submit', handleProjectSubmit);
        }

        // Modal close buttons
        const modalCloses = document.querySelectorAll('.modal-close');
        modalCloses.forEach(close => {
            close.addEventListener('click', closeProjectModal);
        });

        // Click outside modal to close
        const modal = document.getElementById('projectModal');
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeProjectModal();
                }
            });
        }

        // Color picker
        setupColorPicker();
    }

    function setupColorPicker() {
        const colorOptions = document.querySelectorAll('.color-option');
        const colorInput = document.getElementById('projectColor');
        
        colorOptions.forEach(option => {
            option.addEventListener('click', function() {
                // Remove selected class from all options
                colorOptions.forEach(opt => opt.classList.remove('selected'));
                
                // Add selected class to clicked option
                this.classList.add('selected');
                
                // Update hidden input value
                if (colorInput) {
                    colorInput.value = this.dataset.color;
                }
            });
        });

        // Set default selection
        if (colorOptions.length > 0) {
            colorOptions[0].classList.add('selected');
        }
    }

    function loadProjects() {
        const projects = window.storageManager.getProjectStats();
        const projectsGrid = document.getElementById('projectsGrid');
        const emptyState = document.getElementById('emptyState');

        if (projects.length === 0) {
            projectsGrid.style.display = 'none';
            emptyState.style.display = 'block';
            return;
        }

        projectsGrid.style.display = 'grid';
        emptyState.style.display = 'none';

        renderProjects(projects);
    }

    function renderProjects(projects) {
        const projectsGrid = document.getElementById('projectsGrid');
        
        projectsGrid.innerHTML = projects.map(project => `
            <div class="project-card" data-project-id="${project.id}" style="border-left-color: ${project.color};">
                <div class="project-header">
                    <div class="project-info">
                        <h3>${project.name}</h3>
                        <p>${project.description || 'No description provided'}</p>
                    </div>
                    <div class="project-actions">
                        <span class="status-badge status-${project.status.toLowerCase().replace(' ', '-')}">${project.status}</span>
                        <button class="action-btn edit-btn" onclick="editProject('${project.id}')" title="Edit Project">✏️</button>
                        <button class="action-btn delete-btn" onclick="deleteProject('${project.id}')" title="Delete Project">🗑️</button>
                    </div>
                </div>

                <div class="project-progress">
                    <div class="progress-header">
                        <span>Progress</span>
                        <span>${project.progress}%</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: ${project.progress}%; background-color: ${project.color};"></div>
                    </div>
                </div>

                <div class="project-stats">
                    <div class="stat-item">
                        <div class="stat-icon">📊</div>
                        <div class="stat-info">
                            <span class="stat-value">${project.taskCount}</span>
                            <span class="stat-label">Tasks</span>
                        </div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-icon">✅</div>
                        <div class="stat-info">
                            <span class="stat-value">${project.completedTasks}</span>
                            <span class="stat-label">Done</span>
                        </div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-icon">⚠️</div>
                        <div class="stat-info">
                            <span class="stat-value">${project.overdueTasks}</span>
                            <span class="stat-label">Overdue</span>
                        </div>
                    </div>
                </div>

                <div class="project-footer">
                    <div class="project-date">
                        <span class="date-icon">📅</span>
                        <span>Created ${new Date(project.createdAt).toLocaleDateString()}</span>
                    </div>
                </div>
            </div>
        `).join('');
    }

    function openProjectModal(project = null) {
        const modal = document.getElementById('projectModal');
        const form = document.getElementById('projectForm');
        const modalTitle = document.getElementById('modalTitle');
        const submitBtn = document.getElementById('submitBtn');

        currentEditingProject = project;

        if (project) {
            // Edit mode
            modalTitle.textContent = 'Edit Project';
            submitBtn.innerHTML = '💾 Update Project';
            
            // Fill form with project data
            document.getElementById('projectName').value = project.name;
            document.getElementById('projectDescription').value = project.description || '';
            document.getElementById('projectColor').value = project.color;
            document.getElementById('projectStatus').value = project.status;
            
            // Update color picker selection
            const colorOptions = document.querySelectorAll('.color-option');
            colorOptions.forEach(option => {
                option.classList.remove('selected');
                if (option.dataset.color === project.color) {
                    option.classList.add('selected');
                }
            });
        } else {
            // Create mode
            modalTitle.textContent = 'Create New Project';
            submitBtn.innerHTML = '➕ Create Project';
            
            // Reset form
            form.reset();
            document.getElementById('projectColor').value = '#3B82F6';
            
            // Reset color picker
            const colorOptions = document.querySelectorAll('.color-option');
            colorOptions.forEach(option => option.classList.remove('selected'));
            if (colorOptions.length > 0) {
                colorOptions[0].classList.add('selected');
            }
        }

        modal.classList.add('active');
    }

    function closeProjectModal() {
        const modal = document.getElementById('projectModal');
        modal.classList.remove('active');
        currentEditingProject = null;
    }

    function handleProjectSubmit(e) {
        e.preventDefault();
        
        const projectData = {
            name: document.getElementById('projectName').value,
            description: document.getElementById('projectDescription').value,
            color: document.getElementById('projectColor').value,
            status: document.getElementById('projectStatus').value
        };

        let result;
        if (currentEditingProject) {
            result = window.storageManager.updateProject(currentEditingProject.id, projectData);
        } else {
            result = window.storageManager.addProject(projectData);
        }

        if (result.success) {
            closeProjectModal();
            loadProjects();
            showMessage(
                currentEditingProject ? 'Project updated successfully!' : 'Project created successfully!', 
                'success'
            );
        } else {
            showMessage('Failed to save project: ' + result.message, 'error');
        }
    }

    // Global functions for onclick handlers
    window.editProject = function(projectId) {
        const projects = window.storageManager.getProjects();
        const project = projects.find(p => p.id === projectId);
        if (project) {
            openProjectModal(project);
        }
    };

    window.deleteProject = function(projectId) {
        const projects = window.storageManager.getProjects();
        const project = projects.find(p => p.id === projectId);
        
        if (project && confirm(`Are you sure you want to delete "${project.name}"?`)) {
            const result = window.storageManager.deleteProject(projectId);
            
            if (result.success) {
                loadProjects();
                showMessage('Project deleted successfully!', 'success');
            } else {
                showMessage('Failed to delete project: ' + result.message, 'error');
            }
        }
    };

    window.closeProjectModal = closeProjectModal;

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