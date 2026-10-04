<?php

namespace App\Services;

use App\Enums\TaskState;
use App\Models\Project;
use App\Models\ProjectHistory;
use App\Models\User;
use App\Notifications\ProjectMemberAdded;
use App\Notifications\ProjectMemberRemoved;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ProjectMembershipService
{
    public function change(Project $project, int $memberId, User $actor, bool $add): bool
    {
        return DB::transaction(function () use ($project, $memberId, $actor, $add): bool {
            $actor = app(ProjectWriterLocks::class)->actor($actor, [$memberId]);
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('manageMembers', $locked);
            $member = User::query()->with('role')->whereKey($memberId)->lockForUpdate()->firstOrFail();
            if ($add) {
                if (! $member->isActive() || ! $member->hasAnyRole(['project_manager', 'team_member'])) {
                    throw ValidationException::withMessages(['user_id' => 'Only active project managers and team members can be added as project members.']);
                }
                // The project lock serializes all supported membership writers; a duplicate
                // must also preserve the original added_by and pivot timestamps.
                if ($locked->members()->whereKey($memberId)->exists()) {
                    return false;
                }
                $result = $locked->members()->syncWithoutDetaching([$member->id => ['added_by' => $actor->id]]);
                $changed = $result['attached'] !== [];
            } else {
                if (! $locked->members()->whereKey($memberId)->exists()) {
                    return false;
                }
                $activeTasks = $locked->tasks()->where(fn ($q) => $q->where('assignee_id', $memberId)->orWhere('reviewer_id', $memberId))
                    ->whereNotIn('status', [TaskState::Completed->value, TaskState::Cancelled->value])->lockForUpdate()->exists();
                if ($activeTasks) {
                    throw ValidationException::withMessages(['user_id' => 'Cannot remove a member with active tasks in this project.']);
                }
                $changed = $locked->members()->detach($memberId) > 0;
            }
            if (! $changed) {
                return false;
            }
            ProjectHistory::query()->create(['project_id' => $locked->id, 'user_id' => $actor->id,
                'action' => $add ? 'member_added' : 'member_removed', 'changes' => ['user_id' => $memberId]]);
            if ($add) {
                DB::afterCommit(fn () => $member->notify(new ProjectMemberAdded($locked, $actor)));
            } else {
                // Safe recipient-only notice and detach commit together, without a revoked-resource link.
                $member->notify(new ProjectMemberRemoved($locked, $actor));
            }

            return true;
        }, 3);
    }
}
