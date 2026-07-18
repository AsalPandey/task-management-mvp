@extends('layouts.app')
@section('content')
<div class="container" style="max-width:800px; margin:2rem auto;">
    <div class="notifications-page-header">
        <div class="header-content">
            <div class="header-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <div class="header-text">
                <h1>All Notifications</h1>
                <p>Stay updated with all your task activities</p>
            </div>
        </div>
        @if($notifications->whereNull('read_at')->count())
            <button id="markAllReadBtn" class="mark-all-read-page-btn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M20 6 9 17l-5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Mark all as read
            </button>
        @endif
    </div>
    
    @if($notifications->count())
        <div class="notifications-list">
            @foreach($notifications as $notification)
                <div class="notification-page-item {{ $notification->read_at ? 'read' : 'unread' }}" data-id="{{ $notification->id }}">
                    <div class="notification-page-icon">
                        @php
                            $type = $notification->data['type'] ?? '';
                            if(str_contains($type, 'assigned')) echo '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="9" cy="7" r="4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="m22 21-2-2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="m16 16 4 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                            elseif(str_contains($type, 'completed')) echo '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="m9 11 3 3L22 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                            elseif(str_contains($type, 'deadline') || str_contains($type, 'overdue')) echo '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><polyline points="12,6 12,12 16,14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                            elseif(str_contains($type, 'updated')) echo '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="m18.5 2.5 3 3L12 15l-4 1 1-4 9.5-9.5z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                            elseif(str_contains($type, 'reverted')) echo '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M21 3v5h-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 21v-5h5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                            else echo '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M13.73 21a2 2 0 0 1-3.46 0" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                                @endphp
                    </div>
                    <div class="notification-page-content">
                        <div class="notification-page-message">{{ $notification->data['message'] ?? 'You have a new notification.' }}</div>
                        <div class="notification-page-time">{{ $notification->created_at->diffForHumans() }}</div>
                    </div>
                    @if(!$notification->read_at)
                        <button class="mark-read-page-btn" onclick="markAsRead('{{ $notification->id }}')">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M20 6 9 17l-5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>
                    @endif
                </div>
            @endforeach
        </div>
        <div class="notifications-pagination">
            {{ $notifications->links() }}
        </div>
    @else
        <div class="no-notifications-page">
            <div class="no-notifications-page-icon">
                <svg width="64" height="64" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            <h2>No notifications yet</h2>
            <p>You're all caught up! New notifications will appear here.</p>
        </div>
    @endif
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // AJAX mark as read
    window.markAsRead = function(notificationId) {
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
                    const markReadBtn = notificationItem.querySelector('.mark-read-page-btn');
                    if (markReadBtn) {
                        markReadBtn.remove();
                    }
                }
            }
        })
        .catch(() => {});
    };
    
    // AJAX mark all as read
    const markAllBtn = document.getElementById('markAllReadBtn');
    if(markAllBtn) {
        markAllBtn.addEventListener('click', function() {
            this.disabled = true;
            this.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm0 18a8 8 0 1 1 8-8 8 8 0 0 1-8 8z" stroke="currentColor" stroke-width="2"/><path d="M12 6v6l4 2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg> Processing...';
            
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
                    document.querySelectorAll('.notification-page-item.unread').forEach(item => {
                        item.classList.remove('unread');
                        item.classList.add('read');
                        const markReadBtn = item.querySelector('.mark-read-page-btn');
                        if (markReadBtn) {
                            markReadBtn.remove();
                        }
                    });
                    markAllBtn.remove();
                }
            })
            .catch(() => {
                markAllBtn.disabled = false;
                markAllBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M20 6 9 17l-5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg> Mark all as read';
            });
        });
    }
});
</script>
@endpush

<style>
/* Notifications Page Styles */
.notifications-page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 2rem;
    padding: 1.5rem;
    background: white;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    border: 1px solid #e5e7eb;
}

.header-content {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.header-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
}

.header-text h1 {
    font-size: 24px;
    font-weight: 700;
    color: #111827;
    margin: 0 0 4px 0;
}

.header-text p {
    font-size: 14px;
    color: #6b7280;
    margin: 0;
}

.mark-all-read-page-btn {
    background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
    color: white;
    border: none;
    padding: 12px 20px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
}

.mark-all-read-page-btn:hover {
    background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
}

.mark-all-read-page-btn:disabled {
    opacity: 0.7;
    cursor: not-allowed;
    transform: none;
}

.notifications-list {
    background: white;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    border: 1px solid #e5e7eb;
    overflow: hidden;
}

.notification-page-item {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    padding: 20px;
    border-bottom: 1px solid #f3f4f6;
    transition: all 0.2s ease;
}

.notification-page-item:hover {
    background: #f9fafb;
}

.notification-page-item.unread {
    background: #eff6ff;
    border-left: 4px solid #3b82f6;
}

.notification-page-item.unread:hover {
    background: #dbeafe;
}

.notification-page-item.read {
    background: white;
}

.notification-page-item.read:hover {
    background: #f9fafb;
}

.notification-page-icon {
    flex-shrink: 0;
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f3f4f6;
    color: #6b7280;
}

.notification-page-item.unread .notification-page-icon {
    background: #dbeafe;
    color: #3b82f6;
}

.notification-page-content {
    flex: 1;
    min-width: 0;
}

.notification-page-message {
    font-size: 15px;
    font-weight: 500;
    color: #111827;
    line-height: 1.5;
    margin-bottom: 6px;
    word-wrap: break-word;
}

.notification-page-time {
    font-size: 13px;
    color: #6b7280;
    font-weight: 500;
}

.mark-read-page-btn {
    flex-shrink: 0;
    background: none;
    border: none;
    color: #6b7280;
    cursor: pointer;
    padding: 8px;
    border-radius: 6px;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
}

.mark-read-page-btn:hover {
    background: #f3f4f6;
    color: #374151;
}

.notifications-pagination {
    margin-top: 2rem;
    display: flex;
    justify-content: center;
}

.no-notifications-page {
    text-align: center;
    padding: 4rem 2rem;
    background: white;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    border: 1px solid #e5e7eb;
}

.no-notifications-page-icon {
    margin-bottom: 1.5rem;
    opacity: 0.5;
    color: #6b7280;
}

.no-notifications-page h2 {
    font-size: 20px;
    font-weight: 600;
    color: #111827;
    margin: 0 0 8px 0;
}

.no-notifications-page p {
    font-size: 14px;
    color: #6b7280;
    margin: 0;
}

@media (max-width: 768px) {
    .notifications-page-header {
        flex-direction: column;
        gap: 1rem;
        align-items: stretch;
    }
    
    .header-content {
        justify-content: center;
    }
    
    .mark-all-read-page-btn {
        width: 100%;
        justify-content: center;
    }
    
    .notification-page-item {
        padding: 16px;
    }
}
</style>
@endsection
