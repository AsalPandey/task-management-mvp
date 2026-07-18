<?php

namespace App\Http\Controllers;

use App\Models\CompletedTask;
use App\Models\Task;

class TeamDashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        abort_unless($user && $user->hasRole('team_member'), 403);
        $today = now()->toDateString();

        // Today's tasks for this team member (created today OR currently active)
        $todayTasks = Task::where('assignee_id', $user->id)
            ->with(['project', 'creator'])
            ->where(function ($query) use ($today) {
                $query->whereDate('created_at', $today)
                    ->orWhere('status', '!=', 'Completed');
            })
            ->get();

        // Current active tasks for this team member
        $currentTasks = Task::where('assignee_id', $user->id)
            ->with(['project', 'creator'])
            ->where('status', '!=', 'Completed')
            ->get();

        // Today's completed tasks
        $todayCompletedTasks = CompletedTask::where('assignee_id', $user->id)
            ->whereDate('completed_at', $today)
            ->count();

        // Today's metrics
        $todayTotalTasks = $todayTasks->count();
        $todayInProgressTasks = $todayTasks->where('status', 'In Progress')->count();
        $todayOverdueTasks = $todayTasks->where('due_date', '<', now())->where('status', '!=', 'Completed')->count();
        $todayAvgProgress = $todayTotalTasks ? round($todayTasks->avg('progress')) : 0;

        // Current workload metrics
        $currentTotalTasks = $currentTasks->count();
        $currentInProgressTasks = $currentTasks->where('status', 'In Progress')->count();
        $currentOverdueTasks = $currentTasks->where('due_date', '<', now())->where('status', '!=', 'Completed')->count();

        // Completion rate (today's completed vs today's total)
        $todayCompletionRate = $todayTotalTasks ? round($todayCompletedTasks / $todayTotalTasks * 100) : 0;

        // Today's priority breakdown
        $todayPriorityCounts = [
            'High' => $todayTasks->where('priority', 'High')->count(),
            'Medium' => $todayTasks->where('priority', 'Medium')->count(),
            'Low' => $todayTasks->where('priority', 'Low')->count(),
        ];

        // Today's status breakdown
        $todayStatusCounts = [
            'Not Started' => $todayTasks->where('status', 'Not Started')->count(),
            'In Progress' => $todayInProgressTasks,
            'Completed' => $todayTasks->where('status', 'Completed')->count(),
        ];

        // Today's overdue list
        $todayOverdueList = $todayTasks->where('due_date', '<', now())->where('status', '!=', 'Completed');

        // Today's productivity (last 7 days for context)
        $days = collect(range(0, 6))->map(function ($i) {
            return now()->subDays(6 - $i)->format('Y-m-d');
        });
        $productivity = $days->mapWithKeys(function ($date) use ($user) {
            $count = CompletedTask::where('assignee_id', $user->id)
                ->whereDate('completed_at', $date)
                ->count();

            return [now()->parse($date)->format('D') => $count];
        });

        // Recent completed tasks (last 7 days)
        $recentCompletedHistory = CompletedTask::where('assignee_id', $user->id)
            ->with('project')
            ->where('completed_at', '>=', now()->subDays(7))
            ->orderBy('completed_at', 'desc')
            ->get();

        // Today's notifications
        $notifications = collect();
        foreach ($todayTasks->sortByDesc('created_at')->take(5) as $task) {
            if ($task->status === 'Completed') {
                $notifications->push(['type' => 'completed', 'text' => "Task '{$task->title}' was completed."]);
            } elseif ($task->due_date && $task->due_date < now() && $task->status !== 'Completed') {
                $notifications->push(['type' => 'overdue', 'text' => "Task '{$task->title}' is overdue."]);
            } else {
                $notifications->push(['type' => 'assigned', 'text' => "Task '{$task->title}' was assigned to you."]);
            }
        }

        // Today's insights
        $achievements = [
            'Completed '.$todayCompletedTasks.' tasks today',
            'Currently working on '.$currentInProgressTasks.' tasks',
            'Today\'s progress: '.$todayAvgProgress.'%',
        ];
        $improvements = [
            'Focus on '.$todayOverdueTasks.' overdue task(s)',
            'Maintain steady progress on current tasks',
            'Prioritize high-priority tasks',
        ];

        return view('team-dashboard', compact(
            'user', 'todayTasks', 'currentTasks', 'todayTotalTasks', 'todayCompletedTasks',
            'todayInProgressTasks', 'todayOverdueTasks', 'todayAvgProgress', 'todayCompletionRate',
            'currentTotalTasks', 'currentInProgressTasks', 'currentOverdueTasks',
            'todayPriorityCounts', 'todayStatusCounts', 'todayOverdueList', 'productivity',
            'notifications', 'achievements', 'improvements', 'recentCompletedHistory'
        ));
    }
}
