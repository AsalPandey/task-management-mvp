<?php

namespace App\Http\Controllers;

use App\Services\NotificationPreferencePolicy;
use App\Support\UserPayload;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index(NotificationPreferencePolicy $policy)
    {
        $user = auth()->user();
        $taskCompletedEnabled = $policy->decideForType($user, 'task_approved_completed', 'database')->allowed;
        $teamUpdatesEnabled = $policy->decideForType($user, 'task_updated', 'database')->allowed;

        return view('settings', compact('user', 'taskCompletedEnabled', 'teamUpdatesEnabled'));
    }

    public function updateProfile(Request $request)
    {
        $user = auth()->user();
        $data = $request->validate([
            'profileFullName' => 'required|string|max:255',
            'profileEmail' => 'required|email|max:255|unique:users,email,'.$user->id,
        ]);
        $user->name = $data['profileFullName'];
        $user->email = $data['profileEmail'];
        $user->save();

        return response()->json(['success' => true, 'message' => 'Profile updated successfully.', 'user' => UserPayload::self($user)]);
    }
}
