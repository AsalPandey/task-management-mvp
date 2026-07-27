@php
    $mobileUser = auth()->user();
    $isTeamMember = $mobileUser?->role?->name === 'team_member';
@endphp

@if ($isTeamMember)
    <nav class="mobile-bottom-nav" aria-label="Mobile primary navigation">
        <a href="{{ route('team-dashboard') }}"
            class="mobile-bottom-nav-item{{ request()->routeIs('team-dashboard') ? ' active' : '' }}"
            @if(request()->routeIs('team-dashboard')) aria-current="page" @endif>
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M3 11.5 12 4l9 7.5M5.5 10.5V20h13v-9.5M9 20v-6h6v6"/>
            </svg>
            <span>Home</span>
        </a>
        <a href="{{ route('tasks') }}"
            class="mobile-bottom-nav-item{{ request()->routeIs('tasks') && ! request()->filled('search') ? ' active' : '' }}"
            @if(request()->routeIs('tasks') && ! request()->filled('search')) aria-current="page" @endif>
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M7 4h10a2 2 0 0 1 2 2v14H5V6a2 2 0 0 1 2-2Zm2-2v4m6-4v4M8 10h8m-8 4h8"/>
            </svg>
            <span>My Tasks</span>
        </a>
        <a href="{{ route('notifications.all') }}"
            class="mobile-bottom-nav-item{{ request()->routeIs('notifications.*') ? ' active' : '' }}"
            @if(request()->routeIs('notifications.*')) aria-current="page" @endif>
            <span class="mobile-nav-icon-wrap">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M18 8a6 6 0 0 0-12 0c0 6-3 8-3 8h18s-3-2-3-8m-4 12h-4"/>
                </svg>
                @php $mobileUnreadCount = $mobileUser->unreadNotifications->count(); @endphp
                @if ($mobileUnreadCount)
                    <span class="mobile-nav-badge" aria-label="{{ $mobileUnreadCount }} unread notifications">
                        {{ $mobileUnreadCount > 9 ? '9+' : $mobileUnreadCount }}
                    </span>
                @endif
            </span>
            <span>Notifications</span>
        </a>
        <a href="{{ route('tasks') }}#searchTasks" class="mobile-bottom-nav-item">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="11" cy="11" r="7"/>
                <path d="m16 16 5 5"/>
            </svg>
            <span>Search</span>
        </a>
        <a href="{{ route('settings') }}#profile"
            class="mobile-bottom-nav-item{{ request()->routeIs('settings*') ? ' active' : '' }}"
            @if(request()->routeIs('settings*')) aria-current="page" @endif>
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="12" cy="8" r="4"/>
                <path d="M4 21a8 8 0 0 1 16 0"/>
            </svg>
            <span>Profile</span>
        </a>
    </nav>
@endif
