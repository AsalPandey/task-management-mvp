<?php

namespace App\Services;

use App\Exceptions\DuplicateTaskOperationException;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Support\UlidGenerator;
use App\ValueObjects\TaskOperationContext;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;
use RuntimeException;

class TaskEventRecorder
{
    public const CREATED = 'task.created';

    public const UPDATED = 'task.updated';

    public const COMPLETED = 'task.completed';

    public const REOPENED = 'task.reopened';

    public const STARTED = 'task.started';

    public const HELD = 'task.held';

    public const RESUMED = 'task.resumed';

    public const SUBMITTED = 'task.submitted';

    public const REVIEW_STARTED = 'task.review_started';

    public const FEEDBACK_ADDED = 'task.feedback_added';

    public const REVISION_REQUESTED = 'task.revision_requested';

    public const REVISION_STARTED = 'task.revision_started';

    public const RESUBMITTED = 'task.resubmitted';

    public const APPROVED = 'task.approved';

    public const CANCELLED = 'task.cancelled';

    public const REVIEWER_REASSIGNED = 'task.reviewer_reassigned';

    public const DEADLINE_CHANGED = 'task.deadline_changed';

    private const MAX_INSERT_ATTEMPTS = 5;

    public function __construct(private readonly UlidGenerator $ulids) {}

    public function record(
        Task $task,
        string $eventType,
        TaskOperationContext $context,
        array $changedFields,
        ?array $metadata = null,
    ): TaskEvent {
        if (! $task->exists || ! $task->getKey()) {
            throw new InvalidArgumentException('A persisted task is required to record an event.');
        }

        if (! in_array($eventType, self::supportedTypes(), true)) {
            throw new InvalidArgumentException("Unsupported task event type [{$eventType}].");
        }

        $connection = $task->getConnection();

        return $connection->transaction(function () use ($connection, $task, $eventType, $context, $changedFields, $metadata) {
            $lockedTask = Task::on($connection->getName())
                ->withTrashed()
                ->whereKey($task->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! is_string($lockedTask->task_uid)
                || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $lockedTask->task_uid) !== 1) {
                throw new InvalidArgumentException('A valid task UID is required to record an event.');
            }

            return $this->insertWithRetry(
                $connection,
                $lockedTask,
                $eventType,
                $context,
                $changedFields,
                $metadata,
            );
        });
    }

    /**
     * @return array<int, string>
     */
    public static function supportedTypes(): array
    {
        return [
            self::CREATED,
            self::UPDATED,
            self::COMPLETED,
            self::REOPENED,
            self::STARTED,
            self::HELD,
            self::RESUMED,
            self::SUBMITTED,
            self::REVIEW_STARTED,
            self::FEEDBACK_ADDED,
            self::REVISION_REQUESTED,
            self::REVISION_STARTED,
            self::RESUBMITTED,
            self::APPROVED,
            self::CANCELLED,
            self::REVIEWER_REASSIGNED,
            self::DEADLINE_CHANGED,
        ];
    }

    private function insertWithRetry(
        Connection $connection,
        Task $task,
        string $eventType,
        TaskOperationContext $context,
        array $changedFields,
        ?array $metadata,
    ): TaskEvent {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_INSERT_ATTEMPTS; $attempt++) {
            $latestSequence = $connection->table('task_events')
                ->where('task_id', $task->id)
                ->orderByDesc('sequence')
                ->lockForUpdate()
                ->value('sequence');
            $sequence = ((int) $latestSequence) + 1;

            try {
                $event = (new TaskEvent)->setConnection($connection->getName());
                $event->forceFill([
                    'event_uid' => $this->ulids->generate(),
                    'task_id' => $task->id,
                    'sequence' => $sequence,
                    'event_type' => $eventType,
                    'actor_id' => $context->actorId,
                    'source' => $context->source,
                    'correlation_id' => $context->correlationId,
                    'operation_key' => $context->correlationId === null
                        ? null
                        : hash('sha256', implode('|', [$eventType, (string) $context->actorId, $context->correlationId])),
                    'changed_fields' => $changedFields,
                    'metadata' => $metadata,
                    'occurred_at' => $context->occurredAt,
                ])->save();

                return $event;
            } catch (UniqueConstraintViolationException $exception) {
                $message = strtolower($exception->getMessage());
                if (str_contains($message, 'unique constraint failed: task_events.operation_key')
                    || str_contains($message, 'task_events_operation_key_unique')) {
                    throw new DuplicateTaskOperationException(
                        'This task operation has already been recorded.',
                        previous: $exception,
                    );
                }
                $lastException = $exception;
            }
        }

        throw new RuntimeException(
            'Unable to allocate a unique task event identity after bounded retries.',
            previous: $lastException,
        );
    }
}
