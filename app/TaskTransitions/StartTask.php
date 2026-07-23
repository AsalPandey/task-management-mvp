<?php

namespace App\TaskTransitions;

use App\Contracts\TaskTransitionCommand;
use App\Enums\TaskState;
use App\Exceptions\TaskTransitionException;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskEventRecorder;
use App\Services\TaskExecutionEligibilityService;
use App\Services\TaskNotificationDispatcher;
use App\ValueObjects\TaskOperationContext;
use App\ValueObjects\TaskTransitionEffects;

final class StartTask implements TaskTransitionCommand
{
    public function __construct(
        private readonly TaskExecutionEligibilityService $eligibility,
        private readonly TaskNotificationDispatcher $notifications,
    ) {}

    public function ability(): string
    {
        return 'start';
    }

    public function validate(Task $task, User $actor): void
    {
        if ($task->machineState() !== TaskState::NotStarted) {
            throw TaskTransitionException::invalidState('Only a not-started task may be started.');
        }

        $this->eligibility->assertEligible($task, $actor);
    }

    public function apply(Task $task, User $actor, TaskOperationContext $context): TaskTransitionEffects
    {
        $beforeStatus = $task->machineState()->value;
        $beforeStartedAt = $task->started_at?->toAtomString();
        $beforeProgress = (int) ($task->progress ?? 0);
        $progress = min((int) ($task->progress ?? 0), 99);

        $task->forceFill([
            'status' => TaskState::InProgress,
            'started_at' => $task->started_at ?? $context->occurredAt,
            'progress' => $progress,
        ])->save();

        $changes = [
            'status' => ['before' => $beforeStatus, 'after' => TaskState::InProgress->value],
            'started_at' => [
                'before' => $beforeStartedAt,
                'after' => $task->started_at?->toAtomString(),
            ],
            'assignee_id' => ['before' => (int) $task->assignee_id, 'after' => (int) $task->assignee_id],
        ];

        if ($beforeProgress !== $progress) {
            $changes['progress'] = ['before' => $beforeProgress, 'after' => $progress];
        }

        return new TaskTransitionEffects(
            historyAction: 'started',
            historyChanges: $changes,
            events: [[
                'type' => TaskEventRecorder::STARTED,
                'changed_fields' => $changes,
            ]],
            afterCommit: fn (Task $committedTask) => $this->notifications
                ->dispatchTaskStarted($committedTask, $actor),
        );
    }
}
