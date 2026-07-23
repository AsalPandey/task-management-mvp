<?php

namespace App\TaskTransitions;

use App\Contracts\TaskTransitionCommand;
use App\Enums\TaskState;
use App\Exceptions\TaskTransitionException;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskEventRecorder;
use App\Services\TaskNotificationDispatcher;
use App\Services\TaskReviewEligibilityService;
use App\ValueObjects\TaskOperationContext;
use App\ValueObjects\TaskTransitionEffects;

final class StartTaskReview implements TaskTransitionCommand
{
    public function __construct(
        private readonly TaskReviewEligibilityService $eligibility,
        private readonly TaskNotificationDispatcher $notifications,
    ) {}

    public function ability(): string
    {
        return 'startReview';
    }

    public function validate(Task $task, User $actor): void
    {
        if ($task->machineState() !== TaskState::Submitted) {
            throw TaskTransitionException::invalidState('Only a submitted task may enter review.');
        }

        $this->eligibility->assertReviewerMayStart($task, $actor);
    }

    public function apply(Task $task, User $actor, TaskOperationContext $context): TaskTransitionEffects
    {
        $changes = [
            'status' => ['before' => TaskState::Submitted->value, 'after' => TaskState::InReview->value],
            'review_started_at' => ['before' => null, 'after' => $context->occurredAt->toAtomString()],
        ];

        $task->forceFill([
            'status' => TaskState::InReview,
            'review_started_at' => $context->occurredAt,
        ])->save();

        return new TaskTransitionEffects(
            historyAction: 'review_started',
            historyChanges: $changes,
            events: [[
                'type' => TaskEventRecorder::REVIEW_STARTED,
                'changed_fields' => $changes,
                'metadata' => [
                    'reviewer_id' => (int) $task->reviewer_id,
                    'review_started_at' => $context->occurredAt->toAtomString(),
                    'review_due_date' => $task->review_due_date?->toDateString(),
                ],
            ]],
            afterCommit: fn (Task $committedTask) => $this->notifications->dispatchTaskReviewStarted($committedTask, $actor),
        );
    }
}
