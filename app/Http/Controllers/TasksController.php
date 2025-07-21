<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\TaskHistory;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskCompletedNotification;

class TasksController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        if ($user && $user->role && $user->role->name === 'team_member') {
            $tasks = \App\Models\Task::with(['project', 'assignee'])
                ->where('assignee_id', $user->id)
                ->get();
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
            // Assignment role validation
            if (!empty($data['assignee_id'])) {
                $assignee = \App\Models\User::find($data['assignee_id']);
                if (!$assignee || !$assignee->hasRole('team_member')) {
                    return response()->json(['success' => false, 'message' => 'Tasks can only be assigned to team members.'], 422);
                }
            }
            // Progress/status consistency
            if (($data['status'] === 'Completed' && $data['progress'] < 100) || ($data['progress'] == 100 && $data['status'] !== 'Completed')) {
                return response()->json(['success' => false, 'message' => 'If status is Completed, progress must be 100 and vice versa.'], 422);
            }
            // Start date <= due date
            if (!empty($data['start_date']) && !empty($data['due_date']) && $data['start_date'] > $data['due_date']) {
                return response()->json(['success' => false, 'message' => 'Start date must be before or equal to due date.'], 422);
            }
            $task = \App\Models\Task::create($data);
            // Audit trail: creation
            TaskHistory::create([
                'task_id' => $task->id,
                'user_id' => auth()->id(),
                'action' => 'created',
                'changes' => json_encode($data),
            ]);
            // Notify assignee
            if ($task->assignee) {
                $task->assignee->notify(new TaskAssignedNotification());
            }
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
            // Assignment role validation
            if (!empty($data['assignee_id'])) {
                $assignee = \App\Models\User::find($data['assignee_id']);
                if (!$assignee || !$assignee->hasRole('team_member')) {
                    return response()->json(['success' => false, 'message' => 'Tasks can only be assigned to team members.'], 422);
                }
            }
            // Progress/status consistency
            if (($data['status'] === 'Completed' && $data['progress'] < 100) || ($data['progress'] == 100 && $data['status'] !== 'Completed')) {
                return response()->json(['success' => false, 'message' => 'If status is Completed, progress must be 100 and vice versa.'], 422);
            }
            // Start date <= due date
            if (!empty($data['start_date']) && !empty($data['due_date']) && $data['start_date'] > $data['due_date']) {
                return response()->json(['success' => false, 'message' => 'Start date must be before or equal to due date.'], 422);
            }
            if ($data['status'] === 'Completed') {
                DB::beginTransaction();
                try {
                    // Prevent duplicate move
                    if (!\App\Models\CompletedTask::where('id', $task->id)->exists()) {
                        $completed = new \App\Models\CompletedTask($task->toArray());
                        $completed->completed_at = now();
                        $completed->save();
                        // Audit trail: completed
                        TaskHistory::create([
                            'task_id' => $task->id,
                            'user_id' => auth()->id(),
                            'action' => 'completed',
                            'changes' => json_encode($data),
                        ]);
                        // Notify assignee
                        if ($task->assignee) {
                            $task->assignee->notify(new TaskCompletedNotification());
                        }
                        $task->delete();
                        DB::commit();
                        return response()->json(['success' => true, 'moved' => true, 'task' => $completed]);
                    } else {
                        DB::rollBack();
                        return response()->json(['success' => false, 'message' => 'Task already completed.'], 409);
                    }
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            } else {
                $old = $task->toArray();
                $task->update($data);
                // Audit trail: update
                TaskHistory::create([
                    'task_id' => $task->id,
                    'user_id' => auth()->id(),
                    'action' => 'updated',
                    'changes' => json_encode(['old' => $old, 'new' => $data]),
                ]);
                return response()->json(['success' => true, 'task' => $task->load(['project', 'assignee'])]);
            }
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

    public function bulkDelete(Request $request)
    {
        $ids = $request->input('task_ids', []);
        if (!is_array($ids) || empty($ids)) {
            return response()->json(['success' => false, 'message' => 'No tasks selected.'], 422);
        }
        $tasks = \App\Models\Task::whereIn('id', $ids)->get();
        foreach ($tasks as $task) {
            $task->delete();
            TaskHistory::create([
                'task_id' => $task->id,
                'user_id' => auth()->id(),
                'action' => 'bulk_deleted',
                'changes' => json_encode($task->toArray()),
            ]);
        }
        return response()->json(['success' => true]);
    }

    public function bulkComplete(Request $request)
    {
        $ids = $request->input('task_ids', []);
        if (!is_array($ids) || empty($ids)) {
            return response()->json(['success' => false, 'message' => 'No tasks selected.'], 422);
        }
        DB::beginTransaction();
        try {
            $tasks = \App\Models\Task::whereIn('id', $ids)->get();
            foreach ($tasks as $task) {
                if ($task->status !== 'Completed') {
                    $completed = new \App\Models\CompletedTask($task->toArray());
                    $completed->completed_at = now();
                    $completed->save();
                    TaskHistory::create([
                        'task_id' => $task->id,
                        'user_id' => auth()->id(),
                        'action' => 'bulk_completed',
                        'changes' => json_encode($task->toArray()),
                    ]);
                    // Notify assignee
                    if ($task->assignee) {
                        $task->assignee->notify(new TaskCompletedNotification());
                    }
                    $task->delete();
                }
            }
            DB::commit();
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error completing tasks.', 'error' => $e->getMessage()], 500);
        }
    }
}
