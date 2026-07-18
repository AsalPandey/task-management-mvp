<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['manager', 'project_manager', 'team_member']);
    }

    public function view(User $user, Project $project): bool
    {
        if ($user->hasRole('manager')) {
            return true;
        }

        if ($user->hasRole('project_manager')) {
            return (int) $project->project_manager_id === (int) $user->id;
        }

        return $project->members()->whereKey($user->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['manager', 'project_manager']);
    }

    public function update(User $user, Project $project): bool
    {
        return $user->hasRole('manager')
            || ($user->hasRole('project_manager') && (int) $project->project_manager_id === (int) $user->id);
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->hasRole('manager');
    }

    public function manageMembers(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }
}
