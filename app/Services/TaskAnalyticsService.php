<?php

namespace App\Services;

use App\Enums\TaskState;
use App\Models\TaskEvent;
use App\Models\User;
use App\Support\TaskDeadlineSql;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class TaskAnalyticsService
{
    public function __construct(private readonly TaskReadService $taskReads) {}

    /** Aggregate the caller's canonical scope without retrieving Task models. */
    public function summary(Builder $scope): array
    {
        $deadline = TaskDeadlineSql::date();
        $today = today(config('app.timezone'));
        $states = (clone $scope)->toBase()
            ->selectRaw("tasks.status, COUNT(*) AS total, SUM(tasks.progress) AS progress_sum,
                SUM(CASE WHEN {$deadline} < ? THEN 1 ELSE 0 END) AS overdue,
                SUM(CASE WHEN {$deadline} = ? THEN 1 ELSE 0 END) AS due_soon",
                [$today->toDateString(), $today->copy()->addDay()->toDateString()])
            ->groupBy('tasks.status')->get()->keyBy('status');
        $count = fn (TaskState $state): int => (int) ($states[$state->value]->total ?? 0);
        $completed = $count(TaskState::Completed);
        $cancelled = $count(TaskState::Cancelled);
        $total = (int) $states->sum('total');
        $active = $total - $completed - $cancelled;
        $activeRows = $states->except([TaskState::Completed->value, TaskState::Cancelled->value]);

        return [
            'totalTasks' => $total,
            'totalActiveTasks' => $active,
            'totalCompletedTasks' => $completed,
            'cancelledTasks' => $cancelled,
            'tasksCreated' => $total,
            'completionRate' => $this->boundedCompletionRate($completed, $active),
            'executionTaskCount' => $count(TaskState::NotStarted) + $count(TaskState::InProgress)
                + $count(TaskState::OnHold) + $count(TaskState::RevisionRequested),
            'reviewQueueTaskCount' => $count(TaskState::Submitted) + $count(TaskState::InReview),
            'inProgressTasks' => $count(TaskState::InProgress),
            'overdueTasks' => (int) $activeRows->sum('overdue'),
            'dueSoonTasks' => (int) $activeRows->sum('due_soon'),
            'avgProgress' => $active ? round((float) $activeRows->sum('progress_sum') / $active) : 0,
            'statusCounts' => collect(TaskState::cases())->filter(fn ($state) => $states->has($state->value))
                ->mapWithKeys(fn ($state) => [$state->label() => $count($state)]),
            'priorityCounts' => (clone $scope)->whereNotIn('tasks.status', ['completed', 'cancelled'])
                ->toBase()->selectRaw('tasks.priority, COUNT(*) AS aggregate')->groupBy('tasks.priority')
                ->pluck('aggregate', 'priority')->map(fn ($count) => (int) $count),
        ];
    }

    /** Current creation-cohort state and historical event throughput remain separate. */
    public function report(User $viewer, ?string $dateFrom = null, ?string $dateTo = null, ?int $assigneeId = null, ?int $projectId = null): array
    {
        [$from, $to] = $this->range($dateFrom, $dateTo);
        $scope = $this->taskReads->visibleTo($viewer)
            ->when($assigneeId, fn (Builder $query) => $query->where('tasks.assignee_id', $assigneeId))
            ->when($projectId, fn (Builder $query) => $query->where('tasks.project_id', $projectId));
        $cohort = (clone $scope)->when($from && $to, fn (Builder $query) => $query->whereBetween('tasks.created_at', [$from, $to]));
        $summary = $this->summary($cohort);
        $events = TaskEvent::query()->whereIn('task_id', (clone $scope)->select('tasks.id'))
            ->when($from && $to, fn (Builder $query) => $query->whereBetween('occurred_at', [$from, $to]));
        $eventCounts = (clone $events)->whereIn('event_type', [TaskEventRecorder::COMPLETED, TaskEventRecorder::CANCELLED, TaskEventRecorder::REOPENED])
            ->toBase()->selectRaw('event_type, COUNT(*) AS aggregate')->groupBy('event_type')->pluck('aggregate', 'event_type');
        $trendStart = CarbonImmutable::now(config('app.timezone'))->subDays(29)->startOfDay();
        $completionDates = (clone $events)->where('event_type', TaskEventRecorder::COMPLETED)->where('occurred_at', '>=', $trendStart)
            ->toBase()->selectRaw('DATE(occurred_at) AS day, COUNT(*) AS aggregate')->groupByRaw('DATE(occurred_at)')->pluck('aggregate', 'day');
        // Creation trend is the whole visible creation scope; event trend also honors the event date filter.
        $creationDates = (clone $scope)->where('tasks.created_at', '>=', $trendStart)
            ->toBase()->selectRaw('DATE(tasks.created_at) AS day, COUNT(*) AS aggregate')->groupByRaw('DATE(tasks.created_at)')->pluck('aggregate', 'day');
        $days = collect(range(0, 29))->map(fn (int $offset) => $trendStart->addDays($offset));
        $deadline = TaskDeadlineSql::date();
        // Exactly 30 scalar buckets, even if there are millions of distinct deadline dates.
        $overdueBuckets = (clone $cohort)->toBase()->selectRaw($days->map(fn ($day, $i) => "SUM(CASE WHEN {$deadline} < ? THEN 1 ELSE 0 END) AS d{$i}")->implode(', '), $days->map->toDateString()->all())->first();
        $teamPerformance = (clone $cohort)->leftJoin('users as owners', function ($join): void {
            $join->on('owners.id', '=', 'tasks.assignee_id');
        })->toBase()->selectRaw("tasks.assignee_id, owners.id AS owner_id, owners.name, owners.active, owners.deleted_at,
            COUNT(*) AS total, SUM(CASE WHEN tasks.status = 'completed' THEN 1 ELSE 0 END) AS completed,
            SUM(CASE WHEN tasks.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
            SUM(CASE WHEN {$deadline} < ? THEN 1 ELSE 0 END) AS overdue", [today(config('app.timezone'))->toDateString()])
            ->groupBy('tasks.assignee_id', 'owners.id', 'owners.name', 'owners.active', 'owners.deleted_at')->get()->map(fn ($row): array => [
                'id' => $row->owner_id,
                'name' => $row->name ?? 'Unassigned',
                'accountStatus' => $row->deleted_at ? 'Removed' : ($row->active ? 'Active' : 'Inactive'),
                'avatar' => strtoupper(substr($row->name ?? 'UN', 0, 2)),
                'total' => (int) $row->total,
                'completed' => (int) $row->completed,
                'overdue' => (int) $row->overdue,
                'completionRate' => $this->boundedCompletionRate((int) $row->completed, (int) $row->total - (int) $row->cancelled - (int) $row->completed),
            ]);

        return array_merge($summary, [
            'completionEvents' => (int) ($eventCounts[TaskEventRecorder::COMPLETED] ?? 0),
            'cancellationEvents' => (int) ($eventCounts[TaskEventRecorder::CANCELLED] ?? 0),
            'reopenEvents' => (int) ($eventCounts[TaskEventRecorder::REOPENED] ?? 0),
            'teamPerformance' => $teamPerformance,
            'completionTrend' => $days->mapWithKeys(fn ($day) => [$day->format('M d') => (int) ($completionDates[$day->toDateString()] ?? 0)]),
            'creationTrend' => $days->mapWithKeys(fn ($day) => [$day->format('M d') => (int) ($creationDates[$day->toDateString()] ?? 0)]),
            'overdueTrend' => $days->mapWithKeys(fn ($day, $i) => [$day->format('M d') => (int) ($overdueBuckets->{'d'.$i} ?? 0)]),
            'dateFrom' => $from,
            'dateTo' => $to,
        ]);
    }

    public function boundedCompletionRate(int $completed, int $active): float
    {
        $eligible = $completed + $active;

        return $eligible > 0 ? round($completed / $eligible * 100, 1) : 0.0;
    }

    private function range(?string $dateFrom, ?string $dateTo): array
    {
        if (! $dateFrom || ! $dateTo) {
            return [null, null];
        }
        $timezone = config('app.timezone');

        return [CarbonImmutable::parse($dateFrom, $timezone)->startOfDay(), CarbonImmutable::parse($dateTo, $timezone)->endOfDay()];
    }
}
