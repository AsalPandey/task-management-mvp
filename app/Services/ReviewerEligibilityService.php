<?php

namespace App\Services;

use App\Exceptions\TaskTransitionException;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

class ReviewerEligibilityService
{
    public function isEligible(User $reviewer, Project $project, int|string|null $assigneeId = null): bool
    {
        if (! $reviewer->isActive()) {
            return false;
        }

        if ($assigneeId !== null && (int) $reviewer->id === (int) $assigneeId) {
            return false;
        }

        if ($reviewer->hasRole('manager')) {
            return true;
        }

        return $reviewer->hasRole('project_manager')
            && (int) $project->project_manager_id === (int) $reviewer->id;
    }

    public function isEligibleForTask(User $reviewer, Task $task): bool
    {
        $task->loadMissing('project');

        return $task->project !== null
            && $this->isEligible($reviewer, $task->project, $task->assignee_id);
    }

    public function assertEligible(User $reviewer, Project $project, int|string|null $assigneeId = null): void
    {
        if (! $reviewer->isActive()) {
            throw TaskTransitionException::invariant('reviewer_id', 'The reviewer must be active.');
        }

        if ($assigneeId !== null && (int) $reviewer->id === (int) $assigneeId) {
            throw TaskTransitionException::invariant('reviewer_id', 'The reviewer must be different from the assignee.');
        }

        if (! $reviewer->hasAnyRole(['manager', 'project_manager'])) {
            throw TaskTransitionException::invariant(
                'reviewer_id',
                'The reviewer must be a Manager or the Project Manager who manages this project.',
            );
        }

        if ($reviewer->hasRole('project_manager')
            && (int) $project->project_manager_id !== (int) $reviewer->id) {
            throw TaskTransitionException::invariant(
                'reviewer_id',
                'A Project Manager may review only tasks in a project they manage.',
            );
        }
    }

    public function assertEligibleForTask(User $reviewer, Task $task): void
    {
        $task->loadMissing('project');

        if (! $task->project) {
            throw TaskTransitionException::invariant('project_id', 'A task project is required for reviewer eligibility.');
        }

        $this->assertEligible($reviewer, $task->project, $task->assignee_id);
    }
}
