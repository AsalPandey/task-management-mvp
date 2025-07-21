<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class TasksController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        if ($user && $user->role && $user->role->name === 'team_member') {
            $tasks = \App\Models\Task::with(['project', 'assignee'])->where('assignee_id', $user->id)->get();
        } else {
            $tasks = \App\Models\Task::with(['project', 'assignee'])->get();
        }
        $projects = \App\Models\Project::all();
        $assignees = \App\Models\User::all();
        return view('tasks', compact('tasks', 'projects', 'assignees'));
    }

    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'project_id' => 'nullable|exists:projects,id',
                'assignee_id' => 'nullable|exists:users,id',
                'priority' => 'required|string',
                'status' => 'required|string',
                'progress' => 'required|integer|min:0|max:100',
                'start_date' => 'nullable|date',
                'due_date' => 'nullable|date',
                'comments' => 'nullable|string',
            ]);
            $task = \App\Models\Task::create($data);
            return response()->json(['success' => true, 'task' => $task->load(['project', 'assignee'])]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Server Error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $task = \App\Models\Task::findOrFail($id);
            $data = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'project_id' => 'nullable|exists:projects,id',
                'assignee_id' => 'nullable|exists:users,id',
                'priority' => 'required|string',
                'status' => 'required|string',
                'progress' => 'required|integer|min:0|max:100',
                'start_date' => 'nullable|date',
                'due_date' => 'nullable|date',
                'comments' => 'nullable|string',
            ]);
            $task->update($data);
            return response()->json(['success' => true, 'task' => $task->load(['project', 'assignee'])]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Server Error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $task = \App\Models\Task::findOrFail($id);
            $task->delete();
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Server Error',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
