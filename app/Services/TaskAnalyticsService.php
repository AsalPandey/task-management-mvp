<?php

namespace App\Services;

use App\Enums\TaskState;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class TaskAnalyticsService
{
    public function __construct(private readonly TaskReadService $taskReads) {}

    /**
     * A bounded completion rate is the current completed share of the creation cohort,
     * excluding cancellations. Historical throughput is reported separately from events.
     *
     * @return array<string, mixed>
     */
    public function report(
        User $viewer,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $assigneeId = null,
        ?int $projectId = null,
    ): array {
        [$from, $to] = $this->range($dateFrom, $dateTo);
        $scope = $this->scopedTasks($viewer, $assigneeId, $projectId);
        $cohortQuery = clone $scope;
        if ($from && $to) {
            $cohortQuery->whereBetween('created_at', [$from, $to]);
        }

        $cohort = $cohortQuery->with(['assignee', 'project'])->get();
        $active = $cohort->reject(fn (Task $task) => $task->machineState()->isFinal())->values();
        $completed = $cohort->filter(fn (Task $task) => $task->machineState() === TaskState::Completed)->values();
        $cancelled = $cohort->filter(fn (Task $task) => $task->machineState() === TaskState::Cancelled)->values();
        $eligibleCohort = $cohort->count() - $cancelled->count();
        $completionRate = $eligibleCohort > 0
            ? round($completed->count() / $eligibleCohort * 100, 1)
            : 0.0;

        $events = TaskEvent::query()
            ->whereIn('task_id', (clone $scope)->select('tasks.id'))
            ->when($from && $to, fn (Builder $query) => $query->whereBetween('occurred_at', [$from, $to]));
        $eventCounts = (clone $events)
            ->whereIn('event_type', [
                TaskEventRecorder::COMPLETED,
                TaskEventRecorder::CANCELLED,
                TaskEventRecorder::REOPENED,
            ])
            ->selectRaw('event_type, COUNT(*) AS aggregate')
            ->groupBy('event_type')
            ->pluck('aggregate', 'event_type');
        $trendStart = now(config('app.timezone'))->subDays(29)->startOfDay();
        $completionDates = (clone $events)
            ->where('event_type', TaskEventRecorder::COMPLETED)
            ->where('occurred_at', '>=', $trendStart)
            ->get(['occurred_at'])
            ->groupBy(fn (TaskEvent $event) => $event->occurred_at->timezone(config('app.timezone'))->toDateString())
            ->map->count();
        $creationDates = (clone $scope)
            ->where('created_at', '>=', $trendStart)
            ->get(['created_at'])
            ->groupBy(fn (Task $task) => $task->created_at->timezone(config('app.timezone'))->toDateString())
            ->map->count();
        $trendDays = collect(range(0, 29))->map(fn (int $offset) => $trendStart->addDays($offset));

        $execution = $active->filter(fn (Task $task) => $task->machineState()->isExecutionState())->values();
        $review = $active->filter(fn (Task $task) => $task->machineState()->isReviewState())->values();
        $overdue = $active->filter(fn (Task $task) => $task->activeDeadlineGeneration()?->isOverdue() === true);
        $teamPerformance = $cohort->groupBy('assignee_id')->map(function (Collection $tasks): array {
            $cancelledCount = $tasks->filter(fn (Task $task) => $task->machineState() === TaskState::Cancelled)->count();
            $completedCount = $tasks->filter(fn (Task $task) => $task->machineState() === TaskState::Completed)->count();
            $eligible = $tasks->count() - $cancelledCount;
            $owner = $tasks->first()?->assignee;

            return [
                'id' => $owner?->id,
                'name' => $owner?->name ?? 'Unassigned',
                'avatar' => strtoupper(substr($owner?->name ?? 'UN', 0, 2)),
                'total' => $tasks->count(),
                'completed' => $completedCount,
                'overdue' => $tasks->filter(fn (Task $task) => ! $task->machineState()->isFinal()
                    && $task->activeDeadlineGeneration()?->isOverdue() === true)->count(),
                'completionRate' => $eligible > 0 ? round($completedCount / $eligible * 100, 1) : 0.0,
            ];
        })->values();

        return [
            'cohortTasks' => $cohort,
            'activeTasks' => $active,
            'completedTasks' => $completed,
            'cancelledTaskRows' => $cancelled,
            'executionTasks' => $execution,
            'reviewQueueTasks' => $review,
            'totalTasks' => $cohort->count(),
            'totalActiveTasks' => $active->count(),
            'totalCompletedTasks' => $completed->count(),
            'cancelledTasks' => $cancelled->count(),
            'tasksCreated' => $cohort->count(),
            'completionEvents' => (int) ($eventCounts[TaskEventRecorder::COMPLETED] ?? 0),
            'cancellationEvents' => (int) ($eventCounts[TaskEventRecorder::CANCELLED] ?? 0),
            'reopenEvents' => (int) ($eventCounts[TaskEventRecorder::REOPENED] ?? 0),
            'completionRate' => $completionRate,
            'inProgressTasks' => $active->where('status', TaskState::InProgress->value)->count(),
            'overdueTasks' => $overdue->count(),
            'dueSoonTasks' => $active->filter(fn (Task $task) => $task->activeDeadline()?->toDateString()
                === today(config('app.timezone'))->addDay()->toDateString())->count(),
            'avgProgress' => $active->isNotEmpty() ? round((float) $active->avg('progress')) : 0,
            'priorityCounts' => $active->groupBy('priority')->map->count(),
            'statusCounts' => $cohort->groupBy(fn (Task $task) => $task->machineState()->label())->map->count(),
            'teamPerformance' => $teamPerformance,
            'completionTrend' => $trendDays->mapWithKeys(fn ($day) => [
                $day->format('M d') => (int) ($completionDates[$day->toDateString()] ?? 0),
            ]),
            'creationTrend' => $trendDays->mapWithKeys(fn ($day) => [
                $day->format('M d') => (int) ($creationDates[$day->toDateString()] ?? 0),
            ]),
            'dateFrom' => $from,
            'dateTo' => $to,
        ];
    }

    public function boundedCompletionRate(int $completed, int $active): float
    {
        $eligible = $completed + $active;

        return $eligible > 0 ? round($completed / $eligible * 100, 1) : 0.0;
    }

    private function scopedTasks(User $viewer, ?int $assigneeId, ?int $projectId): Builder
    {
        return $this->taskReads->visibleTo($viewer)
            ->when($assigneeId, fn (Builder $query) => $query->where('assignee_id', $assigneeId))
            ->when($projectId, fn (Builder $query) => $query->where('project_id', $projectId));
    }

    /** @return array{CarbonImmutable|null, CarbonImmutable|null} */
    private function range(?string $dateFrom, ?string $dateTo): array
    {
        if (! $dateFrom || ! $dateTo) {
            return [null, null];
        }

        $timezone = config('app.timezone');

        return [
            CarbonImmutable::parse($dateFrom, $timezone)->startOfDay(),
            CarbonImmutable::parse($dateTo, $timezone)->endOfDay(),
        ];
    }
}
