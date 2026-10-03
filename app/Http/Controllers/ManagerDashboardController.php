<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Services\TaskAnalyticsService;
use App\Services\TaskAssignmentCandidateService;
use App\Services\TaskReadService;
use App\Support\ReadLimits;
use App\Support\TaskDeadlineSql;

class ManagerDashboardController extends Controller
{
    public function __construct(private readonly TaskReadService $taskReads, private readonly TaskAnalyticsService $analytics) {}

    public function __invoke()
    {
        $user = auth()->user();
        abort_unless($user && $user->hasAnyRole(['manager', 'project_manager']), 403);
        $today = today(config('app.timezone'))->toDateString();
        $taskQuery = $this->taskReads->activeVisibleTo($user);
        $completedQuery = $this->taskReads->completedVisibleTo($user)->whereDate('completed_at', $today);
        $summary = $this->analytics->summary($taskQuery);
        $todayTotalTasks = $currentActiveCount = $summary['totalActiveTasks'];
        $todayCompletedCount = (clone $completedQuery)->count();
        $executionTaskCount = $summary['executionTaskCount'];
        $reviewQueueTaskCount = $summary['reviewQueueTaskCount'];
        $totalOverdueCount = $summary['overdueTasks'];
        $todayProgress = $this->analytics->boundedCompletionRate($todayCompletedCount, $todayTotalTasks);
        // These collections are previews, never the source of summary totals.
        $todayActiveTasks = $currentActiveTasks = (clone $taskQuery)->with(['assignee', 'project'])
            ->latest('created_at')->orderByDesc('id')->limit(ReadLimits::RECENT_ACTIVE)->get();
        $todayCompletedTasks = (clone $completedQuery)->with(['assignee', 'project'])
            ->latest('completed_at')->orderByDesc('id')->limit(ReadLimits::RECENT_COMPLETED)->get();
        $recentTasks = $todayActiveTasks->concat($todayCompletedTasks)
            ->sortByDesc(fn ($task) => $task->completed_at ?? $task->created_at)->take(5);
        $todayOverdueTasks = (clone $taskQuery)->whereRaw(TaskDeadlineSql::date().' < ?', [$today])
            ->with(['assignee', 'project'])->orderByRaw(TaskDeadlineSql::date())->orderBy('id')->limit(ReadLimits::DASHBOARD_TASKS)->get();
        $statusCounts = $summary['statusCounts'];
        $priorityCounts = $summary['priorityCounts'];
        $notifications = $recentTasks->map(function ($task): array {
            if ($task->status === 'Completed') {
                return ['type' => 'completed', 'text' => "Task '{$task->title}' was completed by ".($task->assignee?->name ?? 'Unassigned').'.'];
            }
            if ($task->activeDeadlineGeneration()?->isOverdue()) {
                return ['type' => 'overdue', 'text' => "Task '{$task->title}' is overdue."];
            }

            return ['type' => 'assigned', 'text' => "Task '{$task->title}' was assigned to ".($task->assignee?->name ?? 'Unassigned').'.'];
        });
        $achievements = ['Completed '.$todayCompletedCount.' tasks today', 'Currently managing '.$currentActiveCount.' active tasks', "Today's progress: ".$todayProgress.'%'];
        $improvements = ['Focus on '.$totalOverdueCount.' overdue task(s)', 'Balance high-priority task load', 'Monitor task progress throughout the day'];
        $projects = $this->visibleProjects()->whereNotIn('status', ['completed', 'archived'])
            ->with(['members' => fn ($query) => $query->where('active', true)->orderBy('name')])->orderBy('name')->get();
        $assignmentCandidates = app(TaskAssignmentCandidateService::class)->forProjects($projects)->get(['id', 'name']);
        $reviewerCandidates = User::query()->where('active', true)
            ->whereHas('role', fn ($query) => $query->whereIn('name', ['manager', 'project_manager']))
            ->with('role')->orderBy('name')->get(['id', 'name', 'role_id']);

        return view('manager-dashboard', compact('todayActiveTasks', 'todayCompletedTasks', 'currentActiveTasks',
            'executionTaskCount', 'reviewQueueTaskCount', 'totalOverdueCount', 'todayTotalTasks', 'todayCompletedCount',
            'currentActiveCount', 'todayProgress', 'recentTasks', 'statusCounts', 'priorityCounts', 'todayOverdueTasks',
            'achievements', 'improvements', 'notifications', 'projects', 'assignmentCandidates', 'reviewerCandidates'));
    }

    private function visibleProjects()
    {
        $user = auth()->user();

        return $user->hasRole('manager') ? Project::query() : Project::query()->where('project_manager_id', $user->id);
    }
}
