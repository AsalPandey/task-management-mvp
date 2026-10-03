<?php

namespace App\Services;

use App\Models\Task;
use App\Support\ReadLimits;
use App\Support\TaskDeadlineSql;
use App\ValueObjects\TaskDeadlineGeneration;
use InvalidArgumentException;

final class TaskDeadlineCandidates
{
    /** Bounded scalar snapshots. Unconsumed candidates still use the atomic delivery service. */
    public function each(string $type, callable $deliver): void
    {
        $marker = match ($type) {
            TaskDeadlineNotificationDelivery::DEADLINE_REMINDER => 'deadline_reminder_generation',
            TaskDeadlineNotificationDelivery::OVERDUE => 'overdue_notification_generation',
            default => throw new InvalidArgumentException('Unsupported deadline notification type.'),
        };
        $today = today(config('app.timezone'));
        $isReminder = $type === TaskDeadlineNotificationDelivery::DEADLINE_REMINDER;
        $query = Task::query()->whereRaw(TaskDeadlineSql::date().($isReminder ? ' = ?' : ' < ?'), [
            ($isReminder ? $today->addDay() : $today)->toDateString(),
        ])->select(['tasks.id', 'tasks.assignee_id', 'tasks.reviewer_id', 'tasks.active_revision_cycle_id', 'tasks.'.$marker])
            ->selectRaw(TaskDeadlineSql::kind().' AS deadline_kind, '.TaskDeadlineSql::date().' AS deadline_date')->toBase();
        $query->chunkById(ReadLimits::DEADLINE_CHUNK, function ($rows) use ($marker, $deliver): void {
            foreach ($rows as $row) {
                $ownerId = $row->deadline_kind === 'review' ? $row->reviewer_id : $row->assignee_id;
                $cycleId = $row->deadline_kind === 'execution' ? null : $row->active_revision_cycle_id;
                $fingerprint = TaskDeadlineGeneration::fingerprintFor($row->deadline_kind, $row->deadline_date,
                    $ownerId === null ? null : (int) $ownerId, $cycleId === null ? null : (int) $cycleId);
                if (hash_equals((string) $row->{$marker}, $fingerprint)) {
                    continue;
                }
                // A change after this snapshot is rechecked under lock; a new generation after a skip is picked up next run.
                $deliver((int) $row->id);
            }
        }, 'tasks.id', 'id');
    }
}
