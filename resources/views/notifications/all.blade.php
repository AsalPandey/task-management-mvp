@extends('layouts.app')
@section('content')
<div class="container" style="max-width:700px; margin:2rem auto;">
    <h2 style="font-weight:600; color:#4f8cff; margin-bottom:1.5rem;">All Notifications</h2>
    @if($notifications->count())
        <ul style="list-style:none; padding:0;">
            @foreach($notifications as $notification)
                <li style="background:{{ $notification->read_at ? '#fff' : '#f4f8ff' }}; border-radius:8px; margin-bottom:1rem; padding:1rem 1.2rem; box-shadow:0 1px 4px 0 rgba(60,72,88,0.06);">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <span>{{ $notification->data['message'] ?? 'You have a new notification.' }}</span>
                        @if(!$notification->read_at)
                        <form method="POST" action="{{ route('notifications.read', $notification->id) }}">
                            @csrf
                            <button type="submit" style="background:none; border:none; color:#4f8cff; font-size:0.95rem; cursor:pointer;">Mark as read</button>
                        </form>
                        @endif
                    </div>
                    <div style="color:#888; font-size:0.92rem; margin-top:0.5rem;">{{ $notification->created_at->diffForHumans() }}</div>
                </li>
            @endforeach
        </ul>
        <div style="margin-top:2rem;">{{ $notifications->links() }}</div>
    @else
        <div style="color:#888; text-align:center; margin-top:2rem;">No notifications found.</div>
    @endif
</div>
@endsection 