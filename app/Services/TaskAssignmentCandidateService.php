<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class TaskAssignmentCandidateService
{
    public function canExecuteInProject(User $user, Project $project): bool
    {
        return $user->isActive() && ($user->hasAnyRole(['manager', 'team_member'])
            || ($user->hasRole('project_manager') && (int) $project->project_manager_id === (int) $user->id));
    }

    /**
     * @param  Collection<int, mixed>  $projects
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
