<?php

namespace App\Services;

use App\Enums\TaskState;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class TaskReadService
{
    /** @return Builder<Task> */
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

    /** @return Builder<Task> */
    public function activeVisibleTo(User $user): Builder
    {
        return $this->visibleTo($user)->whereNotIn('status', [
            TaskState::Completed->value,
            TaskState::Cancelled->value,
        ]);
    }

    /** @return Builder<Task> */
    public function completedVisibleTo(User $user): Builder
    {
        return $this->visibleTo($user)->where('status', TaskState::Completed->value);
    }

    /** @return Builder<Task> */
    public function cancelledVisibleTo(User $user): Builder
    {
        return $this->visibleTo($user)->where('status', TaskState::Cancelled->value);
    }

    /** @return Builder<Task> */
    public function executionVisibleTo(User $user): Builder
    {
        return $this->visibleTo($user)->whereIn('status', [
            TaskState::NotStarted->value,
            TaskState::InProgress->value,
            TaskState::OnHold->value,
            TaskState::RevisionRequested->value,
        ]);
    }

    /** @return Builder<Task> */
    public function reviewQueueVisibleTo(User $user): Builder
    {
        return $this->visibleTo($user)->whereIn('status', [
            TaskState::Submitted->value,
            TaskState::InReview->value,
        ]);
    }
}
