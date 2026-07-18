<?php

namespace App\Http\Controllers;

class NotificationController extends Controller
{
    public function markAsRead($id)
    {
        $user = auth()->user();
        $notification = $user->notifications()->where('id', $id)->first();
        if ($notification) {
            $notification->markAsRead();
        }

        if (request()->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return back();
    }

    public function markAllAsRead()
    {
        $user = auth()->user();
        $user->unreadNotifications->markAsRead();

        if (request()->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return back();
    }

    public function all()
    {
        $user = auth()->user();
        $notifications = $user->notifications()->orderBy('created_at', 'desc')->paginate(20);

        return view('notifications.all', compact('notifications'));
    }
}
