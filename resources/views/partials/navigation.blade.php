<header class="header">
    <div class="header-content">
        <div class="logo-section">
            <div class="logo-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <div class="logo-text">
                <h1>TaskFlow</h1>
                <p>TechCorp Solutions</p>
            </div>
        </div>
        <nav class="nav-tabs">
            @php $user = auth()->user(); @endphp
            <a href="{{ $user && $user->role && $user->role->name === 'manager' ? route('manager.dashboard') : ($user && $user->role && $user->role->name === 'team_member' ? route('team-dashboard') : ($user && $user->role && $user->role->name === 'teamleader' ? route('teamleader.dashboard') : '#')) }}" class="nav-tab{{ (request()->routeIs('manager.dashboard') || request()->routeIs('team-dashboard') || request()->routeIs('teamleader.dashboard')) ? ' active' : '' }}">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect x="3" y="3" width="7" height="7" stroke="currentColor" stroke-width="2"/>
                    <rect x="14" y="3" width="7" height="7" stroke="currentColor" stroke-width="2"/>
                    <rect x="14" y="14" width="7" height="7" stroke="currentColor" stroke-width="2"/>
                    <rect x="3" y="14" width="7" height="7" stroke="currentColor" stroke-width="2"/>
                </svg>
                Dashboard
            </a>
            <a href="{{ route('tasks') }}" class="nav-tab{{ request()->routeIs('tasks') ? ' active' : '' }}">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">

++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++                <rect x="3" y="7" width="18" height="13" rx="2" stroke="currentColor" stroke-width="2"/>
                    <path d="M16 3v4M8 3v4" stroke="currentColor" stroke-width="2"/>
                </svg>
                Tasks
            </a>
            @if($user && $user->role && $user->role->name !== 'team_member')
            <a href="{{ route('projects') }}" class="nav-tab{{ request()->routeIs('projects') ? ' active' : '' }}">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z" stroke="currentColor" stroke-width="2"/>
                </svg>
                Projects
            </a>
            <a href="{{ route('analytics') }}" class="nav-tab{{ request()->routeIs('analytics') ? ' active' : '' }}">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <polyline points="22,12 18,12 15,21 9,3 6,12 2,12" stroke="currentColor" stroke-width="2"/>
                </svg>
                Analytics
            </a>
            <a href="{{ route('team-management') }}" class="nav-tab{{ request()->routeIs('team-management') ? ' active' : '' }}">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2" stroke="currentColor" stroke-width="2"/>
                    <circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="2"/>
                    <path d="M23 21v-2a4 4 0 00-3-3.87" stroke="currentColor" stroke-width="2"/>
                    <path d="M16 3.13a4 4 0 010 7.75" stroke="currentColor" stroke-width="2"/>
                </svg>
                Team
            </a>
            @endif
            <a href="{{ route('settings') }}" class="nav-tab{{ request()->routeIs('settings') ? ' active' : '' }}">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/>
                    <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 01-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 008.6 19a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004 15.4a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004 8.6a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 008.6 5a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09c.29.06.56.18.8.35.24.17.45.39.62.62.17.23.29.5.35.8H15a1.65 1.65 0 001.51 1z" stroke="currentColor" stroke-width="2"/>
                </svg>
                Settings
            </a>
        </nav>
        <div class="header-actions">
            <div class="user-info">
                <span class="user-name">{{ $user ? $user->name : '' }}</span>
            </div>
            <form method="POST" action="{{ route('logout') }}" style="display:inline;">
                @csrf
                <button type="submit" class="logout-btn" title="Logout" style="background:none; border:none; cursor:pointer;">
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