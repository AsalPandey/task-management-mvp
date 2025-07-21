// Authentication System
class AuthSystem {
    constructor() {
        this.storageKey = 'taskflow_current_user';
        this.usersKey = 'taskflow_users';
        this.initializeDefaultUsers();
    }

    initializeDefaultUsers() {
        if (!localStorage.getItem(this.usersKey)) {
            const defaultUsers = [
                {
                    id: 'manager1',
                    email: 'asalpandey44@gmail.com',
                    name: 'Asal Pandey',
                    role: 'manager',
                    companyId: '1',
                    createdAt: '2024-01-01T00:00:00Z',
                    password: 'PLMokn!@#123',
                },
                {
                    id: '1',
                    email: 'aniket@techcorp.com',
                    name: 'Aniket',
                    role: 'team',
                    companyId: '1',
                    createdAt: '2024-01-02T00:00:00Z',
                    password: 'aniket123',
                },
                {
                    id: '2',
                    email: 'achyut@techcorp.com',
                    name: 'Achyut',
                    role: 'team',
                    companyId: '1',
                    createdAt: '2024-01-02T00:00:00Z',
                    password: 'achyut123',
                },
                {
                    id: '3',
                    email: 'bishal@techcorp.com',
                    name: 'Bishal',
                    role: 'team',
                    companyId: '1',
                    createdAt: '2024-01-02T00:00:00Z',
                    password: 'bishal123',
                }
            ];
            localStorage.setItem(this.usersKey, JSON.stringify(defaultUsers));
        }
    }

    async login(email, password) {
        try {
            const users = JSON.parse(localStorage.getItem(this.usersKey) || '[]');
            const user = users.find(u => u.email === email && u.password === password);
            
            if (user) {
                // Update last login
                const updatedUser = { ...user, lastLogin: new Date().toISOString() };
                const updatedUsers = users.map(u => u.id === user.id ? updatedUser : u);
                localStorage.setItem(this.usersKey, JSON.stringify(updatedUsers));
                
                // Save current session
                localStorage.setItem(this.storageKey, JSON.stringify(updatedUser));
                return { success: true, user: updatedUser };
            }
            
            return { success: false, message: 'Invalid email or password' };
        } catch (error) {
            console.error('Login error:', error);
            return { success: false, message: 'Login failed. Please try again.' };
        }
    }

    getCurrentUser() {
        try {
            const userData = localStorage.getItem(this.storageKey);
            return userData ? JSON.parse(userData) : null;
        } catch (error) {
            console.error('Error getting current user:', error);
            return null;
        }
    }

    logout() {
        localStorage.removeItem(this.storageKey);
        window.location.href = '/index.html';
    }

    isAuthenticated() {
        return this.getCurrentUser() !== null;
    }

    requireAuth() {
        if (!this.isAuthenticated()) {
            window.location.href = '/index.html';
            return false;
        }
        return true;
    }

    requireManagerRole() {
        const user = this.getCurrentUser();
        if (!user || user.role !== 'manager') {
            window.location.href = '/pages/team-dashboard.html';
            return false;
        }
        return true;
    }

    // Team Management Functions
    getTeamMembers() {
        try {
            const users = JSON.parse(localStorage.getItem(this.usersKey) || '[]');
            const currentUser = this.getCurrentUser();
            if (!currentUser) return [];
            
            return users.filter(user => 
                user.companyId === currentUser.companyId && user.role === 'team'
            );
        } catch (error) {
            console.error('Error getting team members:', error);
            return [];
        }
    }

    addTeamMember(memberData) {
        try {
            const users = JSON.parse(localStorage.getItem(this.usersKey) || '[]');
            const currentUser = this.getCurrentUser();
            
            if (!currentUser || currentUser.role !== 'manager') {
                throw new Error('Unauthorized');
            }

            // Check if email already exists
            if (users.find(u => u.email === memberData.email)) {
                throw new Error('Email already exists');
            }

            const newMember = {
                id: Date.now().toString(),
                email: memberData.email,
                name: memberData.name,
                role: 'team',
                companyId: currentUser.companyId,
                createdAt: new Date().toISOString(),
                password: memberData.password
            };

            users.push(newMember);
            localStorage.setItem(this.usersKey, JSON.stringify(users));
            
            return { success: true, member: newMember };
        } catch (error) {
            console.error('Error adding team member:', error);
            return { success: false, message: error.message };
        }
    }

    updateTeamMember(memberId, updates) {
        try {
            const users = JSON.parse(localStorage.getItem(this.usersKey) || '[]');
            const currentUser = this.getCurrentUser();
            
            if (!currentUser || currentUser.role !== 'manager') {
                throw new Error('Unauthorized');
            }

            const memberIndex = users.findIndex(u => u.id === memberId);
            if (memberIndex === -1) {
                throw new Error('Member not found');
            }

            // Check if email already exists (excluding current member)
            if (updates.email && users.find(u => u.email === updates.email && u.id !== memberId)) {
                throw new Error('Email already exists');
            }

            users[memberIndex] = { ...users[memberIndex], ...updates };
            localStorage.setItem(this.usersKey, JSON.stringify(users));
            
            return { success: true, member: users[memberIndex] };
        } catch (error) {
            console.error('Error updating team member:', error);
            return { success: false, message: error.message };
        }
    }

    removeTeamMember(memberId) {
        try {
            const users = JSON.parse(localStorage.getItem(this.usersKey) || '[]');
            const currentUser = this.getCurrentUser();
            
            if (!currentUser || currentUser.role !== 'manager') {
                throw new Error('Unauthorized');
            }

            const filteredUsers = users.filter(u => u.id !== memberId);
            localStorage.setItem(this.usersKey, JSON.stringify(filteredUsers));
            
            return { success: true };
        } catch (error) {
            console.error('Error removing team member:', error);
            return { success: false, message: error.message };
        }
    }

    updateProfile(updates) {
        try {
            const currentUser = this.getCurrentUser();
            if (!currentUser) throw new Error('Not authenticated');

            const users = JSON.parse(localStorage.getItem(this.usersKey) || '[]');
            const userIndex = users.findIndex(u => u.id === currentUser.id);
            
            if (userIndex === -1) throw new Error('User not found');

            users[userIndex] = { ...users[userIndex], ...updates };
            localStorage.setItem(this.usersKey, JSON.stringify(users));
            localStorage.setItem(this.storageKey, JSON.stringify(users[userIndex]));
            
            return { success: true };
        } catch (error) {
            console.error('Error updating profile:', error);
            return { success: false, message: error.message };
        }
    }

    changePassword(currentPassword, newPassword) {
        try {
            const currentUser = this.getCurrentUser();
            if (!currentUser) throw new Error('Not authenticated');

            const users = JSON.parse(localStorage.getItem(this.usersKey) || '[]');
            const user = users.find(u => u.id === currentUser.id);
            
            if (!user || user.password !== currentPassword) {
                throw new Error('Current password is incorrect');
            }

            const userIndex = users.findIndex(u => u.id === currentUser.id);
            users[userIndex].password = newPassword;
            localStorage.setItem(this.usersKey, JSON.stringify(users));
            
            return { success: true };
        } catch (error) {
            console.error('Error changing password:', error);
            return { success: false, message: error.message };
        }
    }
}

// Create global auth instance
window.authSystem = new AuthSystem();