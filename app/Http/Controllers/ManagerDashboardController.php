<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use App\Models\CompletedTask;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ManagerDashboardController extends Controller
{
    // Blade: Initial dashboard load
    public function __invoke()
    {
        // Today's focus - only current active tasks and today's completions
        $today = now()->toDateString();
        
        // Today's active tasks (created today OR currently active)
        $todayActiveTasks = Task::with(['assignee'])
            ->where(function($query) use ($today) {
                $query->whereDate('created_at', $today)
                      ->orWhere('status', '!=', 'Completed');
            })
            ->get();
            
        // Today's completed tasks
        $todayCompletedTasks = CompletedTask::with(['assignee'])
            ->whereDate('completed_at', $today)
            ->get();
            
        // Current active tasks (not completed)
        $currentActiveTasks = Task::with(['assignee'])
            ->where('status', '!=', 'Completed')
            ->get();
            
        // Dashboard metrics for today
        $todayTotalTasks = $todayActiveTasks->count();
        $todayCompletedCount = $todayCompletedTasks->count();
        $currentActiveCount = $currentActiveTasks->count();
        $todayProgress = $todayTotalTasks ? round($todayCompletedCount / $todayTotalTasks * 100) : 0;
        
        // Recent activity (last 5 tasks created or completed today)
        $recentTasks = $todayActiveTasks->sortByDesc('created_at')->take(3)
            ->concat($todayCompletedTasks->sortByDesc('completed_at')->take(2))
            ->sortByDesc(function($task) {
                return $task->created_at ?? $task->completed_at;
            })->take(5);

        // Today's notifications
        $notifications = collect();
        foreach ($recentTasks as $task) {
            if (($task->status ?? null) === 'Completed') {
                $notifications->push(['type' => 'completed', 'text' => "Task '{$task->title}' was completed by " . ($task->assignee ? $task->assignee->name : 'Unassigned') . "."]);
            } elseif (($task->due_date ?? null) && ($task->due_date < now()) && ($task->status ?? null) !== 'Completed') {
                $notifications->push(['type' => 'overdue', 'text' => "Task '{$task->title}' is overdue."]);
            } else {
                $notifications->push(['type' => 'assigned', 'text' => "Task '{$task->title}' was assigned to " . ($task->assignee ? $task->assignee->name : 'Unassigned') . "."]);
            }
        }

        // Today's charts - only current active tasks
        $statusCounts = $currentActiveTasks->groupBy('status')->map->count();
        $priorityCounts = $currentActiveTasks->groupBy('priority')->map->count();
        
        // Today's overdue tasks
        $todayOverdueTasks = $currentActiveTasks->where('due_date', '<', now());
        
        // Today's insights
        $achievements = [
            'Completed ' . $todayCompletedCount . ' tasks today',
            'Currently managing ' . $currentActiveCount . ' active tasks',
            'Today\'s progress: ' . $todayProgress . '%',
        ];
        $improvements = [
            'Focus on ' . $todayOverdueTasks->count() . ' overdue task(s)',
            'Balance high-priority task load',
            'Monitor task progress throughout the day',
        ];

        return view('manager-dashboard', compact(
            'todayActiveTasks', 'todayCompletedTasks', 'currentActiveTasks',
            'todayTotalTasks', 'todayCompletedCount', 'currentActiveCount', 'todayProgress',
            'recentTasks', 'statusCounts', 'priorityCounts',
            'todayOverdueTasks', 'achievements', 'improvements', 'notifications'
        ));
    }

    // AJAX: Create a new task
    public function storeTask(Request $request)
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

            // Progress must be 100% to mark as completed
            if ($data['status'] === 'Completed' && $data['progress'] < 100) {
                return response()->json(['success' => false, 'message' => 'Progress must be 100% to mark task as completed.'], 422);
            }

            $task = Task::create($data);
            return response()->json(['success' => true, 'task' => $task]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error creating task.', 'error' => $e->getMessage()], 500);
        }
    }

    // AJAX: Update a task
    public function updateTask(Request $request, $id)
    {
        try {
            $task = Task::findOrFail($id);
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

            // Progress must be 100% to mark as completed
            if ($data['status'] === 'Completed' && $data['progress'] < 100) {
                return response()->json(['success' => false, 'message' => 'Progress must be 100% to mark task as completed.'], 422);
            }

            $task->update($data);
            return response()->json(['success' => true, 'task' => $task]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error updating task.', 'error' => $e->getMessage()], 500);
        }
    }

    // AJAX: Delete a task
    public function deleteTask($id)
    {
        $task = Task::findOrFail($id);
        $task->delete();
        return response()->json(['success' => true]);
    }
}
