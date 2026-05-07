<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CompletedTask;

class AnalyticsController extends Controller
{
    public function index(Request $request)
    {
        // Role-based access control
        $authUser = auth()->user();
        if (!$authUser || $authUser->role->name !== 'manager') {
            abort(403, 'Only managers can access analytics.');
        }

        $dateFrom = $request->input('dateFrom');
        $dateTo = $request->input('dateTo') ?: now()->format('Y-m-d');
        $assigneeId = $request->input('assignee');

        // Overall team analytics (not today-specific)
        $activeTasksQuery = \App\Models\Task::query();
        if ($assigneeId) $activeTasksQuery->where('assignee_id', $assigneeId);
        if ($dateFrom && $dateTo) {
            $activeTasksQuery->whereBetween('created_at', [$dateFrom, $dateTo]);
        }
        $activeTasks = $activeTasksQuery->get();

        // Historical completed tasks
        $completedTasksQuery = CompletedTask::query();
        if ($assigneeId) $completedTasksQuery->where('assignee_id', $assigneeId);
        if ($dateFrom && $dateTo) {
            $completedTasksQuery->whereBetween('completed_at', [$dateFrom, $dateTo]);
        }
        $completedTasks = $completedTasksQuery->get();

        // Overall team metrics
        $totalActiveTasks = $activeTasks->count();
        $totalCompletedTasks = $completedTasks->count();
        $totalTasksForRate = $totalActiveTasks + $totalCompletedTasks;
        $inProgressTasks = $activeTasks->where('status', 'In Progress')->count();
        $overdueTasks = $activeTasks->where('due_date', '<', now())->where('status', '!=', 'Completed')->count();
        $completionRate = $totalTasksForRate ? round($totalCompletedTasks / $totalTasksForRate * 100) : 0;
        $avgProgress = $activeTasks->count() ? round($activeTasks->avg('progress')) : 0;

        // Overall priority breakdown (all active tasks)
        $priorityCounts = $activeTasks->groupBy('priority')->map->count();
        
        // Overall status breakdown (all active tasks)
        $statusCounts = $activeTasks->groupBy('status')->map->count();
        
        // Historical productivity trend (last 30 days)
        $days = collect(range(0, 29))->map(function($i) {
            return now()->subDays(29 - $i)->format('Y-m-d');
        });
        $productivity = $days->mapWithKeys(function($date) use ($completedTasksQuery) {
            $count = (clone $completedTasksQuery)->whereDate('completed_at', $date)->count();
            return [\Carbon\Carbon::parse($date)->format('M d') => $count];
        });
        
        // Historical overdue trend
        $overdueTrend = $days->mapWithKeys(function($date) use ($activeTasksQuery) {
            $count = (clone $activeTasksQuery)->where('due_date', '<', $date)->where('status', '!=', 'Completed')->count();
            return [\Carbon\Carbon::parse($date)->format('M d') => $count];
        });

        $users = \App\Models\User::with(['role', 'tasks'])->get();

        // Overall team performance (historical)
        $teamPerformance = $users->map(function($user) use ($dateFrom, $dateTo, $assigneeId) {
            // Skip if assignee filter is set and doesn't match this user
            if ($assigneeId && $user->id != $assigneeId) {
                return null;
            }
            
            $active = $user->tasks();
            if ($dateFrom && $dateTo) {
                $active->whereBetween('created_at', [$dateFrom, $dateTo]);
            }
            $active = $active->get();
            $completed = CompletedTask::where('assignee_id', $user->id);
            if ($dateFrom && $dateTo) {
                $completed->whereBetween('completed_at', [$dateFrom, $dateTo]);
            }
            $completed = $completed->get();
            $total = $active->count() + $completed->count();
            $completedCount = $completed->count();
            $overdue = $active->where('due_date', '<', now())->where('status', '!=', 'Completed')->count();
            $completionRate = $total ? round($completedCount / $total * 100) : 0;
            return [
                'name' => $user->name,
                'avatar' => strtoupper(substr($user->name, 0, 2)),
                'total' => $total,
                'completed' => $completedCount,
                'overdue' => $overdue,
                'completionRate' => $completionRate,
            ];
        })->filter(); // Remove null entries

        // Overall insights (not today-specific)
        $achievements = [
            'Team completed ' . $totalCompletedTasks . ' tasks in selected period',
            'Maintaining ' . $avgProgress . '% average progress rate',
            'Overall completion rate: ' . $completionRate . '%',
        ];
        $improvements = [
            'Address ' . $overdueTasks . ' overdue task(s)',
            'Balance high-priority task distribution',
            'Monitor team productivity trends',
        ];

        // Last updated
        $lastUpdated = \App\Models\Task::latest('updated_at')->value('updated_at');

        return view('analytics', [
            'activeTasks' => $activeTasks,
            'completedTasks' => $completedTasks,
            'totalActiveTasks' => $totalActiveTasks,
            'totalCompletedTasks' => $totalCompletedTasks,
            'inProgressTasks' => $inProgressTasks,
            'overdueTasks' => $overdueTasks,
            'completionRate' => $completionRate,
            'avgProgress' => $avgProgress,
            'priorityCounts' => $priorityCounts,
            'statusCounts' => $statusCounts,
            'productivity' => $productivity,
            'overdueTrend' => $overdueTrend,
            'teamPerformance' => $teamPerformance,
            'achievements' => $achievements,
            'improvements' => $improvements,
            'lastUpdated' => $lastUpdated,
        ]);
    }
}
