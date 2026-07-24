<?php

namespace App\Policies;

use App\Enums\TaskState;
use App\Models\Task;
use App\Models\User;
use App\Services\ReviewerEligibilityService;

class TaskPolicy
{
    public function __construct(private readonly ReviewerEligibilityService $reviewers) {}

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['manager', 'project_manager', 'team_member']);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Task $task): bool
    {
        if ($user->hasRole('manager')) {
            return true;
        }

        if ($user->hasRole('project_manager')) {
            return $task->project && (int) $task->project->project_manager_id === (int) $user->id;
        }

        return (int) $task->assignee_id === (int) $user->id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasAnyRole(['manager', 'project_manager']);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Task $task): bool
    {
        return $this->view($user, $task);
    }

    public function complete(User $user, Task $task): bool
    {
        return false;
    }

    public function reopen(User $user, Task $task): bool
    {
        return $this->controlsManagement($user, $task);
    }

    public function start(User $user, Task $task): bool
    {
        return $this->controlsAssigneeWork($user, $task);
    }

    public function hold(User $user, Task $task): bool
    {
        return $this->controlsAssigneeWork($user, $task);
    }

    public function resume(User $user, Task $task): bool
    {
        return $this->controlsAssigneeWork($user, $task);
    }

    public function submit(User $user, Task $task): bool
    {
        return $this->controlsAssigneeWork($user, $task);
    }

    public function startReview(User $user, Task $task): bool
    {
        return $this->isAssignedEligibleReviewer($user, $task);
    }

    public function requestRevision(User $user, Task $task): bool
    {
        return $this->isAssignedEligibleReviewer($user, $task);
    }

    public function startRevision(User $user, Task $task): bool
    {
        return $this->controlsAssigneeWork($user, $task);
    }

    public function resubmit(User $user, Task $task): bool
    {
        return $this->controlsAssigneeWork($user, $task);
    }

    public function approve(User $user, Task $task): bool
    {
        return $user->isActive()
            && (int) $task->reviewer_id === (int) $user->id;
    }

    public function overrideApprove(User $user, Task $task): bool
    {
        return $user->isActive() && $user->hasRole('manager');
    }

    public function cancel(User $user, Task $task): bool
    {
        return $this->controlsManagement($user, $task);
    }

    public function overrideReviewer(User $user, Task $task): bool
    {
        return $user->isActive() && $user->hasRole('manager');
    }

    public function reassignReviewer(User $user, Task $task): bool
    {
        return ! $task->machineState()->isFinal()
            && $this->controlsManagement($user, $task);
    }

    public function changeDeadline(User $user, Task $task): bool
    {
        return ! $task->machineState()->isFinal()
            && $this->controlsManagement($user, $task);
    }

    public function updateProgress(User $user, Task $task): bool
    {
        return $this->controlsAssigneeWork($user, $task);
    }

    public function viewSensitivePriority(User $user, Task $task): bool
    {
        return $this->controlsManagement($user, $task);
    }

    public function viewManagementNotes(User $user, Task $task): bool
    {
        return $this->controlsManagement($user, $task);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Task $task): bool
    {
        return $task->machineState() === TaskState::NotStarted
            && $this->controlsManagement($user, $task);
    }

    public function bulkActions(User $user): bool
    {
        return $user->hasAnyRole(['manager', 'project_manager']);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Task $task): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Task $task): bool
    {
        return false;
    }

    private function controlsLifecycle(User $user, Task $task): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        if ($user->hasRole('manager')) {
            return true;
        }

        if ($user->hasRole('project_manager')) {
            return $task->project
                && (int) $task->project->project_manager_id === (int) $user->id;
        }

        return $user->hasRole('team_member')
            && (int) $task->assignee_id === (int) $user->id
            && $task->project
            && $user->can('view', $task->project);
    }

    private function controlsAssigneeWork(User $user, Task $task): bool
    {
        return $user->isActive()
            && (int) $task->assignee_id === (int) $user->id
            && $task->project
            && $user->can('view', $task->project);
    }

    private function isAssignedEligibleReviewer(User $user, Task $task): bool
    {
        return (int) $task->reviewer_id === (int) $user->id
            && $this->reviewers->isEligibleForTask($user, $task);
    }

    private function controlsManagement(User $user, Task $task): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        return $user->hasRole('manager')
            || ($user->hasRole('project_manager')
                && $task->project
                && (int) $task->project->project_manager_id === (int) $user->id);
    }
}
