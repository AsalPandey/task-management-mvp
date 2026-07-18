// Storage Management System
class StorageManager {
    constructor() {
        this.tasksKey = 'taskflow_tasks';
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
            const filteredTasks = tasks.filter(t => t.id !== taskId);
            
            this.saveTasks(filteredTasks);
            
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
}

// Create global storage instance
window.storageManager = new StorageManager();