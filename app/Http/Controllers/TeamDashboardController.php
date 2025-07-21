<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class TeamDashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $tasks = \App\Models\Task::with('project')->where('assignee_id', $user->id)->get();
        $totalTasks = $tasks->count();
        $completedTasks = $tasks->where('status', 'Completed')->count();
        $inProgressTasks = $tasks->where('status', 'In Progress')->count();
        $overdueTasks = $tasks->where('due_date', '<', now())->where('status', '!=', 'Completed')->count();
        $avgProgress = $totalTasks ? round($tasks->avg('progress')) : 0;
        $completionRate = $totalTasks ? round($completedTasks / $totalTasks * 100) : 0;
        $priorityCounts = [
            'High' => $tasks->where('priority', 'High')->count(),
            'Medium' => $tasks->where('priority', 'Medium')->count(),
            'Low' => $tasks->where('priority', 'Low')->count(),
        ];
        $statusCounts = [
            'Completed' => $completedTasks,
            'In Progress' => $inProgressTasks,
            'Overdue' => $overdueTasks,
        ];
        $overdueList = $tasks->where('due_date', '<', now())->where('status', '!=', 'Completed');
        // Productivity trend (last 7 days)
        $days = collect(range(0, 6))->map(function($i) {
            return now()->subDays(6 - $i)->format('D');
        });
        $productivity = $days->mapWithKeys(function($day) use ($tasks) {
            $date = now()->parse($day)->format('Y-m-d');
            $count = $tasks->where('updated_at', '>=', $date . ' 00:00:00')->where('updated_at', '<=', $date . ' 23:59:59')->count();
            return [$day => $count];
        });
        // Notifications: last 5 assigned, completed, or overdue tasks for this user
        $notifications = collect();
        foreach ($tasks->sortByDesc('created_at')->take(5) as $task) {
            if ($task->status === 'Completed') {
                $notifications->push(['type' => 'completed', 'text' => "Task '{$task->title}' was completed."]);
            } elseif ($task->due_date && $task->due_date < now() && $task->status !== 'Completed') {
                $notifications->push(['type' => 'overdue', 'text' => "Task '{$task->title}' is overdue."]);
            } else {
                $notifications->push(['type' => 'assigned', 'text' => "Task '{$task->title}' was assigned to you."]);
            }
        }
        // Insights
        $achievements = [
            'Completed ' . $completedTasks . ' tasks successfully',
            'Maintaining ' . $avgProgress . '% progress rate',
        ];
        $improvements = [
            'Focus on ' . $overdueTasks . ' overdue task(s)',
            'Consider breaking down complex tasks',
        ];
        return view('team-dashboard', compact(
            'user', 'tasks', 'totalTasks', 'completedTasks', 'inProgressTasks', 'overdueTasks', 'avgProgress', 'completionRate', 'priorityCounts', 'statusCounts', 'overdueList', 'productivity', 'notifications', 'achievements', 'improvements'
        ));
    }
}
