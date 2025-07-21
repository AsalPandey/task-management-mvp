@extends('layouts.app')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
<link rel="stylesheet" href="{{ asset('css/settings.css') }}">
<link rel="stylesheet" href="{{ asset('css/common.css') }}">
@endpush
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Tab switching
    const navItems = document.querySelectorAll('.nav-item');
    const tabs = document.querySelectorAll('.settings-tab');
    navItems.forEach(item => {
        item.addEventListener('click', function() {
            navItems.forEach(i => i.classList.remove('active'));
            tabs.forEach(tab => tab.classList.remove('active'));
            this.classList.add('active');
            const tab = this.getAttribute('data-tab');
            document.getElementById(tab).classList.add('active');
        });
    });
});
</script>
@endpush
@section('content')
<div class="settings-container">
    <!-- Header -->
    <div class="settings-header">
        <h1>Settings</h1>
        <p>Manage your account and preferences</p>
    </div>
    <!-- Settings Layout -->
    <div class="settings-layout">
        <!-- Sidebar Navigation -->
        <div class="settings-sidebar">
            <div class="sidebar-nav">
                <button class="nav-item active" data-tab="profile">
                    <span class="nav-icon">👤</span>
                    <span class="nav-label">Profile</span>
                </button>
                <button class="nav-item" data-tab="notifications">
                    <span class="nav-icon">🔔</span>
                    <span class="nav-label">Notifications</span>
                </button>
                <button class="nav-item" data-tab="security">
                    <span class="nav-icon">🛡️</span>
                    <span class="nav-label">Security</span>
                </button>
                <!-- Removed Preferences tab -->
            </div>
        </div>
        <!-- Main Content -->
        <div class="settings-content">
            <!-- Profile Tab -->
            <div id="profile" class="settings-tab active">
                <div class="tab-header">
                    <h2>Profile Information</h2>
                    <p>Update your personal information and account details</p>
                </div>
                <div id="profileMessage" class="message-container" style="display: none;"></div>
                <div class="profile-avatar-section">
                    <div class="profile-avatar" id="profileAvatar">{{ strtoupper(substr($user->name, 0, 2)) }}</div>
                    <div class="avatar-info">
                        <h3 id="profileName">{{ $user->name }}</h3>
                        <p id="profileRole">{{ $user->role ? ucfirst($user->role->name) : '-' }}</p>
                    </div>
                </div>
                <form id="profileForm" class="settings-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="profileFullName">Full Name</label>
                            <input type="text" id="profileFullName" name="profileFullName" value="{{ $user->name }}" placeholder="Enter your full name" required>
                        </div>
                        <div class="form-group">
                            <label for="profileEmail">Email Address</label>
                            <input type="email" id="profileEmail" name="profileEmail" value="{{ $user->email }}" placeholder="Enter your email" required>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn-primary">💾 Update Profile</button>
                    </div>
                </form>
            </div>
            <!-- Notifications Tab -->
            <div id="notifications" class="settings-tab">
                <div class="tab-header">
                    <h2>Notification Preferences</h2>
                    <p>Choose what notifications you want to receive</p>
                </div>
                <div class="notification-settings">
                    <div class="notification-item">
                        <div class="notification-info">
                            <h3>Task Assignments</h3>
                            <p>Get notified when tasks are assigned to you</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="taskAssigned" checked>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="notification-item">
                        <div class="notification-info">
                            <h3>Task Completion</h3>
                            <p>Get notified when tasks are completed</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="taskCompleted" checked>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="notification-item">
                        <div class="notification-info">
                            <h3>Deadline Reminders</h3>
                            <p>Get reminded about upcoming deadlines</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="deadlineReminder" checked>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="notification-item" id="teamUpdatesItem">
                        <div class="notification-info">
                            <h3>Team Updates</h3>
                            <p>Get notified about team activity and updates</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" id="teamUpdates" checked>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn-primary">💾 Save Preferences</button>
                </div>
            </div>
            <!-- Security Tab -->
            <div id="security" class="settings-tab">
                <div class="tab-header">
                    <h2>Security Settings</h2>
                    <p>Manage your password and account security</p>
                </div>
                <div id="securityMessage" class="message-container" style="display: none;"></div>
                <div class="security-section">
                    <h3>Change Password</h3>
                    <form id="passwordForm" class="settings-form">
                        <div class="form-group">
                            <label for="currentPassword">Current Password</label>
                            <input type="password" id="currentPassword" placeholder="Enter current password" required>
                        </div>
                        <div class="form-group">
                            <label for="newPassword">New Password</label>
                            <input type="password" id="newPassword" placeholder="Enter new password" required minlength="6">
                        </div>
                        <div class="form-group">
                            <label for="confirmPassword">Confirm New Password</label>
                            <input type="password" id="confirmPassword" placeholder="Confirm new password" required minlength="6">
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn-primary">🔒 Update Password</button>
                        </div>
                    </form>
                </div>
                <div class="security-info">
                    <h3>Account Security</h3>
                    <div class="security-details">
                        <div class="security-item">
                            <span class="security-icon">🛡️</span>
                            <div class="security-text">
                                <span class="security-label">Account created:</span>
                                <span class="security-value" id="accountCreated">{{ $user->created_at ? $user->created_at->format('m/d/Y') : '-' }}</span>
                            </div>
                        </div>
                        <div class="security-item">
                            <span class="security-icon">⏰</span>
                            <div class="security-text">
                                <span class="security-label">Last login:</span>
                                <span class="security-value" id="lastLogin">{{ $user->last_login_at ?? 'Unknown' }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Remove Preferences Tab Content -->
        </div>
    </div>
</div>
@endsection 