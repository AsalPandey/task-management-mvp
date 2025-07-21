<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CompletedTask;

class AnalyticsController extends Controller
{
    public function index()
    {
        // Task stats
        $activeTasks = \App\Models\Task::all();
        $completedTasks = CompletedTask::all();
        $totalTasks = $activeTasks->count() + $completedTasks->count();
        $completedTasksCount = $completedTasks->count();
        $inProgressTasks = $activeTasks->where('status', 'In Progress')->count();
        $overdueTasks = $activeTasks->where('due_date', '<', now())->where('status', '!=', 'Completed')->count();
        $completionRate = $totalTasks ? round($completedTasksCount / $totalTasks * 100) : 0;
        $avgProgress = $totalTasks ? round((($activeTasks->avg('progress') * $activeTasks->count() + $completedTasks->avg('progress') * $completedTasks->count()) / $totalTasks)) : 0;

        // Priority breakdown
        $priorityCounts = $activeTasks->groupBy('priority')->map->count();
        foreach ($completedTasks->groupBy('priority') as $priority => $group) {
            $priorityCounts[$priority] = ($priorityCounts[$priority] ?? 0) + $group->count();
        }
        // Productivity trend (last 7 days)
        $days = collect(range(0, 6))->map(function($i) {
            return now()->subDays(6 - $i)->format('Y-m-d');
        });
        $productivity = $days->mapWithKeys(function($date) {
            $count = CompletedTask::whereDate('completed_at', $date)->count();
            return [now()->parse($date)->format('D') => $count];
        });

        // Team performance
        $users = \App\Models\User::with(['role', 'tasks'])->get();
        $teamPerformance = $users->map(function($user) {
            $active = $user->tasks ?? collect();
            $completed = CompletedTask::where('assignee_id', $user->id)->get();
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
        });

        // Project performance
        $projects = \App\Models\Project::with('tasks')->get();
        $projectPerformance = $projects->map(function($project) {
            $active = $project->tasks;
            $completed = CompletedTask::where('project_id', $project->id)->get();
            $total = $active->count() + $completed->count();
            $done = $completed->count();
            $progress = $total ? round((($active->avg('progress') * $active->count() + $completed->avg('progress') * $completed->count()) / $total)) : 0;
            return [
                'name' => $project->name,
                'color' => $project->color ?? '#3B82F6',
                'progress' => $progress,
                'total' => $total,
                'done' => $done,
            ];
        });

        // Insights
        $achievements = [
            'Completed ' . $completedTasksCount . ' tasks successfully',
            'Maintaining ' . $avgProgress . '% average progress rate',
            'Most tasks completed on schedule',
        ];
        $improvements = [
            'Focus on ' . $overdueTasks . ' overdue task(s)',
            'Balance high-priority task load',
            'Consider breaking down complex tasks',
        ];

        // Last updated
        $lastUpdated = \App\Models\Task::latest('updated_at')->value('updated_at');

        return view('analytics', compact(
            'totalTasks', 'completedTasksCount', 'inProgressTasks', 'overdueTasks', 'completionRate', 'avgProgress',
            'priorityCounts', 'productivity', 'teamPerformance', 'projectPerformance',
            'achievements', 'improvements', 'lastUpdated'
        ));
    }
}
