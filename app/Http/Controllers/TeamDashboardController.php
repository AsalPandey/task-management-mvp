<?php

namespace App\Http\Controllers;

use App\Enums\TaskState;
use App\Services\TaskAnalyticsService;
use App\Services\TaskReadService;
use App\Support\ReadLimits;
use App\Support\TaskDeadlineSql;

class TeamDashboardController extends Controller
{
    public function __construct(private readonly TaskReadService $taskReads, private readonly TaskAnalyticsService $analytics) {}

    public function index()
    {
        $user = auth()->user();
        abort_unless($user && $user->hasRole('team_member'), 403);
        $today = today(config('app.timezone'));
        $scope = $this->taskReads->activeVisibleTo($user);
        $summary = $this->analytics->summary($scope);
        $completed = $this->taskReads->completedVisibleTo($user);
        $todayCompletedTasks = (clone $completed)->whereDate('completed_at', $today)->count();
        $todayTotalTasks = $currentTotalTasks = $summary['totalActiveTasks'];
        $todayInProgressTasks = $currentInProgressTasks = $summary['inProgressTasks'];
        $todayOverdueTasks = $currentOverdueTasks = $summary['overdueTasks'];
        $todayAvgProgress = $summary['avgProgress'];
        $executionTaskCount = $summary['executionTaskCount'];
        $reviewQueueTaskCount = $summary['reviewQueueTaskCount'];
        $todayCompletionRate = $this->analytics->boundedCompletionRate($todayCompletedTasks, $todayTotalTasks);
        $todayPriorityCounts = collect(['High', 'Medium', 'Low'])->mapWithKeys(fn ($priority) => [$priority => $summary['priorityCounts'][$priority] ?? 0])->all();
        $todayStatusCounts = collect(TaskState::cases())->mapWithKeys(fn ($state) => [$state->label() => $summary['statusCounts'][$state->label()] ?? 0])
            ->put('Completed', $todayCompletedTasks)->all();
        $todayTasks = $currentTasks = (clone $scope)->with(['project', 'creator'])->latest('created_at')->orderByDesc('id')->limit(ReadLimits::MEMBER_NOTICES)->get();
        $todayOverdueList = (clone $scope)->whereRaw(TaskDeadlineSql::date().' < ?', [$today->toDateString()])
            ->with('project')->orderByRaw(TaskDeadlineSql::date())->orderBy('id')->limit(ReadLimits::DASHBOARD_TASKS)->get();
        $upcoming = (clone $scope)->whereRaw(TaskDeadlineSql::date().' BETWEEN ? AND ?', [$today->toDateString(), $today->copy()->addDays(7)->toDateString()])
            ->with('project')->orderByRaw(TaskDeadlineSql::date())->orderBy('id')->limit(ReadLimits::DASHBOARD_TASKS)->get();
        $completionDates = (clone $completed)->where('completed_at', '>=', $today->copy()->subDays(6))
            ->toBase()->selectRaw('DATE(completed_at) AS day, COUNT(*) AS aggregate')->groupByRaw('DATE(completed_at)')->pluck('aggregate', 'day');
        $productivity = collect(range(0, 6))->mapWithKeys(function ($i) use ($today, $completionDates): array {
            $date = $today->copy()->subDays(6 - $i);

            return [$date->format('D') => (int) ($completionDates[$date->toDateString()] ?? 0)];
        });
        $recentCompletedHistory = (clone $completed)->with('project')->where('completed_at', '>=', now()->subDays(7))
            ->latest('completed_at')->orderByDesc('id')->limit(ReadLimits::DASHBOARD_TASKS)->get();
        $notifications = $todayTasks->map(function ($task): array {
            if ($task->activeDeadlineGeneration()?->isOverdue()) {
                return ['type' => 'overdue', 'text' => "Task '{$task->title}' is overdue."];
            }

            return ['type' => 'assigned', 'text' => "Task '{$task->title}' was assigned to you."];
        });
        $achievements = ['Completed '.$todayCompletedTasks.' tasks today', 'Currently working on '.$currentInProgressTasks.' tasks', "Today's progress: ".$todayAvgProgress.'%'];
        $improvements = ['Focus on '.$todayOverdueTasks.' overdue task(s)', 'Maintain steady progress on current tasks', 'Prioritize high-priority tasks'];

        return view('team-dashboard', compact('user', 'todayTasks', 'currentTasks', 'todayTotalTasks', 'todayCompletedTasks',
            'todayInProgressTasks', 'todayOverdueTasks', 'todayAvgProgress', 'todayCompletionRate', 'currentTotalTasks',
            'currentInProgressTasks', 'currentOverdueTasks', 'executionTaskCount', 'reviewQueueTaskCount',
            'todayPriorityCounts', 'todayStatusCounts', 'todayOverdueList', 'productivity', 'notifications',
            'achievements', 'improvements', 'recentCompletedHistory', 'upcoming'));
    }
}
