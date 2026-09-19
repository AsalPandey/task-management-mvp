<?php

namespace App\Services;

use App\Enums\TaskState;
use App\Exceptions\AccountLifecycleException;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;

class AccountLifecycleService
{
    /**
     * Assert that a user account can be deleted safely.
     *
     * @throws AccountLifecycleException
     */
    public function assertCanDelete(User $user, User $actor): void
    {
        $this->assertNotLastActiveManager($user, 'delete');
        $this->assertNoActiveManagedProjects($user, 'delete');
        $this->assertNoAssignedTasks($user, 'delete');
        $this->assertNoActiveReviewerDuties($user, 'delete');
    }

    /**
     * Assert that a user account can be deactivated safely.
     *
     * @throws AccountLifecycleException
     */
    public function assertCanDeactivate(User $user, User $actor): void
    {
        if ((int) $user->id === (int) $actor->id) {
            throw new AccountLifecycleException(422, 'You cannot deactivate your own account.');
        }

        $this->assertNotLastActiveManager($user, 'deactivate');
        $this->assertNoActiveManagedProjects($user, 'deactivate');
        $this->assertNoActiveTaskAssignments($user, 'deactivate');
        $this->assertNoActiveReviewerDuties($user, 'deactivate');
    }

    /**
     * Assert that a user's role can be changed safely.
     *
     * @throws AccountLifecycleException
     */
    public function assertCanChangeRole(User $user, int $newRoleId, User $actor): void
    {
        $currentRole = $user->role;
        $newRole = Role::query()->findOrFail($newRoleId);

        if ($currentRole && $currentRole->name === 'manager') {
            if ($newRole->name !== 'manager') {
                $this->assertNotLastActiveManager($user, 'change the role of');
            }
        }

        if ($newRole->name === 'team_member') {
            $this->assertNoActiveManagedProjects($user, 'change the role of');
        }

        $activeReviewDuties = Task::query()
            ->with('project:id,project_manager_id')
            ->where('reviewer_id', $user->id)
            ->whereNotIn('status', [TaskState::Completed->value, TaskState::Cancelled->value])
            ->lockForUpdate()
            ->get();

        $wouldInvalidateDuty = $activeReviewDuties->contains(function (Task $task) use ($newRole, $user): bool {
            if ($newRole->name === 'manager') {
                return false;
            }

            return $newRole->name !== 'project_manager'
                || ! $task->project
                || (int) $task->project->project_manager_id !== (int) $user->id
                || (int) $task->assignee_id === (int) $user->id;
        });

        if ($wouldInvalidateDuty) {
            throw new AccountLifecycleException(
                409,
                'Cannot change the role of a user who is the reviewer for active tasks. Reassign their reviewer duties first.',
            );
        }
    }

    public function assertNotLastActiveManager(User $user, string $action): void
    {
        if (! $user->hasRole('manager') || ! $user->isActive()) {
            return;
        }

        // Lock the manager role row to serialize concurrent manager lifecycle mutations
        Role::query()->where('name', 'manager')->lockForUpdate()->first();

        $otherActiveManagers = User::query()
            ->whereHas('role', fn ($query) => $query->where('name', 'manager'))
            ->where('active', true)
            ->whereNull('deleted_at')
            ->where('id', '!=', $user->id)
            ->lockForUpdate()
            ->count();

        if ($otherActiveManagers === 0) {
            throw new AccountLifecycleException(409, "Cannot {$action} the last active manager.");
        }
    }

    public function assertNoActiveManagedProjects(User $user, string $action): void
    {
        $hasActiveProjects = $user->managedProjects()
            ->whereNotIn('status', ['completed', 'archived'])
            ->lockForUpdate()
            ->exists();

        if ($hasActiveProjects) {
            throw new AccountLifecycleException(
                409,
                "Cannot {$action} a user who manages an active project. Assign another project manager or close the project first.",
            );
        }
    }

    public function assertNoAssignedTasks(User $user, string $action): void
    {
        $hasAssignedTasks = Task::query()
            ->where('assignee_id', $user->id)
            ->lockForUpdate()
            ->exists();

        if ($hasAssignedTasks) {
            throw new AccountLifecycleException(
                409,
                'Cannot delete a user with assigned tasks. Deactivate the user or reassign their tasks first.',
            );
        }
    }

    public function assertNoActiveTaskAssignments(User $user, string $action): void
    {
        $hasActiveTasks = Task::query()
            ->where('assignee_id', $user->id)
            ->whereNotIn('status', [TaskState::Completed->value, TaskState::Cancelled->value])
            ->lockForUpdate()
            ->exists();

        if ($hasActiveTasks) {
            throw new AccountLifecycleException(
                409,
                "Cannot {$action} a user with active task assignments. Reassign their tasks first.",
            );
        }
    }

    public function assertNoActiveReviewerDuties(User $user, string $action): void
    {
        $hasActiveReviewDuties = Task::query()
            ->where('reviewer_id', $user->id)
            ->whereNotIn('status', [TaskState::Completed->value, TaskState::Cancelled->value])
            ->lockForUpdate()
            ->exists();

        if ($hasActiveReviewDuties) {
            throw new AccountLifecycleException(
                409,
                "Cannot {$action} a user who is the reviewer for active tasks. Reassign their reviewer duties first.",
            );
        }
    }
}
