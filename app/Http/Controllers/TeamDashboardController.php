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
        $inProgressTasks = $tasks->where('status', 'In Progress')->count();
        $overdueTasks = $tasks->where('due_date', '<', now())->where('status', '!=', 'Completed')->count();
        $avgProgress = $totalTasks ? round($tasks->avg('progress')) : 0;
        $completionRate = $totalTasks ? round(($tasks->where('status', 'Completed')->count()) / $totalTasks * 100) : 0;
        $priorityCounts = [
            'High' => $tasks->where('priority', 'High')->count(),
            'Medium' => $tasks->where('priority', 'Medium')->count(),
            'Low' => $tasks->where('priority', 'Low')->count(),
        ];
        $statusCounts = [
            'Completed' => 0, // will be set below
            'In Progress' => $inProgressTasks,
            'Overdue' => $overdueTasks,
        ];
        $overdueList = $tasks->where('due_date', '<', now())->where('status', '!=', 'Completed');
        // Completed tasks today
        $todayCompletedTasks = \App\Models\CompletedTask::where('assignee_id', $user->id)
            ->whereDate('completed_at', now()->toDateString())
            ->count();
        $statusCounts['Completed'] = $todayCompletedTasks;
        // Productivity trend (last 7 days) and completed history
        $days = collect(range(0, 6))->map(function($i) {
            return now()->subDays(6 - $i)->format('Y-m-d');
        });
        $productivity = $days->mapWithKeys(function($date) use ($user) {
            $count = \App\Models\CompletedTask::where('assignee_id', $user->id)
                ->whereDate('completed_at', $date)
                ->count();
            return [now()->parse($date)->format('D') => $count];
        });
        $completedHistory = \App\Models\CompletedTask::with('project')
            ->where('assignee_id', $user->id)
            ->where('completed_at', '>=', now()->subDays(7))
            ->orderBy('completed_at', 'desc')
            ->get();
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
        $achievements = [
            'Completed ' . $todayCompletedTasks . ' tasks today',
            'Maintaining ' . $avgProgress . '% progress rate',
        ];
        $improvements = [
            'Focus on ' . $overdueTasks . ' overdue task(s)',
            'Consider breaking down complex tasks',
        ];
        return view('team-dashboard', compact(
            'user', 'tasks', 'totalTasks', 'todayCompletedTasks', 'inProgressTasks', 'overdueTasks', 'avgProgress', 'completionRate', 'priorityCounts', 'statusCounts', 'overdueList', 'productivity', 'notifications', 'achievements', 'improvements', 'completedHistory'
        ));
    }
}
