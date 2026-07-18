<?php

namespace App\Services;

use App\Models\CompletedTask;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskHistory;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskCompletedNotification;
use App\Notifications\TaskOverdueNotification;
use App\Notifications\TaskRevertedNotification;
use App\Notifications\TaskUpdatedNotification;
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
        'priority',
        'status',
        'progress',
        'start_date',
        'due_date',
        'comments',
    ];

    private const MAX_TASK_UID_ATTEMPTS = 5;

    public function __construct(private readonly TaskEventRecorder $eventRecorder) {}

    public function create(array $data, User $actor, ?TaskOperationContext $context = null): Task|CompletedTask
    {
        $context ??= TaskOperationContext::system($actor->id);

        return DB::transaction(function () use ($data, $actor, $context) {
            $data['created_by'] = $actor->id;
            $data['assigned_by'] = $actor->id;
            $project = $this->assertProjectAccess($data['project_id'], $actor);
            $this->assertProjectAcceptsNewTasks($project);
            $this->assertAssigneeIsProjectMember($data['project_id'], $data['assignee_id'] ?? null);
            $this->assertCompletionState($data);

            $task = $this->createTaskWithUidRetry($data);
            $this->recordHistory($task, 'created', $data, $actor);
            $this->eventRecorder->record(
                $task,
                TaskEventRecorder::CREATED,
                $context,
                $this->createdEventChanges($task),
            );

            if ($task->assignee) {
                $task->assignee->notify(new TaskAssignedNotification($task, $actor));
            }

            if ($task->status === 'Completed') {
                return $this->complete($task, $actor, 'completed_on_create');
            }

            return $task->load(['project', 'assignee']);
        });
    }

    public function update(Task $task, array $data, User $actor, ?TaskOperationContext $context = null): Task|CompletedTask
    {
        $context ??= TaskOperationContext::system($actor->id);

        return DB::transaction(function () use ($task, $data, $actor, $context) {
            $task->load(['project', 'assignee']);
            $old = $task->toArray();
            $beforeEventValues = $this->taskEventValues($task);

            if ($actor->hasRole('team_member')) {
                $data = array_intersect_key($data, array_flip(['status', 'progress', 'comments']));
            }

            $merged = array_merge($task->only([
                'project_id',
                'title',
                'description',
                'assignee_id',
                'priority',
                'status',
                'progress',
                'start_date',
                'due_date',
                'comments',
            ]), $data);

            $project = $this->assertProjectAccess((int) $merged['project_id'], $actor);
            if ((int) $merged['project_id'] !== (int) $task->project_id) {
                $this->assertProjectAcceptsNewTasks($project);
            }
            $this->assertAssigneeIsProjectMember((int) $merged['project_id'], $merged['assignee_id'] ?? null);
            $this->assertCompletionState($merged);

            if (($merged['assignee_id'] ?? null) && (int) $merged['assignee_id'] !== (int) $task->assignee_id) {
                $merged['assigned_by'] = $actor->id;
            }

            if (($merged['status'] ?? null) === 'Completed') {
                $task->fill($merged);

                return $this->complete($task, $actor);
            }

            $task->update($merged);
            $task->refresh()->load(['project', 'assignee']);
            $this->recordHistory($task, 'updated', ['old' => $old, 'new' => $merged], $actor);
            $changedFields = $this->updatedEventChanges($beforeEventValues, $this->taskEventValues($task));

            if ($changedFields !== []) {
                $this->eventRecorder->record(
                    $task,
                    TaskEventRecorder::UPDATED,
                    $context,
                    $changedFields,
                );
            }

            if ($task->assignee && (int) $task->assignee_id !== (int) $actor->id) {
                $task->assignee->notify(new TaskUpdatedNotification($task, $merged, $actor));
            }

            if ($task->due_date && $task->due_date->isPast() && $task->status !== 'Completed' && ! $task->overdue_notification_sent_at) {
                $task->assignee?->notify(new TaskOverdueNotification($task));
                $task->forceFill(['overdue_notification_sent_at' => now()])->save();
            }

            return $task;
        });
    }

    public function delete(Task $task, User $actor, string $action = 'deleted'): void
    {
        DB::transaction(function () use ($task, $actor, $action) {
            $this->recordHistory($task, $action, $task->toArray(), $actor);
            $task->delete();
        });
    }

    public function complete(Task $task, User $actor, string $action = 'completed'): CompletedTask
    {
        Gate::forUser($actor)->authorize('update', $task);

        $this->assertCompletionState([
            'status' => 'Completed',
            'progress' => $task->progress,
        ]);

        $taskData = collect($task->toArray())
            ->only((new CompletedTask)->getFillable())
            ->toArray();

        $taskData['original_task_id'] = $task->original_task_id ?: $task->id;
        $taskData['status'] = 'Completed';
        $taskData['progress'] = 100;
        $taskData['completed_at'] = now();
        $taskData['completed_by'] = $actor->id;
        unset($taskData['id'], $taskData['deleted_at'], $taskData['created_at'], $taskData['updated_at']);

        $completed = CompletedTask::query()->create($taskData);
        $this->recordHistory($task, $action, $taskData, $actor);

        if ($task->assignee) {
            $task->assignee->notify(new TaskCompletedNotification($completed, $actor, true));
        }

        $task->delete();

        return $completed->load(['project', 'assignee']);
    }

    public function revert(CompletedTask $completedTask, User $actor): Task
    {
        Gate::forUser($actor)->authorize('revert', $completedTask);

        return DB::transaction(function () use ($completedTask, $actor) {
            $this->assertProjectAccess((int) $completedTask->project_id, $actor);

            if ($completedTask->reverted) {
                throw ValidationException::withMessages([
                    'task' => 'Task has already been reverted.',
                ]);
            }

            $existsQuery = Task::query();

            if ($completedTask->original_task_id) {
                $existsQuery->where('original_task_id', $completedTask->original_task_id);
            } else {
                $existsQuery->where(function ($query) use ($completedTask) {
                    $query->where('title', $completedTask->title)
                        ->where('assignee_id', $completedTask->assignee_id)
                        ->where('project_id', $completedTask->project_id);
                });
            }

            $exists = $existsQuery->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'task' => 'Task is already active.',
                ]);
            }

            $taskData = collect($completedTask->toArray())
                ->only((new Task)->getFillable())
                ->toArray();
            $taskData['original_task_id'] = $completedTask->original_task_id ?: $completedTask->id;
            $taskData['status'] = 'In Progress';
            $taskData['progress'] = min((int) ($completedTask->progress ?? 0), 99);
            unset(
                $taskData['id'],
                $taskData['completed_at'],
                $taskData['completed_by'],
                $taskData['reverted'],
                $taskData['reverted_by'],
                $taskData['reverted_at'],
                $taskData['deleted_at'],
                $taskData['created_at'],
                $taskData['updated_at'],
            );

            $task = $this->createTaskWithUidRetry($taskData);
            $this->recordHistory($task, 'reverted', $taskData, $actor, $completedTask);

            $completedTask->forceFill([
                'reverted' => true,
                'reverted_by' => $actor->id,
                'reverted_at' => now(),
            ])->save();

            if ($task->assignee) {
                $task->assignee->notify(new TaskRevertedNotification($task, $actor));
            }

            return $task->load(['project', 'assignee']);
        });
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

    private function assertCompletionState(array $data): void
    {
        if (($data['status'] ?? null) === 'Completed' && (int) ($data['progress'] ?? 0) < 100) {
            throw ValidationException::withMessages([
                'progress' => 'Progress must be 100% to mark a task as completed.',
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

    private function updatedEventChanges(array $before, array $after): array
    {
        $changes = [];

        foreach (self::TASK_EVENT_FIELDS as $field) {
            if ($before[$field] !== $after[$field]) {
                $changes[$field] = [
                    'before' => $before[$field],
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

    private function recordHistory(Task $task, string $action, array $changes, User $actor, ?CompletedTask $completedTask = null): void
    {
        TaskHistory::query()->create([
            'task_id' => $task->id,
            'completed_task_id' => $completedTask?->id,
            'project_id' => $task->project_id,
            'original_task_id' => $task->original_task_id ?: ($changes['original_task_id'] ?? $completedTask?->original_task_id),
            'task_title' => $task->title,
            'user_id' => $actor->id,
            'action' => $action,
            'changes' => $changes,
        ]);
    }
}
