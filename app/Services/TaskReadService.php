<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class TaskReadService
{
    public function visibleTo(User $user): Builder
    {
        $query = Task::query();

        if (! $user->isActive()) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasRole('manager')) {
            return $query;
        }

        if ($user->hasRole('project_manager')) {
            return $query->whereHas(
                'project',
                fn (Builder $projectQuery) => $projectQuery->where('project_manager_id', $user->id),
            );
        }

        return $query->where('assignee_id', $user->id);
    }

    public function activeVisibleTo(User $user): Builder
    {
        return $this->visibleTo($user)->where('status', '!=', 'Completed');
    }

    public function completedVisibleTo(User $user): Builder
    {
        return $this->visibleTo($user)->where('status', 'Completed');
    }
}
