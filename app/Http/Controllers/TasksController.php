<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\TaskHistory;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskCompletedNotification;
use App\Notifications\TaskUpdatedNotification;
use App\Notifications\TaskOverdueNotification;
use App\Http\Requests\TaskStoreRequest;
use App\Http\Requests\TaskUpdateRequest;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class TasksController extends Controller
{
    use AuthorizesRequests;

    public function index()
    {
        $tasks = \App\Models\Task::with(['assignee'])->get();
        $assignees = \App\Models\User::all();
        return view('tasks', compact('tasks', 'assignees'));
    }

    public function store(TaskStoreRequest $request)
    {
        try {
            $data = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'assignee_id' => 'nullable|exists:users,id',
                'priority' => 'required|string',
                'status' => 'required|string',
                'progress' => 'required|integer|min:0|max:100',
                'start_date' => 'nullable|date',
                'due_date' => 'nullable|date',
                'comments' => 'nullable|string',
            ]);
            // Start date <= due date
            if (!empty($data['start_date']) && !empty($data['due_date']) && $data['start_date'] > $data['due_date']) {
                return response()->json(['success' => false, 'message' => 'Start date must be before or equal to due date.'], 422);
            }
            $task = \App\Models\Task::create($data);
            TaskHistory::create([
                'task_id' => $task->id,
                'user_id' => auth()->id(),
                'action' => 'created',
                'changes' => json_encode($data),
            ]);
            if ($task->assignee) {
                $task->assignee->notify(new TaskAssignedNotification($task, auth()->user()));
            }
            return response()->json(['success' => true, 'task' => $task->load(['assignee'])]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Server Error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(TaskUpdateRequest $request, $id)
    {
        $task = \App\Models\Task::findOrFail($id);
        try {
            $data = $request->all();
            // Fill missing required fields with current values
            $fields = ['title', 'priority', 'status', 'progress', 'assignee_id', 'start_date', 'due_date', 'description', 'comments'];
            foreach ($fields as $field) {
                if (!array_key_exists($field, $data) || $data[$field] === null) {
                    $data[$field] = $task->$field;
                }
            }
            // Start date <= due date
            if (!empty($data['start_date']) && !empty($data['due_date']) && $data['start_date'] > $data['due_date']) {
                return response()->json(['success' => false, 'message' => 'Start date must be before or equal to due date.'], 422);
            }

            // Progress must be 100% to mark as completed
            if ($data['status'] === 'Completed' && $data['progress'] < 100) {
                return response()->json(['success' => false, 'message' => 'Progress must be 100% to mark task as completed.'], 422);
            }

            if ($data['status'] === 'Completed') {
                // Move task to completed_tasks table first
                $taskData = collect($task->toArray())
                    ->only((new \App\Models\CompletedTask)->getFillable())
                    ->toArray();
                unset($taskData['id']); // Let DB assign new ID
                $taskData['status'] = 'Completed';
                $taskData['progress'] = 100; // Ensure progress is 100%
                
                $completed = new \App\Models\CompletedTask($taskData);
                $completed->completed_at = now();
                $completed->save();
                
                // Create task history for the original task before deleting it
                TaskHistory::create([
                    'task_id' => $task->id,
                    'user_id' => auth()->id(),
                    'action' => 'completed',
                    'changes' => json_encode($data),
                ]);
                
                // Send notification to assignee
                if ($task->assignee) {
                    $task->assignee->notify(new TaskCompletedNotification($completed, auth()->user(), true));
                }
                
                // Send notification to assignor (task creator) if different from assignee
                if (auth()->id() !== $task->assignee_id && $task->assignee_id) {
                    $assignor = \App\Models\User::find($task->assignee_id);
                    if ($assignor) {
                        $assignor->notify(new TaskCompletedNotification($completed, auth()->user(), false));
                    }
                }
                
                // Delete original task after creating history
                $task->delete();
                
                return response()->json(['success' => true, 'moved' => true, 'task' => $completed->load('assignee')]);
            } else {
                $old = $task->toArray();
                $task->update($data);
                $task->refresh();
                $task->load('assignee');
                $taskArr = $task->toArray();
                $taskArr['start_date'] = $task->start_date ? date('Y-m-d', strtotime($task->start_date)) : null;
                $taskArr['due_date'] = $task->due_date ? date('Y-m-d', strtotime($task->due_date)) : null;
                TaskHistory::create([
                    'task_id' => $task->id,
                    'user_id' => auth()->id(),
                    'action' => 'updated',
                    'changes' => json_encode(['old' => $old, 'new' => $data]),
                ]);
                
                // Send task update notification to assignee
                if ($task->assignee && auth()->id() !== $task->assignee_id) {
                    $task->assignee->notify(new TaskUpdatedNotification($task, $data, auth()->user()));
                }
                
                // Check for overdue tasks and send notification
                if ($task->due_date && $task->due_date < now() && $task->status !== 'Completed') {
                    if ($task->assignee) {
                        $task->assignee->notify(new TaskOverdueNotification($task));
                    }
                }
                
                return response()->json(['success' => true, 'task' => $taskArr]);
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
        $task = \App\Models\Task::findOrFail($id);
        // Remove authorize
        try {
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
        // Remove authorize
        $ids = $request->input('task_ids', []);
        if (!is_array($ids) || empty($ids)) {
            return response()->json(['success' => false, 'message' => 'No tasks selected.'], 422);
        }
        $tasks = \App\Models\Task::whereIn('id', $ids)->get();
        
        // Create TaskHistory records before deleting tasks
        foreach ($tasks as $task) {
            TaskHistory::create([
                'task_id' => $task->id,
                'user_id' => auth()->id(),
                'action' => 'bulk_deleted',
                'changes' => json_encode($task->toArray()),
            ]);
        }
        
        // Delete tasks after creating history records
        foreach ($tasks as $task) {
            $task->delete();
        }
        return response()->json(['success' => true]);
    }

    public function bulkComplete(Request $request)
    {
        // Remove authorize
        $ids = $request->input('task_ids', []);
        if (!is_array($ids) || empty($ids)) {
            return response()->json(['success' => false, 'message' => 'No tasks selected.'], 422);
        }
        
        $tasks = \App\Models\Task::whereIn('id', $ids)->get();
        
        // Validate all tasks have 100% progress
        foreach ($tasks as $task) {
            if ($task->progress < 100) {
                return response()->json(['success' => false, 'message' => "Task '{$task->title}' must have 100% progress to be completed."], 422);
            }
        }
        
        DB::beginTransaction();
        try {
            foreach ($tasks as $task) {
                if ($task->status !== 'Completed') {
                    // Create task history for the original task before moving it
                    TaskHistory::create([
                        'task_id' => $task->id,
                        'user_id' => auth()->id(),
                        'action' => 'bulk_completed',
                        'changes' => json_encode($task->toArray()),
                    ]);
                    
                    $taskData = collect($task->toArray())
                        ->only((new \App\Models\CompletedTask)->getFillable())
                        ->toArray();
                    unset($taskData['id']); // Let DB assign new ID
                    $taskData['status'] = 'Completed';
                    
                    $completed = new \App\Models\CompletedTask($taskData);
                    $completed->completed_at = now();
                    $completed->save();
                    
                    // Notify assignee
                    if ($task->assignee) {
                        $task->assignee->notify(new TaskCompletedNotification($completed, auth()->user(), true));
                    }
                    // Notify assignor (task creator) - only if different from assignee
                    if (auth()->id() !== $task->assignee_id && $task->assignee_id) {
                        $assignor = \App\Models\User::find($task->assignee_id);
                        if ($assignor) {
                            $assignor->notify(new TaskCompletedNotification($completed, auth()->user(), false));
                        }
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

    public function edit($id)
    {
        $task = \App\Models\Task::findOrFail($id);
        // Format dates for HTML input type="date"
        $taskArr = $task->toArray();
        $taskArr['start_date'] = $task->start_date ? date('Y-m-d', strtotime($task->start_date)) : null;
        $taskArr['due_date'] = $task->due_date ? date('Y-m-d', strtotime($task->due_date)) : null;
        return response()->json([
            'success' => true,
            'task' => $taskArr
        ]);
    }
}
