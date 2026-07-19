<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Services\TaskReadService;

class ManagerDashboardController extends Controller
{
    public function __construct(private readonly TaskReadService $taskReads) {}

    public function __invoke()
    {
        $user = auth()->user();
        abort_unless($user && $user->hasAnyRole(['manager', 'project_manager']), 403);

        $today = now()->toDateString();
        $taskQuery = $this->taskReads->activeVisibleTo($user);
        $completedQuery = $this->taskReads->completedVisibleTo($user);

        $todayActiveTasks = (clone $taskQuery)->with(['assignee', 'project'])
            ->get();

        $todayCompletedTasks = (clone $completedQuery)->with(['assignee', 'project'])
            ->whereDate('completed_at', $today)
            ->get();

        $currentActiveTasks = (clone $taskQuery)->with(['assignee', 'project'])
            ->get();

        $todayTotalTasks = $todayActiveTasks->count();
        $todayCompletedCount = $todayCompletedTasks->count();
        $currentActiveCount = $currentActiveTasks->count();
        $todayProgress = $todayTotalTasks ? round($todayCompletedCount / $todayTotalTasks * 100) : 0;

        $recentTasks = $todayActiveTasks->sortByDesc('created_at')->take(3)
            ->concat($todayCompletedTasks->sortByDesc('completed_at')->take(2))
            ->sortByDesc(fn ($task) => $task->completed_at ?? $task->created_at)
            ->take(5);

        $notifications = collect();
        foreach ($recentTasks as $task) {
            if (($task->status ?? null) === 'Completed') {
                $notifications->push(['type' => 'completed', 'text' => "Task '{$task->title}' was completed by ".($task->assignee ? $task->assignee->name : 'Unassigned').'.']);
            } elseif (($task->due_date ?? null) && ($task->due_date < now()) && ($task->status ?? null) !== 'Completed') {
                $notifications->push(['type' => 'overdue', 'text' => "Task '{$task->title}' is overdue."]);
            } else {
                $notifications->push(['type' => 'assigned', 'text' => "Task '{$task->title}' was assigned to ".($task->assignee ? $task->assignee->name : 'Unassigned').'.']);
            }
        }

        $statusCounts = $currentActiveTasks->groupBy('status')->map->count();
        $priorityCounts = $currentActiveTasks->groupBy('priority')->map->count();
        $todayOverdueTasks = $currentActiveTasks->where('due_date', '<', now());

        $achievements = [
            'Completed '.$todayCompletedCount.' tasks today',
            'Currently managing '.$currentActiveCount.' active tasks',
            'Today\'s progress: '.$todayProgress.'%',
        ];
        $improvements = [
            'Focus on '.$todayOverdueTasks->count().' overdue task(s)',
            'Balance high-priority task load',
            'Monitor task progress throughout the day',
        ];

        $projects = $this->visibleProjects()
            ->whereNotIn('status', ['completed', 'archived'])
            ->with(['members' => fn ($query) => $query->where('active', true)->orderBy('name')])
            ->orderBy('name')
            ->get();
        $assignees = $this->visibleAssignees()->get();

        return view('manager-dashboard', compact(
            'todayActiveTasks',
            'todayCompletedTasks',
            'currentActiveTasks',
            'todayTotalTasks',
            'todayCompletedCount',
            'currentActiveCount',
            'todayProgress',
            'recentTasks',
            'statusCounts',
            'priorityCounts',
            'todayOverdueTasks',
            'achievements',
            'improvements',
            'notifications',
            'projects',
            'assignees',
        ));
    }

    private function visibleProjects()
    {
        $user = auth()->user();

        if ($user->hasRole('manager')) {
            return Project::query();
        }

        return Project::query()->where('project_manager_id', $user->id);
    }

    private function visibleAssignees()
    {
        $user = auth()->user();

        $query = User::query()
            ->with('role')
            ->where('active', true)
            ->whereHas('role', fn ($roleQuery) => $roleQuery->whereIn('name', ['project_manager', 'team_member']))
            ->orderBy('name');

        if ($user->hasRole('manager')) {
            return $query;
        }

        return $query->whereHas('projects', fn ($projectQuery) => $projectQuery->where('project_manager_id', $user->id));
    }
}
