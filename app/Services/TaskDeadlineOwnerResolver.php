<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use App\ValueObjects\TaskDeadlineOwnership;

final class TaskDeadlineOwnerResolver
{
    public function __construct(
        private readonly ReviewerEligibilityService $reviewers,
        private readonly NotificationAccess $access,
    ) {}

    public function resolve(?Task $task): TaskDeadlineOwnership
    {
        if (! $task || ! $task->exists || $task->trashed()) {
            return new TaskDeadlineOwnership(null, null, 'task_unavailable');
        }

        $task = Task::on($task->getConnectionName())->find($task->getKey());
        if (! $task) {
            return new TaskDeadlineOwnership(null, null, 'task_unavailable');
        }

        $generation = $task->activeDeadlineGeneration();
        if (! $generation) {
            return new TaskDeadlineOwnership(null, null, 'no_active_deadline');
        }

        if (! $generation->responsibleUserId) {
            return new TaskDeadlineOwnership($generation, null, 'missing_deadline_owner');
        }

        $owner = User::query()->find($generation->responsibleUserId);
        if (! $owner || ! $owner->isActive()) {
            return new TaskDeadlineOwnership($generation, null, 'inactive_or_missing_deadline_owner');
        }

        if ($generation->kind === 'review') {
            if (! $this->reviewers->isEligibleForTask($owner, $task)) {
                return new TaskDeadlineOwnership($generation, null, 'ineligible_reviewer');
            }
        } elseif (! $task->project
            || ! $task->project->members()->whereKey($owner->id)->exists()) {
            return new TaskDeadlineOwnership($generation, null, 'assignee_access_removed');
        }

        if (! $this->access->allows($owner, ['task_id' => $task->id])) {
            return new TaskDeadlineOwnership($generation, null, 'deadline_owner_unauthorized');
        }

        return new TaskDeadlineOwnership($generation, $owner);
    }
}
