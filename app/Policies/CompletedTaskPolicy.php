<?php

namespace App\Policies;

use App\Models\CompletedTask;
use App\Models\User;

class CompletedTaskPolicy
{
    public function revert(User $user, CompletedTask $completedTask): bool
    {
        if (! $user->isActive()) {
            return false;
        }

        if ($user->hasRole('manager')) {
            return true;
        }

        if ($user->hasRole('project_manager')) {
            return $completedTask->project
                && (int) $completedTask->project->project_manager_id === (int) $user->id;
        }

        return $user->hasRole('team_member')
            && (int) $completedTask->assignee_id === (int) $user->id
            && $completedTask->project
            && $user->can('view', $completedTask->project);
    }
}
