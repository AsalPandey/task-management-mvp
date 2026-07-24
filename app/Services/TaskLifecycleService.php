<?php

namespace App\Services;

use App\Enums\TaskState;
use App\Exceptions\TaskTransitionException;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskHistory;
use App\Models\User;
use App\Support\TaskStateCompatibility;
use App\ValueObjects\TaskOperationContext;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class TaskLifecycleService
{
    private const TASK_EVENT_FIELDS = [
        'project_id',
        'title',
        'description',
        'assignee_id',
        'reviewer_id',
        'priority',
        'status',
        'progress',
        'start_date',
        'due_date',
        'review_due_date',
        'comments',
    ];

    private const MAX_TASK_UID_ATTEMPTS = 5;

    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private readonly TaskEventRecorder $eventRecorder,
        private readonly TaskNotificationDispatcher $notificationDispatcher,
        private readonly ReviewerEligibilityService $reviewerEligibility,
    ) {}

    public function create(array $data, User $actor, ?TaskOperationContext $context = null): Task
    {
        $context ??= TaskOperationContext::system($actor->id);
        if (isset($data['status']) && is_string($data['status'])) {
            $data['status'] = TaskStateCompatibility::normalizeGenericInput($data['status']);
        }
        $reviewerId = array_key_exists('reviewer_id', $data) ? $data['reviewer_id'] : null;
        $reviewDueDate = $data['review_due_date'] ?? null;
        unset($data['reviewer_id'], $data['review_due_date']);

        return DB::transaction(function () use ($data, $actor, $context, $reviewerId, $reviewDueDate) {
            $data['created_by'] = $actor->id;
            $data['assigned_by'] = $actor->id;
            $project = $this->assertProjectAccess($data['project_id'], $actor);
            $this->assertProjectAcceptsNewTasks($project);
            $this->assertAssigneeIsProjectMember($data['project_id'], $data['assignee_id'] ?? null);
            $this->assertCompletionState($data);
            $this->assertReviewerAssignment($project, $data['assignee_id'] ?? null, $reviewerId, $actor);

            $task = $this->createTaskWithUidRetry($data);
            $task->forceFill([
                'reviewer_id' => $reviewerId,
                'review_due_date' => $reviewDueDate,
            ])->save();
            $this->recordHistory($task, 'created', $data, $actor);
            $this->eventRecorder->record(
                $task,
                TaskEventRecorder::CREATED,
                $context,
                $this->createdEventChanges($task),
            );

            $this->notificationDispatcher->taskCreated($task, $actor);

            return $task->load(['project', 'assignee']);
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function update(Task $task, array $data, User $actor, ?TaskOperationContext $context = null): Task
    {
        $context ??= TaskOperationContext::system($actor->id);
        if (isset($data['status']) && is_string($data['status'])) {
            $data['status'] = TaskStateCompatibility::normalizeGenericInput($data['status']);
        }

        if ($actor->hasRole('team_member')) {
            $data = array_intersect_key($data, array_flip(['status', 'progress', 'comments']));
        }

        return DB::transaction(function () use ($task, $data, $actor, $context) {
            $lockedTask = Task::query()
                ->whereKey($task->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            Gate::forUser($actor)->authorize('update', $lockedTask);

            if ($lockedTask->machineState() === TaskState::Completed) {
                throw ValidationException::withMessages([
                    'task' => 'Completed tasks must be reopened before they can be updated.',
                ]);
            }

            if (in_array($lockedTask->machineState(), [TaskState::Submitted, TaskState::InReview, TaskState::RevisionRequested], true)
                && $data !== []) {
                throw ValidationException::withMessages([
                    'task' => 'Submitted and in-review tasks are frozen until a dedicated correction action is available.',
                ]);
            }

            if ($lockedTask->active_revision_cycle_id
                && (array_key_exists('assignee_id', $data) || array_key_exists('reviewer_id', $data))) {
                throw ValidationException::withMessages([
                    'task' => 'Assignee and reviewer changes require a dedicated workflow action while a revision cycle is active.',
                ]);
            }

            if (isset($data['status']) && is_string($data['status'])) {
                TaskStateCompatibility::assertGenericTransitionAllowed(
                    $lockedTask->machineState(),
                    TaskState::from($data['status']),
                );
            }

            $lockedTask->load(['project', 'assignee', 'reviewer']);
            $old = $lockedTask->toArray();
            $beforeEventValues = $this->taskEventValues($lockedTask);
            $reviewerProvided = array_key_exists('reviewer_id', $data);
            $reviewDueDateProvided = array_key_exists('review_due_date', $data);
            $reviewerId = $reviewerProvided ? $data['reviewer_id'] : $lockedTask->reviewer_id;
            $reviewDueDate = $reviewDueDateProvided ? $data['review_due_date'] : $lockedTask->review_due_date;
            unset($data['reviewer_id'], $data['review_due_date']);
            $merged = array_merge($lockedTask->only(self::TASK_EVENT_FIELDS), $data);

            $project = $this->assertProjectAccess((int) $merged['project_id'], $actor);
            if ((int) $merged['project_id'] !== (int) $lockedTask->project_id) {
                $this->assertProjectAcceptsNewTasks($project);
            }
            $this->assertAssigneeIsProjectMember((int) $merged['project_id'], $merged['assignee_id'] ?? null);
            $this->assertCompletionState($merged);
            if ($reviewerProvided || $reviewDueDateProvided) {
                $this->assertReviewerAssignment($project, $merged['assignee_id'] ?? null, $reviewerId, $actor);
            }

            if (($merged['assignee_id'] ?? null) && (int) $merged['assignee_id'] !== (int) $lockedTask->assignee_id) {
                $merged['assigned_by'] = $actor->id;
            }

            $lockedTask->update($merged);
            if ($reviewerProvided || $reviewDueDateProvided) {
                $lockedTask->forceFill([
                    'reviewer_id' => $reviewerId,
                    'review_due_date' => $reviewDueDate,
                ])->save();
            }
            $lockedTask->refresh()->load(['project', 'assignee', 'reviewer']);
            $this->recordHistory($lockedTask, 'updated', ['old' => $old, 'new' => $merged], $actor);
            $changedFields = $this->changedEventValues($beforeEventValues, $this->taskEventValues($lockedTask));

            if ($changedFields !== []) {
                $this->eventRecorder->record(
                    $lockedTask,
                    TaskEventRecorder::UPDATED,
                    $context,
                    $changedFields,
                );
            }

            $this->notificationDispatcher->taskUpdated($lockedTask, $merged, $actor);

            return $lockedTask;
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function delete(Task $task, User $actor, string $action = 'deleted'): void
    {
        DB::transaction(function () use ($task, $actor, $action) {
            $lockedTask = Task::withTrashed()
                ->whereKey($task->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedTask->load('project');
            Gate::forUser($actor)->authorize('delete', $lockedTask);

            if ($lockedTask->trashed()) {
                throw ValidationException::withMessages([
                    'task' => 'Task is already deleted.',
                ]);
            }

            $hasMeaningfulActivity = $lockedTask->machineState() !== TaskState::NotStarted
                || $lockedTask->submissions()->exists()
                || $lockedTask->revisionCycles()->exists()
                || $lockedTask->approval()->exists()
                || $lockedTask->events()
                    ->whereNotIn('event_type', [TaskEventRecorder::CREATED, TaskEventRecorder::UPDATED])
                    ->exists();

            if ($hasMeaningfulActivity) {
                throw ValidationException::withMessages([
                    'task' => 'Tasks with meaningful lifecycle activity must be cancelled instead of deleted.',
                ]);
            }

            $this->recordHistory($lockedTask, $action, $lockedTask->toArray(), $actor);
            $lockedTask->delete();
        }, self::TRANSACTION_ATTEMPTS);
    }

    private function assertProjectAccess(int|string|null $projectId, User $actor): Project
    {
        if (! $projectId) {
            throw ValidationException::withMessages(['project_id' => 'A project is required.']);
        }

        $project = Project::query()->findOrFail($projectId);
        if (! $actor->can('view', $project)) {
            abort(403, 'Unauthorized project access.');
        }

        return $project;
    }

    private function assertProjectAcceptsNewTasks(Project $project): void
    {
        if (in_array($project->status, ['completed', 'archived'], true)) {
            throw ValidationException::withMessages([
                'project_id' => 'Tasks cannot be created or moved into completed or archived projects.',
            ]);
        }
    }

    private function assertAssigneeIsProjectMember(int|string|null $projectId, int|string|null $assigneeId): void
    {
        if (! $assigneeId) {
            return;
        }

        $isMember = Project::query()
            ->whereKey($projectId)
            ->whereHas('members', fn ($query) => $query->whereKey($assigneeId)->where('active', true))
            ->exists();

        if (! $isMember) {
            throw ValidationException::withMessages([
                'assignee_id' => 'The selected assignee is not an active member of this project.',
            ]);
        }
    }

    private function assertReviewerAssignment(
        Project $project,
        int|string|null $assigneeId,
        int|string|null $reviewerId,
        User $actor,
    ): void {
        if (! $actor->isActive() || ! $actor->hasAnyRole(['manager', 'project_manager'])) {
            throw ValidationException::withMessages([
                'reviewer_id' => 'Only management may assign or change a reviewer.',
            ]);
        }

        if ($actor->hasRole('project_manager')
            && (int) $project->project_manager_id !== (int) $actor->id) {
            throw ValidationException::withMessages([
                'reviewer_id' => 'A Project Manager may assign reviewers only for a project they manage.',
            ]);
        }

        if ($reviewerId === null || $reviewerId === '') {
            return;
        }

        $reviewer = User::query()->with('role')->find($reviewerId);

        if (! $reviewer) {
            throw ValidationException::withMessages(['reviewer_id' => 'The selected reviewer is unavailable.']);
        }

        try {
            $this->reviewerEligibility->assertEligible($reviewer, $project, $assigneeId);
        } catch (TaskTransitionException $exception) {
            throw ValidationException::withMessages($exception->errors());
        }
    }

    private function assertCompletionState(array $data): void
    {
        if ((int) ($data['progress'] ?? 0) > 99) {
            throw ValidationException::withMessages([
                'progress' => 'Progress may reach 100% only through reviewer approval.',
            ]);
        }
    }

    private function createTaskWithUidRetry(array $data): Task
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_TASK_UID_ATTEMPTS; $attempt++) {
            try {
                return Task::query()->create($data);
            } catch (UniqueConstraintViolationException $exception) {
                if (! str_contains(strtolower($exception->getMessage()), 'task_uid')) {
                    throw $exception;
                }

                $lastException = $exception;
            }
        }

        throw new RuntimeException(
            'Unable to assign a unique UID to a new task after bounded retries.',
            previous: $lastException,
        );
    }

    private function createdEventChanges(Task $task): array
    {
        return collect($this->taskEventValues($task))
            ->map(fn ($value) => ['before' => null, 'after' => $value])
            ->all();
    }

    private function changedEventValues(array $before, array $after): array
    {
        $changes = [];

        foreach ($before as $field => $value) {
            if ($value !== $after[$field]) {
                $changes[$field] = [
                    'before' => $value,
                    'after' => $after[$field],
                ];
            }
        }

        return $changes;
    }

    private function taskEventValues(Task $task): array
    {
        return collect(self::TASK_EVENT_FIELDS)
            ->mapWithKeys(function (string $field) use ($task) {
                $value = $task->getAttribute($field);

                if ($value instanceof DateTimeInterface) {
                    $value = $value->format('Y-m-d');
                }

                if (in_array($field, ['project_id', 'assignee_id', 'progress'], true) && $value !== null) {
                    $value = (int) $value;
                }

                return [$field => $value];
            })
            ->all();
    }

    private function lifecycleEventValues(Task $task): array
    {
        return array_merge($this->taskEventValues($task), [
            'completed_at' => $task->completed_at?->format(DateTimeInterface::ATOM),
            'completed_by' => $task->completed_by === null ? null : (int) $task->completed_by,
        ]);
    }

    private function canonicalHistorySnapshot(Task $task): array
    {
        return array_merge($task->only([
            'project_id',
            'title',
            'description',
            'assignee_id',
            'created_by',
            'assigned_by',
            'priority',
            'status',
            'progress',
            'start_date',
            'due_date',
            'comments',
            'completed_at',
            'completed_by',
        ]), [
            'task_uid' => $task->task_uid,
            'original_task_id' => $task->original_task_id ?: $task->id,
        ]);
    }

    private function recordHistory(
        Task $task,
        string $action,
        array $changes,
        User $actor,
    ): void {
        TaskHistory::query()->create([
            'task_id' => $task->id,
            'project_id' => $task->project_id,
            'original_task_id' => $task->original_task_id
                ?: ($changes['original_task_id'] ?? null),
            'task_title' => $task->title,
            'user_id' => $actor->id,
            'action' => $action,
            'changes' => $changes,
        ]);
    }
}
