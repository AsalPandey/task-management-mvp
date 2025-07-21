<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class TeamManagementController extends Controller
{
    public function index()
    {
        $users = \App\Models\User::with('role')->orderBy('created_at', 'desc')->get();
        return view('team-management', compact('users'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
        ]);
        $data['password'] = bcrypt($data['password']);
        $user = \App\Models\User::create($data);
        return response()->json($user->load('role'));
    }

    public function update(Request $request, $id)
    {
        $user = \App\Models\User::findOrFail($id);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $id,
            'password' => 'nullable|string|min:6',
        ]);
        if (!empty($data['password'])) {
            $data['password'] = bcrypt($data['password']);
        } else {
            unset($data['password']);
        }
        $user->update($data);
        return response()->json($user->load('role'));
    }

    public function destroy($id)
    {
        $user = \App\Models\User::findOrFail($id);
        $activeTasks = \App\Models\Task::where('assignee_id', $id)->count();
        $completedTasks = \App\Models\CompletedTask::where('assignee_id', $id)->count();
        if ($activeTasks > 0 || $completedTasks > 0) {
            return response()->json(['success' => false, 'message' => 'Cannot delete user with assigned tasks. Please reassign or delete all tasks first.'], 409);
        }
        $user->delete();
        return response()->json(['success' => true]);
    }
}
