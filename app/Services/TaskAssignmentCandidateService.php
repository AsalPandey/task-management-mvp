<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class TaskAssignmentCandidateService
{
    public function eligibleInProject(Builder $users, Project $project): Builder
    {
        return $users->where('active', true)->where(fn ($query) => $query
            ->whereHas('role', fn ($roles) => $roles->whereIn('name', ['manager', 'team_member']))
            ->orWhere(fn ($pm) => $pm->whereKey($project->project_manager_id)
                ->whereHas('role', fn ($roles) => $roles->where('name', 'project_manager'))));
    }

    /** Apply the same execution rule to tasks under a proposed account role. */
    public function losingExecutionEligibility(Builder $tasks, User $user, string $role): Builder
    {
        if ($user->isActive() && in_array($role, ['manager', 'team_member'], true)) {
            return $tasks->whereRaw('1 = 0');
        }
        if ($user->isActive() && $role === 'project_manager') {
            return $tasks->whereDoesntHave('project', fn ($projects) => $projects->where('project_manager_id', $user->id));
        }

        return $tasks;
    }

    public function canExecuteInProject(User $user, Project $project): bool
    {
        return $user->isActive() && ($user->hasAnyRole(['manager', 'team_member'])
            || ($user->hasRole('project_manager') && (int) $project->project_manager_id === (int) $user->id));
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @return Builder<User>
     */
    public function forProjects(Collection $projects): Builder
    {
        return User::query()
            ->where('active', true)
            ->where(function (Builder $query) use ($projects) {
                $query->where(fn (Builder $users) => $users
                    ->whereHas('role', fn ($roles) => $roles->whereIn('name', ['manager', 'team_member']))
                    ->whereHas('projects', fn ($scope) => $scope->whereIn('projects.id', $projects->modelKeys())))
                    ->orWhere(fn (Builder $users) => $users
                        ->whereHas('role', fn ($roles) => $roles->where('name', 'project_manager'))
                        ->whereHas('projects', fn ($scope) => $scope->whereIn('projects.id', $projects->modelKeys())
                            ->whereColumn('projects.project_manager_id', 'users.id')));
            })
            ->orderBy('name');
    }
}
