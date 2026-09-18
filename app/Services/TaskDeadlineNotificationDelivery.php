<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskNotificationDelivery;
use App\Notifications\TaskDeadlineReminderNotification;
use App\Notifications\TaskOverdueNotification;
use App\ValueObjects\TaskNotificationDeliveryResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class TaskDeadlineNotificationDelivery
{
    public const DEADLINE_REMINDER = 'deadline_reminder';

    public const OVERDUE = 'overdue';

    public function __construct(private readonly TaskDeadlineOwnerResolver $owners) {}

    public function deliver(int $taskId, string $type): TaskNotificationDeliveryResult
    {
        if (! in_array($type, [self::DEADLINE_REMINDER, self::OVERDUE], true)) {
            throw new InvalidArgumentException("Unsupported task notification delivery type [{$type}].");
        }

        return DB::transaction(function () use ($taskId, $type): TaskNotificationDeliveryResult {
            $task = Task::query()->lockForUpdate()->find($taskId);
            if (! $task) {
                return new TaskNotificationDeliveryResult('suppressed', 'task_unavailable');
            }

            $ownership = $this->owners->resolve($task);
            if (! $ownership->isDeliverable()) {
                return new TaskNotificationDeliveryResult('suppressed', $ownership->suppressionReason);
            }

            $generation = $ownership->generation;
            $eligible = $type === self::DEADLINE_REMINDER
                ? $generation->deadline->toDateString() === today(config('app.timezone'))->addDay()->toDateString()
                : $generation->isOverdue();
            if (! $eligible) {
                return new TaskNotificationDeliveryResult('stale', 'generation_not_eligible');
            }

            $alreadyMarked = $type === self::DEADLINE_REMINDER
                ? $task->deadlineReminderWasSentForActiveGeneration()
                : $task->overdueNotificationWasSentForActiveGeneration();
            if ($alreadyMarked) {
                return new TaskNotificationDeliveryResult('already_delivered', 'task_marker_consumed');
            }

            $delivery = TaskNotificationDelivery::query()->firstOrCreate(
                [
                    'task_id' => $task->id,
                    'notification_type' => $type,
                    'deadline_generation' => $generation->fingerprint(),
                    'recipient_id' => $ownership->owner->id,
                ],
                [
                    'notification_id' => (string) Str::uuid(),
                    'status' => 'claimed',
                    'attempt_count' => 1,
                    'claimed_at' => now(),
                ],
            );

            if (! $delivery->wasRecentlyCreated) {
                return new TaskNotificationDeliveryResult(
                    $delivery->status === 'delivered' ? 'already_delivered' : 'claimed',
                    'logical_delivery_exists',
                    $delivery->id,
                );
            }

            $notification = $type === self::DEADLINE_REMINDER
                ? new TaskDeadlineReminderNotification($task)
                : new TaskOverdueNotification($task);
            $notification->id = $delivery->notification_id;
            $ownership->owner->notify($notification);

            if ($type === self::DEADLINE_REMINDER) {
                $task->markDeadlineReminderSentForActiveGeneration();
            } else {
                $task->markOverdueNotificationSentForActiveGeneration();
            }

            $delivery->forceFill([
                'status' => 'delivered',
                'delivered_at' => now(),
                'last_failure_code' => null,
            ])->save();

            return new TaskNotificationDeliveryResult('delivered', deliveryId: $delivery->id);
        }, 3);
    }
}
