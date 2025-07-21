<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ProjectHistory;

class ProjectsController extends Controller
{
    public function index()
    {
        $projects = \App\Models\Project::with('tasks')->orderBy('created_at', 'desc')->get();
        return view('projects', compact('projects'));
    }

    public function store(Request $request)
    {
        $user = auth()->user();
        // Restrict to manager or teamleader
        if (!$user || !in_array($user->role->name, ['manager', 'teamleader'])) {
            return response()->json(['success' => false, 'message' => 'Only managers or teamleaders can create projects.'], 403);
        }
        try {
            $data = $request->validate([
                'name' => 'required|string|max:255|unique:projects,name',
                'description' => 'nullable|string',
                'color' => 'nullable|string|max:20',
                'status' => 'required|string',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date',
            ]);
            // Project date validation
            if (!empty($data['start_date']) && !empty($data['end_date']) && $data['start_date'] > $data['end_date']) {
                return response()->json(['success' => false, 'message' => 'Start date must be before or equal to end date.'], 422);
            }
            $project = \App\Models\Project::create($data);
            // Log creation
            ProjectHistory::create([
                'project_id' => $project->id,
                'user_id' => $user->id,
                'action' => 'created',
                'changes' => json_encode($data),
            ]);
            return response()->json(['success' => true, 'project' => $project->load('tasks')]);
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
        $user = auth()->user();
        try {
            $project = \App\Models\Project::findOrFail($id);
            $data = $request->validate([
                'name' => 'required|string|max:255|unique:projects,name,' . $id,
                'description' => 'nullable|string',
                'color' => 'nullable|string|max:20',
                'status' => 'required|string',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date',
            ]);
            // Project date validation
            if (!empty($data['start_date']) && !empty($data['end_date']) && $data['start_date'] > $data['end_date']) {
                return response()->json(['success' => false, 'message' => 'Start date must be before or equal to end date.'], 422);
            }
            // Prevent status change if active tasks exist
            if (in_array(strtolower($data['status']), ['completed', 'inactive'])) {
                $activeTasks = \App\Models\Task::where('project_id', $id)->where('status', '!=', 'Completed')->count();
                if ($activeTasks > 0) {
                    return response()->json(['success' => false, 'message' => 'Cannot mark project as completed/inactive while it has active tasks.'], 422);
                }
            }
            $old = $project->toArray();
            $project->update($data);
            // Log update
            ProjectHistory::create([
                'project_id' => $project->id,
                'user_id' => $user ? $user->id : null,
                'action' => 'updated',
                'changes' => json_encode(['old' => $old, 'new' => $data]),
            ]);
            return response()->json(['success' => true, 'project' => $project->load('tasks')]);
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
        $user = auth()->user();
        $project = \App\Models\Project::findOrFail($id);
        $activeTasks = \App\Models\Task::where('project_id', $id)->count();
        $completedTasks = \App\Models\CompletedTask::where('project_id', $id)->count();
        if ($activeTasks > 0 || $completedTasks > 0) {
            return response()->json(['success' => false, 'message' => 'Cannot delete project with assigned tasks. Please reassign or delete all tasks first.'], 409);
        }
        $project->delete();
        // Log deletion
        ProjectHistory::create([
            'project_id' => $project->id,
            'user_id' => $user ? $user->id : null,
            'action' => 'deleted',
            'changes' => json_encode($project->toArray()),
        ]);
        return response()->json(['success' => true]);
    }

    public function bulkDelete(Request $request)
    {
        $user = auth()->user();
        $ids = $request->input('project_ids', []);
        if (!is_array($ids) || empty($ids)) {
            return response()->json(['success' => false, 'message' => 'No projects selected.'], 422);
        }
        $projects = \App\Models\Project::whereIn('id', $ids)->get();
        foreach ($projects as $project) {
            $activeTasks = \App\Models\Task::where('project_id', $project->id)->count();
            $completedTasks = \App\Models\CompletedTask::where('project_id', $project->id)->count();
            if ($activeTasks > 0 || $completedTasks > 0) {
                continue; // Skip projects with assigned tasks
            }
            $project->delete();
            ProjectHistory::create([
                'project_id' => $project->id,
                'user_id' => $user ? $user->id : null,
                'action' => 'bulk_deleted',
                'changes' => json_encode($project->toArray()),
            ]);
        }
        return response()->json(['success' => true]);
    }
}
