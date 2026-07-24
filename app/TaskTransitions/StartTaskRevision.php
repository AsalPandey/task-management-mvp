<?php

namespace App\TaskTransitions;

use App\Contracts\TaskTransitionCommand;
use App\Enums\TaskState;
use App\Exceptions\TaskTransitionException;
use App\Models\Task;
use App\Models\TaskRevisionCycle;
use App\Models\User;
use App\Services\TaskEventRecorder;
use App\Services\TaskNotificationDispatcher;
use App\Services\TaskReviewEligibilityService;
use App\ValueObjects\TaskOperationContext;
use App\ValueObjects\TaskTransitionEffects;

final class StartTaskRevision implements TaskTransitionCommand
{
    private ?TaskRevisionCycle $cycle = null;

    public function __construct(
        private readonly TaskReviewEligibilityService $eligibility,
        private readonly TaskNotificationDispatcher $notifications,
    ) {}

    public function ability(): string
    {
        return 'startRevision';
    }

    public function validate(Task $task, User $actor): void
    {
        if ($task->machineState() !== TaskState::RevisionRequested) {
            throw TaskTransitionException::invalidState('Only a revision-requested task may begin revision.');
        }

        $this->eligibility->assertActiveAssignee($task, $actor);
        $this->cycle = $this->activeCycle($task);

        if ($this->cycle->started_at || $this->cycle->resolved_at) {
            throw TaskTransitionException::invalidState('The active revision cycle cannot be started.');
        }
    }

    public function apply(Task $task, User $actor, TaskOperationContext $context): TaskTransitionEffects
    {
        $cycle = $this->cycle ?? $this->activeCycle($task);
        $cycle->forceFill(['started_at' => $context->occurredAt])->save();
        $changes = [
            'status' => ['before' => TaskState::RevisionRequested->value, 'after' => TaskState::InProgress->value],
            'revision_started_at' => ['before' => null, 'after' => $context->occurredAt->toAtomString()],
        ];
        $task->forceFill(['status' => TaskState::InProgress])->save();

        return new TaskTransitionEffects(
            historyAction: 'revision_started',
            historyChanges: $changes,
            events: [[
                'type' => TaskEventRecorder::REVISION_STARTED,
                'changed_fields' => $changes,
                'metadata' => [
                    'revision_cycle_id' => (int) $cycle->id,
                    'cycle_number' => (int) $cycle->cycle_number,
                    'started_at' => $context->occurredAt->toAtomString(),
                ],
            ]],
            afterCommit: fn (Task $committedTask) => $this->notifications
                ->dispatchTaskRevisionStarted($committedTask, $actor),
        );
    }

    private function activeCycle(Task $task): TaskRevisionCycle
    {
        $cycle = TaskRevisionCycle::query()
            ->whereKey($task->active_revision_cycle_id)
            ->where('task_id', $task->id)
            ->whereNull('resolved_at')
            ->first();

        if (! $cycle) {
            throw TaskTransitionException::missingData('active_revision_cycle_id', 'An unresolved active revision cycle is required.');
        }

        return $cycle;
    }
}
