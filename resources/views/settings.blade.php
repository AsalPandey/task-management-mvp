@extends('layouts.app')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
<link rel="stylesheet" href="{{ asset('css/settings.css') }}">
<link rel="stylesheet" href="{{ asset('css/common.css') }}">
<style>
body { background: #f7f8fa; }
.settings-container { max-width: 900px; margin: 2rem auto; padding: 2rem 1rem; background: #fff; border-radius: 16px; box-shadow: 0 2px 16px 0 rgba(60,72,88,0.05); }
.settings-header h1 { font-size: 1.5rem; font-weight: 700; color: #22223b; margin-bottom: 0.2rem; }
.settings-header p { color: #888; font-size: 1.05rem; }
.settings-layout { display: flex; gap: 2rem; }
.settings-sidebar { background: #f8fafc; border-radius: 12px; box-shadow: none; padding: 1.2rem 1rem; min-width: 180px; }
.sidebar-nav { display: flex; flex-direction: column; gap: 0.7rem; }
.nav-item { background: none; border: none; color: #22223b; font-size: 1rem; padding: 0.7rem 1rem; border-radius: 8px; cursor: pointer; text-align: left; transition: background 0.15s; }
.nav-item.active, .nav-item:hover { background: #eaf1ff; color: #4f8cff; }
.settings-tab { background: #f8fafc; border-radius: 12px; box-shadow: none; padding: 1.2rem 1rem; flex: 1; }
@media (max-width: 900px) { .settings-layout { flex-direction: column; gap: 1rem; } .settings-sidebar { min-width: 0; } }
@media (max-width: 600px) { .settings-container { padding: 1rem 0.2rem; } }
</style>
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
            const targetTab = document.getElementById(tab);
            if (targetTab) {
                targetTab.classList.add('active');
            }
            // Clear stale messages when switching tabs
            document.querySelectorAll('.message-container').forEach(el => {
                el.style.display = 'none';
                el.textContent = '';
            });
        });
    });

    const csrfMeta = document.querySelector('meta[name=csrf-token]');
    const profileForm = document.getElementById('profileForm');
    const passwordForm = document.getElementById('passwordForm');
    const preferencesButton = document.getElementById('savePreferencesBtn');

    function showMessage(id, message, type = 'success') {
        const el = document.getElementById(id);
        if (!el) return;
        el.style.display = 'block';
        el.className = `message-container message-${type}`;
        el.textContent = message;
    }

    async function postJson(url, payload) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfMeta ? csrfMeta.content : '',
            },
            body: JSON.stringify(payload),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(data.message || Object.values(data.errors || {})[0]?.[0] || 'Request failed.');
        }
        if (typeof data.csrf_token === 'string' && csrfMeta) {
            csrfMeta.content = data.csrf_token;
            document.querySelectorAll('input[name="_token"]').forEach(input => {
                input.value = data.csrf_token;
            });
        }
        return data;
    }

    if (profileForm) {
        profileForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            try {
                const data = await postJson('{{ route('settings.profile') }}', {
                    name: document.getElementById('profileFullName').value,
                    email: document.getElementById('profileEmail').value,
                    timezone: '{{ $user->timezone ?: config('app.timezone') }}',
                });
                document.getElementById('profileName').textContent = data.user.name;
                showMessage('profileMessage', 'Profile updated.');
            } catch (error) {
                showMessage('profileMessage', error.message, 'error');
            }
        });
    }

    if (passwordForm) {
        passwordForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            try {
                const password = document.getElementById('newPassword').value;
                await postJson('{{ route('settings.profile') }}', {
                    name: document.getElementById('profileFullName').value,
                    email: document.getElementById('profileEmail').value,
                    current_password: document.getElementById('currentPassword').value,
                    password: password,
                    password_confirmation: document.getElementById('confirmPassword').value,
                });
                passwordForm.reset();
                showMessage('securityMessage', 'Password updated.');
            } catch (error) {
                showMessage('securityMessage', error.message, 'error');
            }
        });
    }

    if (preferencesButton) {
        const taskCompletedInput = document.getElementById('taskCompleted');
        const teamUpdatesInput = document.getElementById('teamUpdates');

        if (taskCompletedInput) {
            taskCompletedInput.addEventListener('change', function() {
                this.setAttribute('aria-checked', this.checked ? 'true' : 'false');
            });
        }
        if (teamUpdatesInput) {
            teamUpdatesInput.addEventListener('change', function() {
                this.setAttribute('aria-checked', this.checked ? 'true' : 'false');
            });
        }

        preferencesButton.addEventListener('click', async function() {
            const originalText = preferencesButton.textContent;
            preferencesButton.disabled = true;
            preferencesButton.textContent = 'Saving…';
            try {
                const res = await postJson('{{ route('settings.preferences') }}', {
                    task_completed: taskCompletedInput ? taskCompletedInput.checked : true,
                    team_updates: teamUpdatesInput ? teamUpdatesInput.checked : true,
                });
                if (res && res.preferences) {
                    if (taskCompletedInput && typeof res.preferences.task_completed === 'boolean') {
                        taskCompletedInput.checked = res.preferences.task_completed;
                        taskCompletedInput.setAttribute('aria-checked', res.preferences.task_completed ? 'true' : 'false');
                    }
                    if (teamUpdatesInput && typeof res.preferences.team_updates === 'boolean') {
                        teamUpdatesInput.checked = res.preferences.team_updates;
                        teamUpdatesInput.setAttribute('aria-checked', res.preferences.team_updates ? 'true' : 'false');
                    }
                }
                showMessage('notificationsMessage', 'Notification preferences saved.');
            } catch (error) {
                showMessage('notificationsMessage', error.message, 'error');
            } finally {
                preferencesButton.disabled = false;
                preferencesButton.textContent = originalText;
            }
        });
    }
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
            @php
                $policy = app(\App\Services\NotificationPreferencePolicy::class);
                $taskCompletedActive = isset($taskCompletedEnabled)
                    ? (bool) $taskCompletedEnabled
                    : $policy->decideForType($user, 'task_approved_completed', 'database')->allowed;
                $teamUpdatesActive = isset($teamUpdatesEnabled)
                    ? (bool) $teamUpdatesEnabled
                    : $policy->decideForType($user, 'task_updated', 'database')->allowed;
            @endphp
            <div id="notifications" class="settings-tab">
                <div class="tab-header">
                    <h2>Notification Settings</h2>
                    <p>Manage required notifications, optional preferences, and browser alerts</p>
                </div>
                <div id="notificationsMessage" class="message-container" role="status" aria-live="polite" style="display: none;"></div>

                <!-- Section A: Required Notifications -->
                <section class="notification-card-section" aria-labelledby="requiredNotificationsHeading">
                    <div class="section-subhead">
                        <div class="subhead-title-row">
                            <span class="section-icon" aria-hidden="true">🔒</span>
                            <h3 id="requiredNotificationsHeading">Required Notifications</h3>
                        </div>
                        <p>These notifications are required for tasks you are responsible for. They ensure workflow accountability and cannot be turned off.</p>
                    </div>
                    <div class="notification-items-group" role="list" aria-label="Required notifications list">
                        <div class="notification-item required-item" role="listitem">
                            <div class="notification-info">
                                <h4 class="item-title">Task Assignments</h4>
                                <p>Required in-app notification when tasks or responsibilities are assigned to you</p>
                            </div>
                            <span class="notification-required" aria-label="Required notification: cannot be disabled">
                                <svg class="badge-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                <span>Required</span>
                            </span>
                        </div>
                        <div class="notification-item required-item" role="listitem">
                            <div class="notification-info">
                                <h4 class="item-title">Deadlines &amp; Overdue Work</h4>
                                <p>Required in-app notification for upcoming deadlines and urgent alerts when assigned work becomes overdue</p>
                            </div>
                            <span class="notification-required" aria-label="Required notification: cannot be disabled">
                                <svg class="badge-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                <span>Required</span>
                            </span>
                        </div>
                        <div class="notification-item required-item" role="listitem">
                            <div class="notification-info">
                                <h4 class="item-title">Review &amp; Revision Actions</h4>
                                <p>Required in-app notification when work is submitted for review, revisions are requested, or reviewers are reassigned</p>
                            </div>
                            <span class="notification-required" aria-label="Required notification: cannot be disabled">
                                <svg class="badge-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                <span>Required</span>
                            </span>
                        </div>
                        <div class="notification-item required-item" role="listitem">
                            <div class="notification-info">
                                <h4 class="item-title">Workflow Status Changes</h4>
                                <p>Required in-app notification when tasks you are responsible for are placed on hold, resumed, cancelled, reopened, or have deadlines changed</p>
                            </div>
                            <span class="notification-required" aria-label="Required notification: cannot be disabled">
                                <svg class="badge-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                <span>Required</span>
                            </span>
                        </div>
                    </div>
                </section>

                <!-- Section B: Optional Notifications -->
                <section class="notification-card-section" aria-labelledby="optionalNotificationsHeading">
                    <div class="section-subhead">
                        <div class="subhead-title-row">
                            <span class="section-icon" aria-hidden="true">⚙️</span>
                            <h3 id="optionalNotificationsHeading">Optional Preferences</h3>
                        </div>
                        <p>Choose which optional updates you want to receive. These preferences apply to in-app notifications and enabled push devices.</p>
                    </div>
                    <div class="notification-items-group">
                        <div class="notification-item optional-item">
                            <div class="notification-info">
                                <label for="taskCompleted" id="taskCompletedLabel" class="option-title">Task Completion</label>
                                <p id="taskCompletedDesc">Get notified when tasks are approved and completed</p>
                            </div>
                            <label class="toggle-switch" for="taskCompleted" aria-label="Task completion notifications">
                                <input
                                    type="checkbox"
                                    id="taskCompleted"
                                    name="task_completed"
                                    role="switch"
                                    aria-labelledby="taskCompletedLabel"
                                    aria-describedby="taskCompletedDesc"
                                    aria-checked="{{ $taskCompletedActive ? 'true' : 'false' }}"
                                    {{ $taskCompletedActive ? 'checked' : '' }}
                                >
                                <span class="toggle-slider" aria-hidden="true"></span>
                            </label>
                        </div>
                        <div class="notification-item optional-item" id="teamUpdatesItem">
                            <div class="notification-info">
                                <label for="teamUpdates" id="teamUpdatesLabel" class="option-title">Team &amp; General Updates</label>
                                <p id="teamUpdatesDesc">Get notified about general task progress, phase changes, and project team membership</p>
                            </div>
                            <label class="toggle-switch" for="teamUpdates" aria-label="Team and general updates notifications">
                                <input
                                    type="checkbox"
                                    id="teamUpdates"
                                    name="team_updates"
                                    role="switch"
                                    aria-labelledby="teamUpdatesLabel"
                                    aria-describedby="teamUpdatesDesc"
                                    aria-checked="{{ $teamUpdatesActive ? 'true' : 'false' }}"
                                    {{ $teamUpdatesActive ? 'checked' : '' }}
                                >
                                <span class="toggle-slider" aria-hidden="true"></span>
                            </label>
                        </div>
                    </div>
                    <div class="form-actions preferences-actions">
                        <button type="button" class="btn-primary" id="savePreferencesBtn">💾 Save Preferences</button>
                    </div>
                </section>

                <!-- Section C: Browser Notifications on This Device -->
                <section class="push-settings-card notification-card-section" aria-labelledby="browserPushHeading">
                    <div class="push-settings-heading">
                        <div>
                            <h3 id="browserPushHeading">Browser notifications on this device</h3>
                            <p>Receive real-time notifications on this device. Browser notifications are enabled separately on each device.</p>
                        </div>
                        <span class="push-status" data-push-status aria-live="polite">Checking support…</span>
                    </div>
                    <p class="push-message" data-push-message role="status" aria-live="polite" hidden></p>
                    <p class="push-help" data-push-blocked-help hidden>
                        Permission is blocked by your browser or operating system. Open this site's notification
                        permissions in browser settings, allow notifications, then retry.
                    </p>
                    <div class="push-actions">
                        <button type="button" class="btn-primary" data-push-enable>Enable Notifications</button>
                        <button type="button" class="btn-secondary" data-push-test hidden>Send Test Notification</button>
                        <button type="button" class="btn-secondary push-disable" data-push-disable hidden>Disable on This Device<span class="sr-only"> (Disable This Device)</span></button>
                    </div>
                    <p class="push-privacy-note">
                        Browser notifications are enabled separately on each device. Disabling this device does not
                        disable your other devices.
                    </p>
                </section>

                <!-- Section D: PWA Install Card -->
                <section class="pwa-install-card notification-card-section" data-pwa-card aria-labelledby="installAppHeading">
                    <div>
                        <h3 id="installAppHeading">Install Task Management</h3>
                        <p>Open the installation option for this browser and device. This remains available even if you previously selected Not Now.</p>
                    </div>
                    <div class="push-actions">
                        <button type="button" class="btn-primary" data-pwa-open>Install App</button>
                    </div>
                    <p class="push-privacy-note">
                        Installation is separate from Browser Push. Opening this option never requests notification permission.
                    </p>
                </section>

                <!-- Section E: System & Security Notifications Notice -->
                <div class="system-notification-note">
                    <span class="system-note-icon" aria-hidden="true">🛡️</span>
                    <p>Account security and critical administrative notices are always sent to protect your account and are managed by system policy.</p>
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
                            <input type="password" id="newPassword" placeholder="Enter new password" required minlength="8">
                        </div>
                        <div class="form-group">
                            <label for="confirmPassword">Confirm New Password</label>
                            <input type="password" id="confirmPassword" placeholder="Confirm new password" required minlength="8">
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
                                <span class="security-value" id="accountCreated">
                                    {{ $user->created_at ? $user->created_at->format('m/d/Y') : '-' }}
                                </span>
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
        </div>
    </div>
</div>
@endsection
