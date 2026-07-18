<?php

namespace App\Services;

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

        if (! in_array($eventType, [self::CREATED, self::UPDATED], true)) {
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
                    'changed_fields' => $changedFields,
                    'metadata' => $metadata,
                    'occurred_at' => $context->occurredAt,
                ])->save();

                return $event;
            } catch (UniqueConstraintViolationException $exception) {
                $lastException = $exception;
            }
        }

        throw new RuntimeException(
            'Unable to allocate a unique task event identity after bounded retries.',
            previous: $lastException,
        );
    }
}
