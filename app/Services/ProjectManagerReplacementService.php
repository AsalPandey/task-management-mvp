<?php

namespace App\Services;

use App\Enums\TaskState;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskHistory;
use App\Models\User;
use App\ValueObjects\TaskOperationContext;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ProjectManagerReplacementService
{
    public function __construct(
        private readonly ReviewerEligibilityService $reviewers,
        private readonly TaskEventRecorder $eventRecorder,
        private readonly TaskNotificationDispatcher $notifications,
    ) {}

    /**
     * Reconcile task reviewer assignments when a project manager is replaced.
     *
     * @throws HttpException
     */
    public function reconcile(Project $project, ?int $oldPmId, ?int $newPmId, User $actor): void
    {
        if ($oldPmId === $newPmId) {
            return;
        }

        $connection = $project->getConnection();

        $outgoing = $oldPmId ? User::with('role')->find($oldPmId) : null;
        $proposed = clone $project;
        $proposed->project_manager_id = $newPmId;
        if ($outgoing && ! app(TaskAssignmentCandidateService::class)->canExecuteInProject($outgoing, $proposed)
            && Task::on($connection->getName())->where('project_id', $project->id)->where('assignee_id', $oldPmId)
                ->whereNotIn('status', [TaskState::Completed->value, TaskState::Cancelled->value])->lockForUpdate()->exists()) {
            abort(409, 'The outgoing project manager has unfinished task assignments that would become inaccessible. Reassign or complete them before replacing the project manager.');
        }

        // 1. If removing PM entirely ($newPmId === null), check if any active tasks rely on old PM as reviewer.
        if ($newPmId === null) {
            $hasDependentActiveTasks = Task::on($connection->getName())
                ->where('project_id', $project->id)
                ->whereNotIn('status', [
                    TaskState::Completed->value,
                    TaskState::Cancelled->value,
                    'Completed',
                    'Cancelled',
                ])
                ->where('reviewer_id', $oldPmId)
                ->exists();

            if ($hasDependentActiveTasks) {
                abort(422, 'Cannot remove project manager while active tasks have the current project manager assigned as reviewer.');
            }

            return;
        }

        $newPm = User::query()->with('role')->findOrFail($newPmId);
        $oldPm = $oldPmId ? User::query()->with('role')->find($oldPmId) : null;

        // The project writer lock serializes responsibility writers. Select only duties
        // whose current reviewer loses eligibility under the proposed ownership.
        $tasksToReassign = Task::on($connection->getName())
            ->where('project_id', $project->id)
            ->whereNotIn('status', [
                TaskState::Completed->value,
                TaskState::Cancelled->value,
                'Completed',
                'Cancelled',
            ])
            ->whereNotNull('reviewer_id')
            ->where(function ($tasks) use ($oldPmId, $newPmId) {
                if ($oldPmId !== null) {
                    $tasks->where('reviewer_id', $oldPmId);
                }
                $tasks->orWhereHas('reviewer', fn ($users) => $users->where(fn ($invalid) => $invalid
                    ->where('active', false)
                    ->orWhereColumn('users.id', 'tasks.assignee_id')
                    ->orWhere(fn ($roles) => $roles
                        ->whereDoesntHave('role', fn ($role) => $role->where('name', 'manager'))
                        ->where(fn ($pm) => $pm->where('users.id', '!=', $newPmId)
                            ->orWhereDoesntHave('role', fn ($role) => $role->where('name', 'project_manager'))))));
            });

        // Validate the whole operation before the first mutation, including conflicts
        // at the end of a large project. Never rely on per-chunk validation alone.
        if ((clone $tasksToReassign)->where('assignee_id', $newPmId)->lockForUpdate()->exists()) {
            abort(422, 'Cannot replace project manager: the new project manager is assigned to active tasks in this project, which prohibits self-review.');
        }

        // Temporarily simulate the project with the new project manager to check future eligibility
        $simulatedProject = clone $project;
        $simulatedProject->project_manager_id = $newPmId;

        $context = TaskOperationContext::web($actor);

        $changed = false;
        $tasksToReassign->with(['reviewer', 'assignee', 'creator'])->lockForUpdate()
            ->chunkById(100, function ($tasks) use ($newPmId, $newPm, $oldPm, $actor, $context, $simulatedProject, &$changed) {
                foreach ($tasks as $task) {
                    $changed = true;
                    $oldReviewerModel = $task->reviewer;
                    $oldReviewerId = (int) $task->reviewer_id;

                    // Atomically update reviewer_id and advance version
                    $task->forceFill([
                        'reviewer_id' => $newPmId,
                        'lock_version' => ((int) $task->lock_version) + 1,
                    ])->save();

                    $changes = [
                        'reviewer_id' => [
                            'before' => $oldReviewerId,
                            'after' => $newPmId,
                        ],
                    ];

                    $reason = 'Project manager changed from '.($oldPm?->name ?? 'None')." to {$newPm->name}.";

                    // Record TaskHistory
                    $history = TaskHistory::query()->create([
                        'task_id' => $task->id,
                        'project_id' => $task->project_id,
                        'original_task_id' => $task->original_task_id,
                        'task_title' => $task->title,
                        'user_id' => $actor->id,
                        'action' => 'reviewer_reassigned',
                        'changes' => [
                            'reason' => $reason,
                            'changes' => $changes,
                        ],
                    ]);

                    // Record TaskEvent
                    $this->eventRecorder->record(
                        $task,
                        TaskEventRecorder::REVIEWER_REASSIGNED,
                        $context,
                        $changes,
                        [
                            'reason_reference' => "task_histories:{$history->id}",
                            'state' => $task->machineState()->value,
                            'project_manager_replacement' => true,
                        ],
                        joinOuterTransaction: true,
                    );

                    // Persist required intent before the outer project mutation commits.
                    $task->setRelation('reviewer', $newPm);
                    $task->setRelation('project', $simulatedProject);
                    $this->notifications->dispatchReviewerReassigned($task, $actor, $oldReviewerModel, false);
                }
            });

        if ($changed) {
            $projectId = (int) $project->id;
            $connection->afterCommit(fn () => app(RequiredWorkflowNotifications::class)->deliverProjectReviewerReassignments($projectId));
        }
    }
}
