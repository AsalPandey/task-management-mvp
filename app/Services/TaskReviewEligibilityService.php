<?php

namespace App\Services;

use App\Exceptions\TaskTransitionException;
use App\Models\Task;
use App\Models\User;

class TaskReviewEligibilityService
{
    public function __construct(private readonly ReviewerEligibilityService $reviewers) {}

    public function assertAssigneeMaySubmit(Task $task, User $actor): void
    {
        $this->assertActiveAssignee($task, $actor);

        if (! $task->reviewer) {
            throw TaskTransitionException::missingData('reviewer_id', 'An eligible reviewer must be assigned before submission.');
        }

        $this->reviewers->assertEligibleForTask($task->reviewer, $task);
    }

    public function assertActiveAssignee(Task $task, User $actor): void
    {
        if ($task->trashed() || ! $actor->isActive() || (int) $task->assignee_id !== (int) $actor->id) {
            throw TaskTransitionException::invariant('assignee_id', 'Only the active current assignee may submit this task.');
        }

        if (! $task->project || ! $task->project->members()->whereKey($actor->id)->exists()) {
            throw TaskTransitionException::invariant('assignee_id', 'The assignee must still belong to the task project.');
        }
    }

    public function assertReviewerMayStart(Task $task, User $actor): void
    {
        if (! $actor->isActive() || (int) $task->reviewer_id !== (int) $actor->id) {
            throw TaskTransitionException::invariant('reviewer_id', 'Only the active assigned reviewer may start review.');
        }

        $this->reviewers->assertEligibleForTask($actor, $task);
    }
}
