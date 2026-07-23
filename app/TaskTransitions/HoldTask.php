<?php

namespace App\TaskTransitions;

use App\Contracts\TaskTransitionCommand;
use App\Enums\TaskState;
use App\Exceptions\TaskTransitionException;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskEventRecorder;
use App\Services\TaskExecutionEligibilityService;
use App\Services\TaskNotificationDispatcher;
use App\ValueObjects\TaskOperationContext;
use App\ValueObjects\TaskTransitionEffects;

final class HoldTask implements TaskTransitionCommand
{
    public function __construct(
        private readonly TaskExecutionEligibilityService $eligibility,
        private readonly TaskNotificationDispatcher $notifications,
        private readonly string $reason,
    ) {}

    public function ability(): string
    {
        return 'hold';
    }

    public function validate(Task $task, User $actor): void
    {
        if ($task->machineState() !== TaskState::InProgress) {
            throw TaskTransitionException::invalidState('Only an in-progress task may be put on hold.');
        }

        if (trim($this->reason) === '') {
            throw TaskTransitionException::missingData('reason', 'A hold reason is required.');
        }

        if (mb_strlen($this->reason) > 1000) {
            throw TaskTransitionException::invariant('reason', 'The hold reason may not exceed 1000 characters.');
        }

        $this->eligibility->assertEligible($task, $actor);
    }

    public function apply(Task $task, User $actor, TaskOperationContext $context): TaskTransitionEffects
    {
        $reason = trim($this->reason);
        $deadline = $task->execution_due_date?->toDateString() ?? $task->due_date?->toDateString();
        $beforeHeldAt = $task->held_at?->toAtomString();
        $beforeHeldBy = $task->held_by === null ? null : (int) $task->held_by;
        $beforeReason = $task->hold_reason;

        $task->forceFill([
            'status' => TaskState::OnHold,
            'held_at' => $context->occurredAt,
            'held_by' => $actor->id,
            'hold_reason' => $reason,
        ])->save();

        $changes = [
            'status' => ['before' => TaskState::InProgress->value, 'after' => TaskState::OnHold->value],
            'held_at' => ['before' => $beforeHeldAt, 'after' => $task->held_at?->toAtomString()],
            'held_by' => ['before' => $beforeHeldBy, 'after' => (int) $actor->id],
            'hold_reason' => ['before' => $beforeReason, 'after' => $reason],
            'active_deadline' => ['before' => $deadline, 'after' => $deadline],
        ];

        return new TaskTransitionEffects(
            historyAction: 'held',
            historyChanges: $changes,
            events: [[
                'type' => TaskEventRecorder::HELD,
                'changed_fields' => $changes,
            ]],
            afterCommit: fn (Task $committedTask) => $this->notifications
                ->dispatchTaskHeld($committedTask, $actor),
        );
    }
}
