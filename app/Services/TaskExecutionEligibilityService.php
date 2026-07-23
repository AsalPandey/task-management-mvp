<?php

namespace App\Services;

use App\Exceptions\TaskTransitionException;
use App\Models\Task;
use App\Models\User;

class TaskExecutionEligibilityService
{
    public function __construct(private readonly ReviewerEligibilityService $reviewers) {}

    public function assertEligible(Task $task, User $actor): void
    {
        if ($task->trashed()) {
            throw TaskTransitionException::invalidState('Deleted tasks cannot change execution state.');
        }

        if (! $actor->isActive() || (int) $task->assignee_id !== (int) $actor->id) {
            throw TaskTransitionException::invariant(
                'assignee_id',
                'Only the active current assignee may change the execution state.',
            );
        }

        if (! $task->project) {
            throw TaskTransitionException::missingData('project_id', 'A task project is required.');
        }

        if (! $task->project->members()->whereKey($actor->id)->exists()) {
            throw TaskTransitionException::invariant(
                'assignee_id',
                'The assignee must still belong to the task project.',
            );
        }

        if (! $task->reviewer) {
            throw TaskTransitionException::missingData(
                'reviewer_id',
                'An eligible reviewer must be assigned before work can change execution state.',
            );
        }

        $this->reviewers->assertEligibleForTask($task->reviewer, $task);
    }
}
