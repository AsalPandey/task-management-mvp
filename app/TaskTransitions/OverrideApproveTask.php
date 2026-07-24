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

final class OverrideApproveTask extends AbstractApproveTask implements TaskTransitionCommand
{
    public function __construct(
        ReviewerEligibilityService $reviewers,
        TaskNotificationDispatcher $notifications,
        private readonly string $overrideReason,
        private readonly ?string $approvalComment = null,
    ) {
        parent::__construct($reviewers, $notifications);
    }

    public function ability(): string
    {
        return 'overrideApprove';
    }

    public function validate(Task $task, User $actor): void
    {
        if (! $actor->hasRole('manager')) {
            throw TaskTransitionException::invariant('approver_id', 'Only a Manager may use override approval.');
        }

        if (trim($this->overrideReason) === '') {
            throw TaskTransitionException::missingData('override_reason', 'An override reason is required.');
        }

        $this->validateApproval($task, $actor);
    }

    public function apply(Task $task, User $actor, TaskOperationContext $context): TaskTransitionEffects
    {
        return $this->completeApprovedTask(
            $task,
            $actor,
            $context,
            true,
            $this->approvalComment,
            trim($this->overrideReason),
        );
    }
}
