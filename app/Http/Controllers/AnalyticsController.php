<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function index()
    {
        // Task stats
        $totalTasks = \App\Models\Task::count();
        $completedTasks = \App\Models\Task::where('status', 'Completed')->count();
        $inProgressTasks = \App\Models\Task::where('status', 'In Progress')->count();
        $overdueTasks = \App\Models\Task::where('due_date', '<', now())->where('status', '!=', 'Completed')->count();
        $completionRate = $totalTasks ? round($completedTasks / $totalTasks * 100) : 0;
        $avgProgress = $totalTasks ? round(\App\Models\Task::avg('progress')) : 0;

        // Priority breakdown
        $priorityCounts = \App\Models\Task::groupBy('priority')->selectRaw('priority, COUNT(*) as count')->pluck('count', 'priority');
        // Productivity trend (last 7 days)
        $days = collect(range(0, 6))->map(function($i) {
            return now()->subDays(6 - $i)->format('D');
        });
        $productivity = $days->mapWithKeys(function($day) {
            $date = now()->parse($day)->format('Y-m-d');
            $count = \App\Models\Task::whereDate('updated_at', $date)->count();
            return [$day => $count];
        });

        // Team performance
        $users = \App\Models\User::with(['role', 'tasks'])->get();
        $teamPerformance = $users->map(function($user) {
            $tasks = $user->tasks ?? collect();
            $total = $tasks->count();
            $completed = $tasks->where('status', 'Completed')->count();
            $overdue = $tasks->where('due_date', '<', now())->where('status', '!=', 'Completed')->count();
            $completionRate = $total ? round($completed / $total * 100) : 0;
            return [
                'name' => $user->name,
                'avatar' => strtoupper(substr($user->name, 0, 2)),
                'total' => $total,
                'completed' => $completed,
                'overdue' => $overdue,
                'completionRate' => $completionRate,
            ];
        });

        // Project performance
        $projects = \App\Models\Project::with('tasks')->get();
        $projectPerformance = $projects->map(function($project) {
            $tasks = $project->tasks;
            $total = $tasks->count();
            $done = $tasks->where('status', 'Completed')->count();
            $progress = $total ? round($tasks->avg('progress')) : 0;
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
            'Completed ' . $completedTasks . ' tasks successfully',
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
            'totalTasks', 'completedTasks', 'inProgressTasks', 'overdueTasks', 'completionRate', 'avgProgress',
            'priorityCounts', 'productivity', 'teamPerformance', 'projectPerformance',
            'achievements', 'improvements', 'lastUpdated'
        ));
    }
}
