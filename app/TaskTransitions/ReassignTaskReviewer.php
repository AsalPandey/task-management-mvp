<?php

namespace App\TaskTransitions;

use App\Contracts\TaskTransitionCommand;
use App\Enums\TaskState;
use App\Exceptions\TaskTransitionException;
use App\Models\Task;
use App\Models\User;
use App\Services\ReviewerEligibilityService;
use App\Services\TaskEventRecorder;
use App\Services\TaskNotificationDispatcher;
use App\ValueObjects\TaskOperationContext;
use App\ValueObjects\TaskTransitionEffects;

final class ReassignTaskReviewer implements TaskTransitionCommand
{
    public function __construct(
        private readonly ReviewerEligibilityService $reviewers,
        private readonly TaskNotificationDispatcher $notifications,
        private readonly int $reviewerId,
        private readonly ?string $reason = null,
    ) {}

    public function ability(): string
    {
        return 'reassignReviewer';
    }

    public function validate(Task $task, User $actor): void
    {
        if ($task->machineState()->isFinal()) {
            throw TaskTransitionException::invalidState('A reviewer cannot be reassigned on a final task.');
        }

        if ((int) $task->reviewer_id === $this->reviewerId) {
            throw TaskTransitionException::invariant('reviewer_id', 'The selected reviewer is already assigned.');
        }

        $reviewer = User::query()->with('role')->find($this->reviewerId);
        if (! $reviewer) {
            throw TaskTransitionException::missingData('reviewer_id', 'The selected reviewer is unavailable.');
        }

        $this->reviewers->assertEligibleForTask($reviewer, $task);

        if ($this->reasonRequired($task) && trim((string) $this->reason) === '') {
            throw TaskTransitionException::missingData(
                'reason',
                'A reason is required after task submission.',
            );
        }
    }

    public function apply(Task $task, User $actor, TaskOperationContext $context): TaskTransitionEffects
    {
        $oldReviewer = $task->reviewer;
        $newReviewer = User::query()->findOrFail($this->reviewerId);
        $reason = trim((string) $this->reason);
        $reasonReference = $reason === '' ? null : TaskTransitionEffects::HISTORY_REFERENCE;
        $changes = [
            'reviewer_id' => [
                'before' => $task->reviewer_id === null ? null : (int) $task->reviewer_id,
                'after' => (int) $newReviewer->id,
            ],
        ];

        $task->forceFill(['reviewer_id' => $newReviewer->id])->save();

        return new TaskTransitionEffects(
            historyAction: 'reviewer_reassigned',
            historyChanges: [
                'reason' => $reason === '' ? null : $reason,
                'changes' => $changes,
            ],
            events: [[
                'type' => TaskEventRecorder::REVIEWER_REASSIGNED,
                'changed_fields' => $changes,
                'metadata' => [
                    'reason_reference' => $reasonReference,
                    'state' => $task->machineState()->value,
                ],
            ]],
            afterCommit: fn (Task $committedTask) => $this->notifications
                ->dispatchReviewerReassigned($committedTask, $actor, $oldReviewer),
        );
    }

    private function reasonRequired(Task $task): bool
    {
        return in_array($task->machineState(), [
            TaskState::Submitted,
            TaskState::InReview,
            TaskState::RevisionRequested,
        ], true) || $task->submitted_at !== null || $task->active_revision_cycle_id !== null;
    }
}
