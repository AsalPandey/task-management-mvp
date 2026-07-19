<?php

namespace App\Services;

use App\Exceptions\TaskNotificationDispatchException;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskCompletedNotification;
use App\Notifications\TaskOverdueNotification;
use App\Notifications\TaskRevertedNotification;
use App\Notifications\TaskUpdatedNotification;
use Closure;
use Throwable;

class TaskNotificationDispatcher
{
    public function taskCreated(Task $task, User $actor): void
    {
        $this->afterCommit($task, 'task.created', function (Task $committedTask) use ($actor): void {
            $committedTask->assignee?->notify(new TaskAssignedNotification($committedTask, $actor));
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function taskUpdated(Task $task, array $changes, User $actor): void
    {
        $this->afterCommit($task, 'task.updated', function (Task $committedTask) use ($changes, $actor): void {
            if ($committedTask->assignee && (int) $committedTask->assignee_id !== (int) $actor->id) {
                $committedTask->assignee->notify(new TaskUpdatedNotification($committedTask, $changes, $actor));
            }

            if ($committedTask->due_date && $committedTask->due_date->isPast() && ! $committedTask->overdue_notification_sent_at) {
                $committedTask->assignee?->notify(new TaskOverdueNotification($committedTask));

                Task::on($committedTask->getConnectionName())
                    ->whereKey($committedTask->getKey())
                    ->whereNull('overdue_notification_sent_at')
                    ->update(['overdue_notification_sent_at' => now()]);
            }
        });
    }

    public function taskCompleted(Task $task, User $actor): void
    {
        $this->afterCommit($task, 'task.completed', function (Task $committedTask) use ($actor): void {
            $committedTask->assignee?->notify(new TaskCompletedNotification($committedTask, $actor, true));
        });
    }

    public function taskReopened(Task $task, User $actor): void
    {
        $this->afterCommit($task, 'task.reopened', function (Task $committedTask) use ($actor): void {
            $committedTask->assignee?->notify(new TaskRevertedNotification($committedTask, $actor));
        });
    }

    /**
     * @param  Closure(Task): void  $dispatch
     */
    private function afterCommit(Task $task, string $operation, Closure $dispatch): void
    {
        $connection = $task->getConnection();
        $connectionName = $connection->getName();
        $taskId = (int) $task->getKey();

        $connection->afterCommit(function () use ($connectionName, $dispatch, $operation, $taskId): void {
            try {
                $committedTask = Task::on($connectionName)
                    ->with('assignee')
                    ->find($taskId);

                if (! $committedTask) {
                    return;
                }

                $dispatch($committedTask);
            } catch (Throwable $exception) {
                throw new TaskNotificationDispatchException($operation, $taskId, $exception);
            }
        });
    }
}
