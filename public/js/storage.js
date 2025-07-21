// Storage Management System
class StorageManager {
    constructor() {
        this.tasksKey = 'taskflow_tasks';
        this.projectsKey = 'taskflow_projects';
        this.initializeDefaultData();
    }

    initializeDefaultData() {
        // Initialize default tasks if not exists
        if (!localStorage.getItem(this.tasksKey)) {
            const defaultTasks = [
                {
                    id: '1',
                    sn: 1,
                    project: 'Website Revamp',
                    toDo: 'Design new homepage layout',
                    assignee: 'Achyut',
                    assignedTo: 'Achyut',
                    dueDate: '2024-01-15',
                    priority: 'High',
                    status: 'In Progress',
                    comments: 'Working on wireframes and mockups',
                    startDate: '2024-01-01',
                    progress: 60,
                    createdAt: '2024-01-01T10:00:00Z',
                    updatedAt: '2024-01-08T14:30:00Z',
                },
                {
                    id: '2',
                    sn: 2,
                    project: 'Marketing Campaign',
                    toDo: 'Create social media content calendar',
                    assignee: 'Sarah Johnson',
                    assignedTo: 'Sarah Johnson',
                    dueDate: '2024-01-20',
                    priority: 'Medium',
                    status: 'Not Started',
                    comments: 'Need to coordinate with design team',
                    startDate: '2024-01-10',
                    progress: 0,
                    createdAt: '2024-01-02T11:00:00Z',
                    updatedAt: '2024-01-02T11:00:00Z',
                },
                {
                    id: '3',
                    sn: 3,
                    project: 'Sales Platform',
                    toDo: 'Implement user authentication system',
                    assignee: 'Aniket',
                    assignedTo: 'Aniket',
                    dueDate: '2024-01-12',
                    priority: 'High',
                    status: 'Completed',
                    comments: 'OAuth integration completed successfully',
                    startDate: '2023-12-28',
                    progress: 100,
                    createdAt: '2023-12-28T09:00:00Z',
                    updatedAt: '2024-01-05T16:45:00Z',
                },
                {
                    id: '4',
                    sn: 4,
                    project: 'Mobile App',
                    toDo: 'Set up development environment',
                    assignee: 'Mike Chen',
                    assignedTo: 'Mike Chen',
                    dueDate: '2024-01-08',
                    priority: 'High',
                    status: 'On Hold',
                    comments: 'Waiting for approval from stakeholders',
                    startDate: '2024-01-03',
                    progress: 30,
                    createdAt: '2024-01-03T08:00:00Z',
                    updatedAt: '2024-01-07T12:00:00Z',
                },
                {
                    id: '5',
                    sn: 5,
                    project: 'Data Analytics',
                    toDo: 'Design database schema',
                    assignee: 'Bishal',
                    assignedTo: 'Bishal',
                    dueDate: '2024-01-25',
                    priority: 'Low',
                    status: 'In Progress',
                    comments: 'Working on initial data model',
                    startDate: '2024-01-05',
                    progress: 40,
                    createdAt: '2024-01-05T13:00:00Z',
                    updatedAt: '2024-01-09T10:15:00Z',
                },
                {
                    id: '6',
                    sn: 6,
                    project: 'Website Revamp',
                    toDo: 'Implement responsive navigation',
                    assignee: 'Aniket',
                    assignedTo: 'Aniket',
                    dueDate: '2024-01-18',
                    priority: 'Medium',
                    status: 'Not Started',
                    comments: 'Depends on homepage design completion',
                    startDate: '2024-01-15',
                    progress: 0,
                    createdAt: '2024-01-06T14:00:00Z',
                    updatedAt: '2024-01-06T14:00:00Z',
                }
            ];
            localStorage.setItem(this.tasksKey, JSON.stringify(defaultTasks));
        }

        // Initialize default projects if not exists
        if (!localStorage.getItem(this.projectsKey)) {
            const defaultProjects = [
                {
                    id: '1',
                    name: 'Website Revamp',
                    color: '#3B82F6',
                    description: 'Complete redesign of company website',
                    createdAt: '2024-01-01T00:00:00Z',
                    managerId: 'manager1',
                    status: 'Active',
                    progress: 65,
                    taskCount: 3
                },
                {
                    id: '2',
                    name: 'Marketing Campaign',
                    color: '#10B981',
                    description: 'Q1 marketing initiatives',
                    createdAt: '2024-01-02T00:00:00Z',
                    managerId: 'manager1',
                    status: 'Active',
                    progress: 40,
                    taskCount: 2
                },
                {
                    id: '3',
                    name: 'Sales Platform',
                    color: '#F59E0B',
                    description: 'CRM system development',
                    createdAt: '2024-01-03T00:00:00Z',
                    managerId: 'manager1',
                    status: 'Active',
                    progress: 80,
                    taskCount: 4
                },
                {
                    id: '4',
                    name: 'Mobile App',
                    color: '#EF4444',
                    description: 'iOS and Android app development',
                    createdAt: '2024-01-04T00:00:00Z',
                    managerId: 'manager1',
                    status: 'On Hold',
                    progress: 25,
                    taskCount: 2
                },
                {
                    id: '5',
                    name: 'Data Analytics',
                    color: '#8B5CF6',
                    description: 'Business intelligence dashboard',
                    createdAt: '2024-01-05T00:00:00Z',
                    managerId: 'manager1',
                    status: 'Active',
                    progress: 55,
                    taskCount: 3
                }
            ];
            localStorage.setItem(this.projectsKey, JSON.stringify(defaultProjects));
        }
    }

    // Task Management
    getTasks() {
        try {
            return JSON.parse(localStorage.getItem(this.tasksKey) || '[]');
        } catch (error) {
            console.error('Error getting tasks:', error);
            return [];
        }
    }

    saveTasks(tasks) {
        try {
            localStorage.setItem(this.tasksKey, JSON.stringify(tasks));
            return true;
        } catch (error) {
            console.error('Error saving tasks:', error);
            return false;
        }
    }

    addTask(taskData) {
        try {
            const tasks = this.getTasks();
            const newTask = {
                id: Date.now().toString(),
                sn: tasks.length + 1,
                createdAt: new Date().toISOString(),
                updatedAt: new Date().toISOString(),
                assignedTo: taskData.assignee || '',
                ...taskData
            };

            tasks.push(newTask);
            this.saveTasks(tasks);
            
            // Update project task count
            this.updateProjectTaskCount(taskData.project, 1);
            
            return { success: true, task: newTask };
        } catch (error) {
            console.error('Error adding task:', error);
            return { success: false, message: error.message };
        }
    }

    updateTask(taskId, updates) {
        try {
            const tasks = this.getTasks();
            const taskIndex = tasks.findIndex(t => t.id === taskId);
            
            if (taskIndex === -1) {
                throw new Error('Task not found');
            }

            tasks[taskIndex] = {
                ...tasks[taskIndex],
                ...updates,
                updatedAt: new Date().toISOString()
            };

            this.saveTasks(tasks);
            return { success: true, task: tasks[taskIndex] };
        } catch (error) {
            console.error('Error updating task:', error);
            return { success: false, message: error.message };
        }
    }

    deleteTask(taskId) {
        try {
            const tasks = this.getTasks();
            const taskToDelete = tasks.find(t => t.id === taskId);
            const filteredTasks = tasks.filter(t => t.id !== taskId);
            
            this.saveTasks(filteredTasks);
            
            // Update project task count
            if (taskToDelete) {
                this.updateProjectTaskCount(taskToDelete.project, -1);
            }
            
            return { success: true };
        } catch (error) {
            console.error('Error deleting task:', error);
            return { success: false, message: error.message };
        }
    }

    getTasksByAssignee(assignee) {
        const tasks = this.getTasks();
        return tasks.filter(task => task.assignee === assignee);
    }

    // Project Management
    getProjects() {
        try {
            return JSON.parse(localStorage.getItem(this.projectsKey) || '[]');
        } catch (error) {
            console.error('Error getting projects:', error);
            return [];
        }
    }

    saveProjects(projects) {
        try {
            localStorage.setItem(this.projectsKey, JSON.stringify(projects));
            return true;
        } catch (error) {
            console.error('Error saving projects:', error);
            return false;
        }
    }

    addProject(projectData) {
        try {
            const projects = this.getProjects();
            const newProject = {
                id: Date.now().toString(),
                createdAt: new Date().toISOString(),
                managerId: 'manager1',
                status: 'Active',
                progress: 0,
                taskCount: 0,
                ...projectData
            };

            projects.push(newProject);
            this.saveProjects(projects);
            
            return { success: true, project: newProject };
        } catch (error) {
            console.error('Error adding project:', error);
            return { success: false, message: error.message };
        }
    }

    updateProject(projectId, updates) {
        try {
            const projects = this.getProjects();
            const projectIndex = projects.findIndex(p => p.id === projectId);
            
            if (projectIndex === -1) {
                throw new Error('Project not found');
            }

            projects[projectIndex] = { ...projects[projectIndex], ...updates };
            this.saveProjects(projects);
            
            return { success: true, project: projects[projectIndex] };
        } catch (error) {
            console.error('Error updating project:', error);
            return { success: false, message: error.message };
        }
    }

    deleteProject(projectId) {
        try {
            const projects = this.getProjects();
            const filteredProjects = projects.filter(p => p.id !== projectId);
            
            this.saveProjects(filteredProjects);
            return { success: true };
        } catch (error) {
            console.error('Error deleting project:', error);
            return { success: false, message: error.message };
        }
    }

    updateProjectTaskCount(projectName, change) {
        try {
            const projects = this.getProjects();
            const projectIndex = projects.findIndex(p => p.name === projectName);
            
            if (projectIndex !== -1) {
                projects[projectIndex].taskCount = Math.max(0, projects[projectIndex].taskCount + change);
                this.saveProjects(projects);
            }
        } catch (error) {
            console.error('Error updating project task count:', error);
        }
    }

    // Statistics
    getDashboardStats() {
        try {
            const tasks = this.getTasks();
            const totalTasks = tasks.length;
            const completedTasks = tasks.filter(t => t.status === 'Completed').length;
            const inProgressTasks = tasks.filter(t => t.status === 'In Progress').length;
            const overdueTasks = tasks.filter(t => 
                new Date(t.dueDate) < new Date() && t.status !== 'Completed'
            ).length;
            const averageProgress = totalTasks > 0 
                ? Math.round(tasks.reduce((sum, task) => sum + task.progress, 0) / totalTasks)
                : 0;

            return {
                totalTasks,
                completedTasks,
                inProgressTasks,
                overdueTasks,
                averageProgress
            };
        } catch (error) {
            console.error('Error getting dashboard stats:', error);
            return {
                totalTasks: 0,
                completedTasks: 0,
                inProgressTasks: 0,
                overdueTasks: 0,
                averageProgress: 0
            };
        }
    }

    getProjectStats() {
        try {
            const projects = this.getProjects();
            const tasks = this.getTasks();
            
            return projects.map(project => {
                const projectTasks = tasks.filter(task => task.project === project.name);
                const completedTasks = projectTasks.filter(task => task.status === 'Completed').length;
                const totalTasks = projectTasks.length;
                const actualProgress = totalTasks > 0 ? Math.round((completedTasks / totalTasks) * 100) : 0;
                
                return {
                    ...project,
                    taskCount: totalTasks,
                    progress: actualProgress,
                    completedTasks,
                    overdueTasks: projectTasks.filter(task => 
                        new Date(task.dueDate) < new Date() && task.status !== 'Completed'
                    ).length
                };
            });
        } catch (error) {
            console.error('Error getting project stats:', error);
            return [];
        }
    }
}

// Create global storage instance
window.storageManager = new StorageManager();