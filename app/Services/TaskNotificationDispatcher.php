<?php

namespace App\Services;

use App\Exceptions\TaskNotificationDispatchException;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskOverdueNotification;
use App\Notifications\TaskRevertedNotification;
use App\Notifications\TaskReviewWorkflowNotification;
use App\Notifications\TaskUpdatedNotification;
use App\Notifications\TaskWorkflowTransitionNotification;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
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

    public function taskReopened(Task $task, User $actor): void
    {
        $this->afterCommit($task, 'task.reopened', function (Task $committedTask) use ($actor): void {
            $committedTask->assignee?->notify(new TaskRevertedNotification($committedTask, $actor));
        });
    }

    public function dispatchTaskStarted(Task $task, User $actor): void
    {
        $this->dispatchTransition(
            $task,
            $actor,
            'started',
            collect([$task->creator, $task->reviewer])
                ->reject(fn (?User $user) => $user && (int) $user->id === (int) $task->assignee_id),
            'Monitor execution progress.',
        );
    }

    public function dispatchTaskHeld(Task $task, User $actor): void
    {
        $this->dispatchTransition(
            $task,
            $actor,
            'held',
            collect([
                $task->assignee,
                $task->creator,
                $task->reviewer,
                $task->project?->projectManager,
            ]),
            'Review the hold and resume work when ready.',
        );
    }

    public function dispatchTaskResumed(Task $task, User $actor): void
    {
        $this->dispatchTransition(
            $task,
            $actor,
            'resumed',
            collect([$task->assignee, $task->creator, $task->reviewer]),
            'Continue execution and update progress.',
        );
    }

    public function dispatchTaskSubmitted(Task $task, User $actor): void
    {
        $this->dispatchReviewTransition(
            $task,
            $actor,
            'submitted',
            collect([$task->reviewer, $task->creator])
                ->reject(fn (?User $user) => $user && (int) $user->id === (int) $actor->id),
            'Review task',
        );
    }

    public function dispatchTaskReviewStarted(Task $task, User $actor): void
    {
        $this->dispatchReviewTransition(
            $task,
            $actor,
            'review_started',
            collect([$task->assignee]),
            'Await review outcome',
        );
    }

    public function dispatchTaskRevisionRequested(Task $task, User $actor): void
    {
        $this->dispatchReviewTransition(
            $task,
            $actor,
            'revision_requested',
            collect([$task->assignee, $task->creator, $task->project?->projectManager]),
            'Begin revision',
        );
    }

    public function dispatchTaskRevisionStarted(Task $task, User $actor): void
    {
        $this->dispatchReviewTransition(
            $task,
            $actor,
            'revision_started',
            collect([$task->reviewer, $task->creator]),
            'Await revised submission',
        );
    }

    public function dispatchTaskResubmitted(Task $task, User $actor): void
    {
        $this->dispatchReviewTransition(
            $task,
            $actor,
            'resubmitted',
            collect([$task->reviewer, $task->creator]),
            'Review revised submission',
        );
    }

    public function dispatchTaskApprovedAndCompleted(Task $task, User $actor): void
    {
        $this->dispatchReviewTransition(
            $task,
            $actor,
            'approved_completed',
            collect([
                $task->assignee,
                $task->creator,
                $task->reviewer,
                $task->project?->projectManager,
            ]),
            'None',
        );
    }

    private function dispatchReviewTransition(
        Task $task,
        User $actor,
        string $transition,
        Collection $recipients,
        string $requiredAction,
    ): void {
        try {
            $task->loadMissing(['assignee', 'creator', 'reviewer', 'activeRevisionCycle', 'approval', 'project.projectManager']);
            $recipients = $recipients->filter()->unique(fn (User $user) => (int) $user->id)->values();

            if ($recipients->isNotEmpty()) {
                Notification::send(
                    $recipients,
                    new TaskReviewWorkflowNotification($task, $actor, $transition, $requiredAction),
                );
            }
        } catch (Throwable $exception) {
            throw new TaskNotificationDispatchException("task.{$transition}", (int) $task->id, $exception);
        }
    }

    /**
     * @param  Collection<int, User|null>  $recipients
     */
    private function dispatchTransition(
        Task $task,
        User $actor,
        string $transition,
        Collection $recipients,
        string $nextAction,
    ): void {
        try {
            $task->loadMissing(['assignee', 'creator', 'reviewer', 'project.projectManager']);
            $uniqueRecipients = $recipients
                ->filter()
                ->unique(fn (User $user) => (int) $user->id)
                ->values();

            if ($uniqueRecipients->isEmpty()) {
                return;
            }

            Notification::send(
                $uniqueRecipients,
                new TaskWorkflowTransitionNotification($task, $actor, $transition, $nextAction),
            );
        } catch (Throwable $exception) {
            throw new TaskNotificationDispatchException(
                "task.{$transition}",
                (int) $task->id,
                $exception,
            );
        }
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
                    ->with(['assignee', 'creator', 'reviewer', 'activeRevisionCycle', 'approval', 'project.projectManager'])
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
