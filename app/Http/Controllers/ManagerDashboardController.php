<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ManagerDashboardController extends Controller
{
    // Blade: Initial dashboard load
    public function __invoke()
    {
        $projects = Project::with('tasks.assignee')->get();
        $tasks = Task::with(['project', 'assignee'])->get();
        $totalTasks = $tasks->count();
        $completedTasks = $tasks->where('status', 'Completed')->count();
        $progress = $totalTasks ? round($completedTasks / $totalTasks * 100) : 0;
        $recentTasks = $tasks->sortByDesc('created_at')->take(5);

        // Notifications: last 5 assigned, completed, or overdue tasks
        $notifications = collect();
        foreach ($recentTasks as $task) {
            if ($task->status === 'Completed') {
                $notifications->push(['type' => 'completed', 'text' => "Task '{$task->title}' was completed by " . ($task->assignee ? $task->assignee->name : 'Unassigned') . "."]);
            } elseif ($task->due_date && $task->due_date < now() && $task->status !== 'Completed') {
                $notifications->push(['type' => 'overdue', 'text' => "Task '{$task->title}' is overdue."]);
            } else {
                $notifications->push(['type' => 'assigned', 'text' => "Task '{$task->title}' was assigned to " . ($task->assignee ? $task->assignee->name : 'Unassigned') . "."]);
            }
        }

        // Chart: Tasks by Status
        $statusCounts = $tasks->groupBy('status')->map->count();
        // Chart: Tasks by Priority
        $priorityCounts = $tasks->groupBy('priority')->map->count();
        // Chart: Active Projects
        $activeProjects = $projects->where('status', 'active');
        // Team Workload: tasks per assignee
        $teamWorkload = $tasks->groupBy(fn($t) => $t->assignee ? $t->assignee->name : 'Unassigned')->map->count();
        // Overdue Tasks
        $overdueTasks = $tasks->where('due_date', '<', now())->where('status', '!=', 'Completed');
        // Insights: Key Achievements & Improvements (simple logic)
        $achievements = [
            'Completed ' . $completedTasks . ' tasks successfully',
            'Maintaining ' . $progress . '% average progress rate',
            'Most tasks completed on schedule',
        ];
        $improvements = [
            'Focus on ' . $overdueTasks->count() . ' overdue task(s)',
            'Balance high-priority task load',
            'Consider breaking down complex tasks',
        ];

        return view('manager-dashboard', compact(
            'projects', 'tasks', 'progress', 'recentTasks',
            'statusCounts', 'priorityCounts', 'activeProjects',
            'teamWorkload', 'overdueTasks', 'achievements', 'improvements', 'notifications'
        ));
    }

    // AJAX: Create a new task
    public function storeTask(Request $request)
    {
        $task = Task::create($request->all());
        return response()->json($task);
    }

    // AJAX: Update a task
    public function updateTask(Request $request, $id)
    {
        $task = Task::findOrFail($id);
        $task->update($request->all());
        return response()->json($task);
    }

    // AJAX: Delete a task
    public function deleteTask($id)
    {
        $task = Task::findOrFail($id);
        $task->delete();
        return response()->json(['success' => true]);
    }
}
