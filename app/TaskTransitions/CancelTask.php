<?php

namespace App\TaskTransitions;

use App\Contracts\TaskTransitionCommand;
use App\Enums\TaskState;
use App\Exceptions\TaskTransitionException;
use App\Models\Task;
use App\Models\TaskRevisionCycle;
use App\Models\User;
use App\Services\TaskEventRecorder;
use App\Services\TaskNotificationDispatcher;
use App\ValueObjects\TaskOperationContext;
use App\ValueObjects\TaskTransitionEffects;

final class CancelTask implements TaskTransitionCommand
{
    private const ALLOWED_STATES = [
        TaskState::NotStarted,
        TaskState::InProgress,
        TaskState::OnHold,
        TaskState::Submitted,
        TaskState::InReview,
        TaskState::RevisionRequested,
    ];

    public function __construct(
        private readonly TaskNotificationDispatcher $notifications,
        private readonly string $cancellationReason,
        private readonly ?string $expectedState = null,
    ) {}

    public function ability(): string
    {
        return 'cancel';
    }

    public function validate(Task $task, User $actor): void
    {
        if ($this->expectedState !== null && $task->machineState()->value !== $this->expectedState) {
            throw TaskTransitionException::invalidState('The task changed before cancellation could be applied.');
        }

        if (! in_array($task->machineState(), self::ALLOWED_STATES, true)) {
            throw TaskTransitionException::invalidState('Completed or already cancelled tasks cannot be cancelled.');
        }

        if (! $actor->isActive() || ! $this->controlsManagement($task, $actor)) {
            throw TaskTransitionException::invariant('actor_id', 'Only active authorized management may cancel a task.');
        }

        if (trim($this->cancellationReason) === '') {
            throw TaskTransitionException::missingData('cancellation_reason', 'A cancellation reason is required.');
        }
    }

    public function apply(Task $task, User $actor, TaskOperationContext $context): TaskTransitionEffects
    {
        $stateBefore = $task->machineState();
        $cycle = $this->activeCycle($task);
        $cycle?->forceFill(['resolved_at' => $context->occurredAt])->save();

        $changes = [
            'status' => ['before' => $stateBefore->value, 'after' => TaskState::Cancelled->value],
            'cancelled_at' => ['before' => $task->cancelled_at?->toAtomString(), 'after' => $context->occurredAt->toAtomString()],
            'cancelled_by' => ['before' => $task->cancelled_by, 'after' => (int) $actor->id],
            'cancellation_reason' => ['before' => null, 'after' => "tasks:{$task->id}:cancellation_reason"],
            'held_at' => ['before' => $task->held_at?->toAtomString(), 'after' => null],
            'held_by' => ['before' => $task->held_by, 'after' => null],
            'hold_reason' => ['before' => $task->hold_reason ? "tasks:{$task->id}:hold_reason" : null, 'after' => null],
            'review_due_date' => ['before' => $task->review_due_date?->toDateString(), 'after' => null],
            'revision_due_date' => ['before' => $task->revision_due_date?->toDateString(), 'after' => null],
            'active_revision_cycle_id' => ['before' => $task->active_revision_cycle_id, 'after' => null],
        ];

        $task->forceFill([
            'status' => TaskState::Cancelled,
            'cancelled_at' => $context->occurredAt,
            'cancelled_by' => $actor->id,
            'cancellation_reason' => trim($this->cancellationReason),
            'held_at' => null,
            'held_by' => null,
            'hold_reason' => null,
            'review_due_date' => null,
            'revision_due_date' => null,
            'active_revision_cycle_id' => null,
            'deadline_reminder_sent_at' => null,
            'overdue_notification_sent_at' => null,
        ])->save();

        return new TaskTransitionEffects(
            historyAction: 'cancelled',
            historyChanges: [
                'cancellation_reason_reference' => "tasks:{$task->id}:cancellation_reason",
                'resolved_revision_cycle_id' => $cycle?->id,
                'changes' => $changes,
            ],
            events: [[
                'type' => TaskEventRecorder::CANCELLED,
                'changed_fields' => $changes,
                'metadata' => [
                    'cancelled_by' => (int) $actor->id,
                    'cancelled_at' => $context->occurredAt->toAtomString(),
                    'cancellation_reason_reference' => "tasks:{$task->id}:cancellation_reason",
                    'resolved_revision_cycle_id' => $cycle?->id,
                ],
            ]],
            afterCommit: fn (Task $committedTask) => $this->notifications
                ->dispatchTaskCancelled($committedTask, $actor),
        );
    }

    private function activeCycle(Task $task): ?TaskRevisionCycle
    {
        if (! $task->active_revision_cycle_id) {
            return null;
        }

        return TaskRevisionCycle::query()
            ->whereKey($task->active_revision_cycle_id)
            ->where('task_id', $task->id)
            ->whereNull('resolved_at')
            ->first();
    }

    private function controlsManagement(Task $task, User $actor): bool
    {
        return $actor->hasRole('manager')
            || ($actor->hasRole('project_manager')
                && $task->project
                && (int) $task->project->project_manager_id === (int) $actor->id);
    }
}
