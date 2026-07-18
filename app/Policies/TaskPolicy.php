<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
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

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Task $task): bool
    {
        return $user->hasRole('manager')
            || ($user->hasRole('project_manager') && $task->project && (int) $task->project->project_manager_id === (int) $user->id);
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
}
