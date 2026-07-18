// Authentication System
class AuthSystem {
    constructor() {
        this.currentUser = null;
        this.users = [];
        this.initializeDefaultUsers();
    }

    initializeDefaultUsers() {
        // Archived UI reference only. No users or credentials are provisioned.
        this.users = [];
    }

    async login(email, password) {
        try {
            const users = [...this.users];
            const user = users.find(u => u.email === email && u.password === password);
            
            if (user) {
                // Update last login
                const updatedUser = { ...user, lastLogin: new Date().toISOString() };
                const updatedUsers = users.map(u => u.id === user.id ? updatedUser : u);
                this.users = updatedUsers;
                
                // Save current session
                const sessionUser = { ...updatedUser };
                delete sessionUser.password;
                this.currentUser = sessionUser;
                return { success: true, user: sessionUser };
            }
            
            return { success: false, message: 'Invalid email or password' };
        } catch (error) {
            console.error('Login error:', error);
            return { success: false, message: 'Login failed. Please try again.' };
        }
    }

    getCurrentUser() {
        try {
            return this.currentUser;
        } catch (error) {
            console.error('Error getting current user:', error);
            return null;
        }
    }

    logout() {
        this.currentUser = null;
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
            const users = [...this.users];
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
            const users = [...this.users];
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
            this.users = users;
            
            return { success: true, member: newMember };
        } catch (error) {
            console.error('Error adding team member:', error);
            return { success: false, message: error.message };
        }
    }

    updateTeamMember(memberId, updates) {
        try {
            const users = [...this.users];
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
            this.users = users;
            
            return { success: true, member: users[memberIndex] };
        } catch (error) {
            console.error('Error updating team member:', error);
            return { success: false, message: error.message };
        }
    }

    removeTeamMember(memberId) {
        try {
            const users = [...this.users];
            const currentUser = this.getCurrentUser();
            
            if (!currentUser || currentUser.role !== 'manager') {
                throw new Error('Unauthorized');
            }

            const filteredUsers = users.filter(u => u.id !== memberId);
            this.users = filteredUsers;
            
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

            const users = [...this.users];
            const userIndex = users.findIndex(u => u.id === currentUser.id);
            
            if (userIndex === -1) throw new Error('User not found');

            users[userIndex] = { ...users[userIndex], ...updates };
            this.users = users;
            this.currentUser = { ...users[userIndex] };
            delete this.currentUser.password;
            
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

            const users = [...this.users];
            const user = users.find(u => u.id === currentUser.id);
            
            if (!user || user.password !== currentPassword) {
                throw new Error('Current password is incorrect');
            }

            const userIndex = users.findIndex(u => u.id === currentUser.id);
            users[userIndex].password = newPassword;
            this.users = users;
            
            return { success: true };
        } catch (error) {
            console.error('Error changing password:', error);
            return { success: false, message: error.message };
        }
    }
}

// Create global auth instance
window.authSystem = new AuthSystem();
