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

final class ResumeTask implements TaskTransitionCommand
{
    public function __construct(
        private readonly TaskExecutionEligibilityService $eligibility,
        private readonly TaskNotificationDispatcher $notifications,
    ) {}

    public function ability(): string
    {
        return 'resume';
    }

    public function validate(Task $task, User $actor): void
    {
        if ($task->machineState() !== TaskState::OnHold) {
            throw TaskTransitionException::invalidState('Only an on-hold task may be resumed.');
        }

        $this->eligibility->assertEligible($task, $actor);
    }

    public function apply(Task $task, User $actor, TaskOperationContext $context): TaskTransitionEffects
    {
        $priorHeldAt = $task->held_at;
        $priorHeldBy = $task->held_by;
        $priorReason = $task->hold_reason;
        $deadline = $task->execution_due_date?->toDateString() ?? $task->due_date?->toDateString();
        $holdDuration = $priorHeldAt
            ? (int) max(0, $priorHeldAt->diffInSeconds($context->occurredAt, false))
            : null;

        $task->forceFill([
            'status' => TaskState::InProgress,
            'held_at' => null,
            'held_by' => null,
            'hold_reason' => null,
        ])->save();

        $changes = [
            'status' => ['before' => TaskState::OnHold->value, 'after' => TaskState::InProgress->value],
            'held_at' => ['before' => $priorHeldAt?->toAtomString(), 'after' => null],
            'held_by' => ['before' => $priorHeldBy === null ? null : (int) $priorHeldBy, 'after' => null],
            'hold_reason' => ['before' => $priorReason, 'after' => null],
            'hold_duration_seconds' => ['before' => null, 'after' => $holdDuration],
            'active_deadline' => ['before' => $deadline, 'after' => $deadline],
        ];

        return new TaskTransitionEffects(
            historyAction: 'resumed',
            historyChanges: $changes,
            events: [[
                'type' => TaskEventRecorder::RESUMED,
                'changed_fields' => $changes,
            ]],
            afterCommit: fn (Task $committedTask) => $this->notifications
                ->dispatchTaskResumed($committedTask, $actor),
        );
    }
}
