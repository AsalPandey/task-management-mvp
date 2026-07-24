<?php

namespace App\TaskTransitions;

use App\Contracts\TaskTransitionCommand;
use App\Exceptions\TaskTransitionException;
use App\Models\Task;
use App\Models\User;
use App\Services\ReviewerEligibilityService;
use App\Services\TaskNotificationDispatcher;
use App\ValueObjects\TaskOperationContext;
use App\ValueObjects\TaskTransitionEffects;

final class ApproveTask extends AbstractApproveTask implements TaskTransitionCommand
{
    public function __construct(
        ReviewerEligibilityService $reviewers,
        TaskNotificationDispatcher $notifications,
        private readonly ?string $approvalComment = null,
    ) {
        parent::__construct($reviewers, $notifications);
    }

    public function ability(): string
    {
        return 'approve';
    }

    public function validate(Task $task, User $actor): void
    {
        if ((int) $task->reviewer_id !== (int) $actor->id) {
            throw TaskTransitionException::invariant(
                'reviewer_id',
                'Only the assigned reviewer may approve this task.',
            );
        }

        $this->validateApproval($task, $actor);
    }

    public function apply(Task $task, User $actor, TaskOperationContext $context): TaskTransitionEffects
    {
        return $this->completeApprovedTask($task, $actor, $context, false, $this->approvalComment, null);
    }
}
