<?php

namespace App\Services;

use App\Contracts\TaskTransitionCommand;
use App\Exceptions\StaleTaskEditException;
use App\Models\Task;
use App\Models\TaskHistory;
use App\Models\User;
use App\ValueObjects\TaskOperationContext;
use App\ValueObjects\TaskTransitionEffects;
use App\ValueObjects\TaskTransitionResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class TaskTransitionExecutor
{
    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(private readonly TaskEventRecorder $eventRecorder) {}

    public function execute(
        Task $task,
        User $actor,
        TaskTransitionCommand $command,
        ?TaskOperationContext $context = null,
    ): TaskTransitionResult {
        $context ??= TaskOperationContext::system($actor->id);
        $taskId = (int) $task->getKey();

        return DB::transaction(function () use ($taskId, $actor, $command, $context) {
            $lockedTask = Task::withTrashed()
                ->whereKey($taskId)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedTask->load(['project', 'assignee', 'reviewer']);

            Gate::forUser($actor)->authorize($command->ability(), $lockedTask);
            $command->validate($lockedTask, $actor);

            if ($context?->expectedVersion !== null && (int) $context->expectedVersion !== (int) $lockedTask->lock_version) {
                throw new StaleTaskEditException($lockedTask, (int) $context->expectedVersion, (int) $lockedTask->lock_version);
            }

            $effects = $command->apply($lockedTask, $actor, $context);
            $lockedTask->forceFill(['lock_version' => ((int) $lockedTask->lock_version) + 1])->save();
            $lockedTask->refresh()->load(['project', 'assignee', 'reviewer']);

            $history = $this->recordHistory($lockedTask, $actor, $effects->historyAction, $effects->historyChanges);
            $events = [];

            foreach ($effects->events as $event) {
                $metadata = $event['metadata'] ?? null;
                if ($history
                    && data_get($metadata, 'reason_reference') === TaskTransitionEffects::HISTORY_REFERENCE) {
                    $metadata['reason_reference'] = "task_histories:{$history->id}";
                }

                $events[] = $this->eventRecorder->record(
                    $lockedTask,
                    $event['type'],
                    $context,
                    $event['changed_fields'],
                    $metadata,
                );
            }

            if ($effects->afterCommit) {
                $connection = $lockedTask->getConnection();
                $connectionName = $connection->getName();
                $afterCommit = $effects->afterCommit;

                $connection->afterCommit(function () use ($connectionName, $taskId, $afterCommit): void {
                    $committedTask = Task::on($connectionName)->find($taskId);

                    if ($committedTask) {
                        $afterCommit($committedTask);
                    }
                });
            }

            return new TaskTransitionResult(
                task: $lockedTask,
                history: $history,
                events: $events,
                metadata: $effects->metadata,
            );
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function recordHistory(
        Task $task,
        User $actor,
        ?string $action,
        array $changes,
    ): ?TaskHistory {
        if ($action === null) {
            return null;
        }

        return TaskHistory::query()->create([
            'task_id' => $task->id,
            'project_id' => $task->project_id,
            'original_task_id' => $task->original_task_id,
            'task_title' => $task->title,
            'user_id' => $actor->id,
            'action' => $action,
            'changes' => $changes,
        ]);
    }
}
