<header class="header">
    <div class="header-content">
        <div class="logo-section">
            <div class="logo-icon">
                <!-- Cat face SVG icon -->
                <svg width="28" height="28" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect x="2" y="2" width="28" height="28" rx="7" fill="#f4f8ff"/>
                    <ellipse cx="16" cy="18" rx="8" ry="7" fill="#fff" stroke="#4f8cff" stroke-width="1.5"/>
                    <ellipse cx="12" cy="17" rx="1.2" ry="1.5" fill="#4f8cff"/>
                    <ellipse cx="20" cy="17" rx="1.2" ry="1.5" fill="#4f8cff"/>
                    <path d="M13.5 21c1.5 1 3.5 1 5 0" stroke="#4f8cff" stroke-width="1.2" stroke-linecap="round"/>
                    <path d="M8 10l2 4" stroke="#4f8cff" stroke-width="1.2" stroke-linecap="round"/>
                    <path d="M24 10l-2 4" stroke="#4f8cff" stroke-width="1.2" stroke-linecap="round"/>
                </svg>
            </div>
            <div class="logo-text">
                <h1>{{ config('app.name', 'Task Management MVP') }}</h1>
                <p>Task Management System</p>
            </div>
        </div>
        <button
            id="mobileMenuBtn"
            class="mobile-menu-btn"
            type="button"
            aria-label="Open primary navigation"
            aria-controls="primaryNavigation"
            aria-expanded="false"
        ><span aria-hidden="true">&#9776;</span></button>
        <nav id="primaryNavigation" class="nav-tabs" aria-label="Primary navigation">
            @php $user = auth()->user(); @endphp
            <a href="{{
                $user && $user->role && in_array($user->role->name, ['manager', 'project_manager'], true) ? route('manager.dashboard') :
                ($user && $user->role && $user->role->name === 'team_member' ? route('team-dashboard') : route('dashboard'))
            }}" class="nav-tab{{ (request()->routeIs('manager.dashboard') || request()->routeIs('project-manager.dashboard') || request()->routeIs('team-dashboard')) ? ' active' : '' }}"{{ (request()->routeIs('manager.dashboard') || request()->routeIs('project-manager.dashboard') || request()->routeIs('team-dashboard')) ? ' aria-current=page' : '' }}>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect x="3" y="3" width="7" height="7" stroke="currentColor" stroke-width="2"/>
                    <rect x="14" y="3" width="7" height="7" stroke="currentColor" stroke-width="2"/>
                    <rect x="14" y="14" width="7" height="7" stroke="currentColor" stroke-width="2"/>
                    <rect x="3" y="14" width="7" height="7" stroke="currentColor" stroke-width="2"/>
                </svg>
                Dashboard
            </a>
            <a href="{{ route('projects') }}" class="nav-tab{{ request()->routeIs('projects*') ? ' active' : '' }}"{{ request()->routeIs('projects*') ? ' aria-current=page' : '' }}>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M3 7a2 2 0 012-2h5l2 2h7a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z" stroke="currentColor" stroke-width="2"/>
                </svg>
                Projects
            </a>
            <a href="{{ route('tasks') }}" class="nav-tab{{ request()->routeIs('tasks*') ? ' active' : '' }}"{{ request()->routeIs('tasks*') ? ' aria-current=page' : '' }}>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect x="3" y="7" width="18" height="13" rx="2" stroke="currentColor" stroke-width="2"/>
                    <path d="M16 3v4M8 3v4" stroke="currentColor" stroke-width="2"/>
                </svg>
                Tasks
            </a>
            @if($user && $user->role && $user->role->name !== 'team_member')
            <a href="{{ route('analytics') }}" class="nav-tab{{ request()->routeIs('analytics*') ? ' active' : '' }}"{{ request()->routeIs('analytics*') ? ' aria-current=page' : '' }}>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <polyline points="22,12 18,12 15,21 9,3 6,12 2,12" stroke="currentColor" stroke-width="2"/>
                </svg>
                Analytics
            </a>
            <a href="{{ route('team-management') }}" class="nav-tab{{ request()->routeIs('team-management*') ? ' active' : '' }}"{{ request()->routeIs('team-management*') ? ' aria-current=page' : '' }}>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2" stroke="currentColor" stroke-width="2"/>
                    <circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="2"/>
                    <path d="M23 21v-2a4 4 0 00-3-3.87" stroke="currentColor" stroke-width="2"/>
                    <path d="M16 3.13a4 4 0 010 7.75" stroke="currentColor" stroke-width="2"/>
                </svg>
                Team
            </a>
            @endif
            <a href="{{ route('settings') }}" class="nav-tab{{ request()->routeIs('settings*') ? ' active' : '' }}"{{ request()->routeIs('settings*') ? ' aria-current=page' : '' }}>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/>
                    <path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21a2 2 0 1 1-4 0v-.09a1.7 1.7 0 0 0-.4-1.1 1.7 1.7 0 0 0-1-.6 1.7 1.7 0 0 0-1.88.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1.1-.4H3a2 2 0 1 1 0-4h.09a1.7 1.7 0 0 0 1.1-.4 1.7 1.7 0 0 0 .6-1 1.7 1.7 0 0 0-.34-1.88l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1.1V3a2 2 0 1 1 4 0v.09a1.7 1.7 0 0 0 .4 1.1 1.7 1.7 0 0 0 1 .6 1.7 1.7 0 0 0 1.88-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9a1.7 1.7 0 0 0 .6 1 1.7 1.7 0 0 0 1.1.4H21a2 2 0 1 1 0 4h-.09a1.7 1.7 0 0 0-1.1.4 1.7 1.7 0 0 0-.6 1z" stroke="currentColor" stroke-width="2"/>
                </svg>
                Settings
            </a>
        </nav>
        <div class="header-actions">
            <!-- Notifications Bell -->
            <div class="notifications-dropdown">
                <button id="notificationsBell" class="notification-bell" aria-label="Notifications" type="button">
                    <div class="bell-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M13.73 21a2 2 0 0 1-3.46 0" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    @php $unreadCount = $user ? $user->unreadNotifications->count() : 0; @endphp
                    @if($unreadCount > 0)
                        <span class="notification-badge">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
                    @endif
                </button>
                <div id="notificationsDropdown" class="notifications-panel">
                    <div class="notifications-header">
                        <h3>Notifications</h3>
                        @if($unreadCount > 0)
                            <button class="mark-all-read-btn" onclick="markAllAsRead()">Mark all read</button>
                        @endif
                    </div>
                    <div class="notifications-content">
                    @if($user && $user->notifications->count())
                            @foreach($user->notifications->take(8) as $notification)
                                <div class="notification-item {{ $notification->read_at ? 'read' : 'unread' }}" data-id="{{ $notification->id }}">
                                    <div class="notification-icon">
                                        @php
                                            $type = $notification->data['type'] ?? '';
                                            if(str_contains($type, 'assigned')) echo '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="m22 21-2-2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="m16 16 4 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                                            elseif(str_contains($type, 'completed')) echo '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="m9 11 3 3L22 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                                            elseif(str_contains($type, 'deadline') || str_contains($type, 'overdue')) echo '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><polyline points="12,6 12,12 16,14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                                            elseif(str_contains($type, 'updated')) echo '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="m18.5 2.5 3 3L12 15l-4 1 1-4 9.5-9.5z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                                            elseif(str_contains($type, 'reverted')) echo '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M21 3v5h-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 21v-5h5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                                            else echo '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M13.73 21a2 2 0 0 1-3.46 0" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                                        @endphp
                                    </div>
                                    <div class="notification-content">
                                        <div class="notification-message">{{ $notification->data['message'] ?? 'You have a new notification.' }}</div>
                                        <div class="notification-time">{{ $notification->created_at->diffForHumans() }}</div>
                                    </div>
                                    @if(!$notification->read_at)
                                        <button class="mark-read-btn" onclick="markAsRead('{{ $notification->id }}')">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                <path d="M20 6 9 17l-5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                            </svg>
                                        </button>
                                    @endif
                                </div>
                            @endforeach
                            <div class="notifications-footer">
                                <a href="{{ route('notifications.all') }}" class="view-all-link">View all notifications</a>
                            </div>
                        @else
                            <div class="no-notifications">
                                <div class="no-notifications-icon">
                                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                        <path d="M13.73 21a2 2 0 0 1-3.46 0" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </div>
                                <h4>No notifications</h4>
                                <p>You're all caught up!</p>
                        </div>
                    @endif
                    </div>
                </div>
            </div>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                const bell = document.getElementById('notificationsBell');
                const dropdown = document.getElementById('notificationsDropdown');
                
                if (bell && dropdown) {
                    bell.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        dropdown.classList.toggle('active');
                    });
                    
                    document.addEventListener('click', function(e) {
                        if (!bell.contains(e.target) && !dropdown.contains(e.target)) {
                            dropdown.classList.remove('active');
                        }
                    });
                }
                
                // Mobile menu toggle
                const mobileMenuBtn = document.getElementById('mobileMenuBtn');
                const navTabs = document.getElementById('primaryNavigation');
                if (mobileMenuBtn && navTabs) {
                    const mobileViewport = window.matchMedia('(max-width: 768px)');
                    const setMobileMenuState = function(isOpen, returnFocus = false) {
                        const shouldOpen = mobileViewport.matches && isOpen;

                        navTabs.classList.toggle('open', shouldOpen);
                        mobileMenuBtn.setAttribute('aria-expanded', String(shouldOpen));
                        mobileMenuBtn.setAttribute(
                            'aria-label',
                            shouldOpen ? 'Close primary navigation' : 'Open primary navigation'
                        );

                        if (returnFocus) {
                            mobileMenuBtn.focus();
                        }
                    };

                    mobileMenuBtn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        setMobileMenuState(!navTabs.classList.contains('open'));
                    });

                    navTabs.addEventListener('click', function(e) {
                        if (e.target.closest('a')) {
                            setMobileMenuState(false);
                        }
                    });

                    document.addEventListener('click', function() {
                        setMobileMenuState(false);
                    });

                    document.addEventListener('keydown', function(e) {
                        if (e.key === 'Escape' && navTabs.classList.contains('open')) {
                            setMobileMenuState(false, true);
                        }
                    });

                    mobileViewport.addEventListener('change', function() {
                        setMobileMenuState(false);
                    });
                }
            });
            
            function markAsRead(notificationId) {
                fetch(`/notifications/read/${notificationId}`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const notificationItem = document.querySelector(`[data-id="${notificationId}"]`);
                        if (notificationItem) {
                            notificationItem.classList.remove('unread');
                            notificationItem.classList.add('read');
                            const markReadBtn = notificationItem.querySelector('.mark-read-btn');
                            if (markReadBtn) {
                                markReadBtn.remove();
                            }
                            updateNotificationCount();
                        }
                    }
                })
                .catch(() => {});
            }
            
            function markAllAsRead() {
                fetch('/notifications/read-all', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.querySelectorAll('.notification-item.unread').forEach(item => {
                            item.classList.remove('unread');
                            item.classList.add('read');
                            const markReadBtn = item.querySelector('.mark-read-btn');
                            if (markReadBtn) {
                                markReadBtn.remove();
                            }
                        });
                        updateNotificationCount();
                    }
                })
                .catch(() => {});
            }
            
            function updateNotificationCount() {
                const unreadCount = document.querySelectorAll('.notification-item.unread').length;
                const badge = document.querySelector('.notification-badge');
                if (badge) {
                    if (unreadCount === 0) {
                        badge.remove();
                    } else {
                        badge.textContent = unreadCount > 99 ? '99+' : unreadCount;
                    }
                }
            }
            </script>
            <!-- End Notifications Bell -->
            <div class="user-info">
                <span class="user-name">{{ $user ? $user->name : '' }}</span>
            </div>
            <form method="POST" action="{{ route('logout') }}" class="logout-form">
                @csrf
                <button type="submit" class="logout-btn" title="Logout">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4" stroke="currentColor" stroke-width="2"/>
                        <polyline points="16,17 21,12 16,7" stroke="currentColor" stroke-width="2"/>
                        <line x1="21" y1="12" x2="9" y2="12" stroke="currentColor" stroke-width="2"/>
                    </svg>
                </button>
            </form>
        </div>
    </div>
</header>
<style>
.header { position: sticky; top: 0; z-index: 100; background: #fff; box-shadow: 0 2px 16px 0 rgba(60,72,88,0.05); border-bottom: 1px solid #e5e7eb; }
.header > .header-content { display: flex; align-items: center; justify-content: space-between; max-width: 1200px; margin: 0 auto; padding: 0.7rem 1.5rem; }
.logo-section { display: flex; align-items: center; gap: 0.7rem; }
.logo-icon { background: #f4f8ff; border-radius: 8px; padding: 0.3rem; display: flex; align-items: center; }
.logo-text h1 { font-size: 1.2rem; font-weight: 700; color: #4f8cff; margin: 0; letter-spacing: 0.5px; }
.logo-text p { color: #888; font-size: 0.97rem; margin: 0; font-weight: 500; letter-spacing: 0.2px; }
.nav-tabs { display: flex; align-items: center; gap: 1.2rem; margin-left: 2rem; flex: 1; }
.nav-tab { color: #22223b; text-decoration: none; font-size: 1rem; font-weight: 500; padding: 0.4rem 0.7rem; border-radius: 7px; transition: background 0.15s, color 0.15s; display: flex; align-items: center; gap: 0.4rem; }
.nav-tab.active, .nav-tab:hover { background: #eaf1ff; color: #4f8cff; }
.header-actions { display: flex; align-items: center; gap: 1.2rem; }
.user-info { color: #4f8cff; font-weight: 600; font-size: 1rem; margin-right: 0.7rem; }
.logout-btn { background: none; border: none; cursor: pointer; margin-left: 0.5rem; }
.mobile-menu-btn { display: none; }
.notifications-dropdown { position: relative; display: inline-block; }

/* Notification Bell Styles */
.notification-bell {
    position: relative;
    background: none;
    border: none;
    padding: 8px;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #6b7280;
}

.notification-bell:hover {
    background: #f3f4f6;
    color: #374151;
    transform: translateY(-1px);
}

.bell-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
}

.notification-badge {
    position: absolute;
    top: 4px;
    right: 4px;
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 10px;
    min-width: 18px;
    height: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 4px rgba(239, 68, 68, 0.3);
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.1); }
}

/* Notification Panel Styles */
.notifications-panel {
    position: absolute;
    top: 100%;
    right: 0;
    width: 380px;
    max-height: 500px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    border: 1px solid #e5e7eb;
    z-index: 1000;
    opacity: 0;
    visibility: hidden;
    transform: translateY(-10px) scale(0.95);
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    overflow: hidden;
}

.notifications-panel.active {
    opacity: 1;
    visibility: visible;
    transform: translateY(0) scale(1);
}

.notifications-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    border-bottom: 1px solid #f3f4f6;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
}

.notifications-header h3 {
    font-size: 16px;
    font-weight: 600;
    color: #111827;
    margin: 0;
}

.mark-all-read-btn {
    background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
    color: white;
    border: none;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 4px;
}

.mark-all-read-btn:hover {
    background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
    transform: translateY(-1px);
}

.notifications-content {
    max-height: 400px;
    overflow-y: auto;
}

.notification-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 16px 20px;
    border-bottom: 1px solid #f3f4f6;
    transition: all 0.2s ease;
    cursor: pointer;
}

.notification-item:hover {
    background: #f9fafb;
}

.notification-item.unread {
    background: #fef3c7;
    border-left: 3px solid #f59e0b;
}

.notification-item.unread:hover {
    background: #fde68a;
}

.notification-item.read {
    background: white;
    border-left: 3px solid #e5e7eb;
}

.notification-item.read:hover {
    background: #f9fafb;
}

.notification-icon {
    flex-shrink: 0;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: #f3f4f6;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #6b7280;
}

.notification-item.unread .notification-icon {
    background: #fef3c7;
    color: #f59e0b;
}

.notification-content {
    flex: 1;
    min-width: 0;
}

.notification-message {
    font-size: 14px;
    font-weight: 500;
    color: #111827;
    line-height: 1.4;
    margin-bottom: 4px;
}

.notification-time {
    font-size: 12px;
    color: #6b7280;
}

.mark-read-btn {
    flex-shrink: 0;
    background: none;
    border: none;
    padding: 4px;
    border-radius: 4px;
    cursor: pointer;
    color: #6b7280;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
}

.mark-read-btn:hover {
    background: #f3f4f6;
    color: #374151;
}

.notifications-footer {
    padding: 12px 20px;
    border-top: 1px solid #f3f4f6;
    text-align: center;
}

.view-all-link {
    color: #3b82f6;
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.2s ease;
}

.view-all-link:hover {
    color: #1d4ed8;
    text-decoration: underline;
}

.no-notifications {
    padding: 40px 20px;
    text-align: center;
    color: #6b7280;
}

.no-notifications-icon {
    margin-bottom: 16px;
    color: #d1d5db;
}

.no-notifications h4 {
    font-size: 16px;
    font-weight: 600;
    margin: 0 0 8px 0;
    color: #374151;
}

.no-notifications p {
    font-size: 14px;
    margin: 0;
}

@media (min-width: 769px) and (max-width: 1200px) {
    .header > .header-content {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        grid-template-rows: auto auto;
        align-items: center;
        width: 100%;
        height: auto;
        min-height: 72px;
        box-sizing: border-box;
        padding: 0.7rem;
        gap: 0.65rem 1rem;
    }

    .header > .header-content > .logo-section {
        grid-column: 1;
        grid-row: 1;
        min-width: 0;
    }

    .header > .header-content > .header-actions {
        grid-column: 2;
        grid-row: 1;
        min-width: 0;
    }

    .header > .header-content > .nav-tabs {
        grid-column: 1 / -1;
        grid-row: 2;
        width: 100%;
        margin-left: 0;
        justify-content: center;
        flex-wrap: wrap;
        gap: 0.4rem;
    }
}

@media (max-width: 768px) {
    .header > .header-content {
        position: relative;
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto auto;
        align-items: center;
        width: 100%;
        box-sizing: border-box;
        padding: 0.5rem;
        gap: 0.35rem;
    }

    .logo-section,
    .logo-text,
    .header-actions,
    .user-info {
        min-width: 0;
    }

    .logo-section {
        gap: 0.35rem;
    }

    .logo-text h1,
    .user-info {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .logo-text h1 {
        font-size: 1rem;
    }

    .logo-text p {
        display: none;
    }

    .mobile-menu-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 44px;
        height: 44px;
        padding: 0;
        background: none;
        border: none;
        border-radius: 8px;
        color: #4f8cff;
        cursor: pointer;
        font-size: 1.7rem;
        line-height: 1;
    }

    .mobile-menu-btn:hover {
        background: #f4f8ff;
    }

    .mobile-menu-btn:focus-visible {
        outline: 3px solid #4f8cff;
        outline-offset: 2px;
    }

    .nav-tabs {
        display: none;
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        z-index: 1001;
        flex-direction: column;
        align-items: stretch;
        gap: 0;
        max-height: calc(100vh - 4rem);
        margin-left: 0;
        overflow-y: auto;
        background: #fff;
        border-bottom: 1px solid #e5e7eb;
        box-shadow: 0 8px 16px rgba(60, 72, 88, 0.12);
    }

    .nav-tabs.open {
        display: flex;
    }

    .nav-tab {
        width: 100%;
        box-sizing: border-box;
        padding: 0.75rem 1rem;
        overflow-wrap: anywhere;
    }

    .header-actions {
        gap: 0.25rem;
    }

    .user-info {
        max-width: clamp(3rem, 18vw, 7rem);
        margin-right: 0;
    }

    .logout-btn {
        margin-left: 0;
        padding: 0.4rem;
    }

    .notifications-panel {
        position: fixed;
        top: 4rem;
        right: 0.5rem;
        left: 0.5rem;
        width: auto;
        max-width: none;
    }
    
    .notifications-content {
        max-height: 300px;
    }
    
    .notification-item {
        padding: 12px 16px;
    }
    
    .notifications-header {
        padding: 12px 16px;
    }
    
    .notifications-footer {
        padding: 8px 16px;
    }
}

@media (max-width: 480px) { .user-info { display: none; } }
</style>
