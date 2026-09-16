<?php

namespace App\Services;

use App\Enums\TaskState;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskHistory;
use App\Models\User;
use App\ValueObjects\TaskOperationContext;
use Illuminate\Support\Facades\Log;
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

        // 2. Fetch and lock all active tasks in the project
        $activeTasks = Task::on($connection->getName())
            ->where('project_id', $project->id)
            ->whereNotIn('status', [
                TaskState::Completed->value,
                TaskState::Cancelled->value,
                'Completed',
                'Cancelled',
            ])
            ->with(['reviewer', 'assignee'])
            ->lockForUpdate()
            ->get();

        // Check which active tasks require reviewer reassignment
        $tasksToReassign = [];

        // Temporarily simulate the project with the new project manager to check future eligibility
        $simulatedProject = clone $project;
        $simulatedProject->project_manager_id = $newPmId;

        foreach ($activeTasks as $task) {
            $currentReviewerId = $task->reviewer_id ? (int) $task->reviewer_id : null;
            if ($currentReviewerId === null) {
                continue;
            }

            $isOldPmReviewer = ($oldPmId !== null && $currentReviewerId === (int) $oldPmId);
            $reviewerModel = $task->reviewer ?? User::find($currentReviewerId);

            $losesEligibility = $reviewerModel && ! $this->reviewers->isEligible($reviewerModel, $simulatedProject, $task->assignee_id);

            if ($isOldPmReviewer || $losesEligibility) {
                // Check self-review conflict: if new PM is the assignee of this task, abort with 422
                if ((int) $task->assignee_id === (int) $newPmId) {
                    abort(422, 'Cannot replace project manager: the new project manager is assigned to active tasks in this project, which prohibits self-review.');
                }

                $tasksToReassign[] = [
                    'task' => $task,
                    'old_reviewer' => $reviewerModel,
                    'old_reviewer_id' => $currentReviewerId,
                ];
            }
        }

        if (empty($tasksToReassign)) {
            return;
        }

        $context = TaskOperationContext::web($actor);
        $pendingDispatches = [];

        foreach ($tasksToReassign as $item) {
            /** @var Task $task */
            $task = $item['task'];
            $oldReviewerModel = $item['old_reviewer'];
            $oldReviewerId = $item['old_reviewer_id'];

            // Atomically update reviewer_id
            $task->forceFill(['reviewer_id' => $newPmId])->save();

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
            );

            $pendingDispatches[] = [
                'task_id' => (int) $task->id,
                'old_reviewer' => $oldReviewerModel,
            ];
        }

        // Register afterCommit notification dispatches
        $connectionName = $connection->getName();
        $notifications = $this->notifications;

        $connection->afterCommit(function () use ($connectionName, $pendingDispatches, $actor, $notifications): void {
            foreach ($pendingDispatches as $dispatchItem) {
                $committedTask = Task::on($connectionName)
                    ->with(['assignee', 'creator', 'reviewer', 'activeRevisionCycle', 'approval', 'project.projectManager'])
                    ->find($dispatchItem['task_id']);

                if ($committedTask) {
                    try {
                        $notifications->dispatchReviewerReassigned(
                            $committedTask,
                            $actor,
                            $dispatchItem['old_reviewer'],
                        );
                    } catch (\Throwable $exception) {
                        Log::warning('Task notification dispatch failed following project manager replacement', [
                            'task_id' => $dispatchItem['task_id'],
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            }
        });
    }
}
