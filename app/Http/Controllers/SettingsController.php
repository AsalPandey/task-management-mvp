<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        return view('settings', compact('user'));
    }

    public function updateProfile(Request $request)
    {
        $user = auth()->user();
        $data = $request->validate([
            'profileFullName' => 'required|string|max:255',
            'profileEmail' => 'required|email|max:255|unique:users,email,' . $user->id,
        ]);
        $user->name = $data['profileFullName'];
        $user->email = $data['profileEmail'];
        $user->save();
        return response()->json(['success' => true, 'message' => 'Profile updated successfully.', 'user' => $user]);
    }
}
