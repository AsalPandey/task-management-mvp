<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class TaskAssignmentCandidateService
{
    /**
     * @param  Collection<int, mixed>  $projects
     * @return Builder<User>
     */
    public function forProjects(Collection $projects): Builder
    {
        return User::query()
            ->where('active', true)
            ->whereHas(
                'projects',
                fn (Builder $query) => $query->whereIn('projects.id', $projects->modelKeys()),
            )
            ->orderBy('name');
    }
}
