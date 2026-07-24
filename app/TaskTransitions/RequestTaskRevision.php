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
use Carbon\CarbonImmutable;

final class RequestTaskRevision implements TaskTransitionCommand
{
    public function __construct(
        private readonly TaskReviewEligibilityService $eligibility,
        private readonly TaskNotificationDispatcher $notifications,
        private readonly string $formalFeedback,
        private readonly string $revisionDueDate,
    ) {}

    public function ability(): string
    {
        return 'requestRevision';
    }

    public function validate(Task $task, User $actor): void
    {
        if ($task->machineState() !== TaskState::InReview) {
            throw TaskTransitionException::invalidState('Only a task in review may have a revision requested.');
        }

        $this->eligibility->assertReviewerMayStart($task, $actor);

        if (trim($this->formalFeedback) === '') {
            throw TaskTransitionException::missingData('formal_feedback', 'Formal feedback is required.');
        }

        $dueDate = CarbonImmutable::parse($this->revisionDueDate, config('app.timezone'))->startOfDay();
        if (! $dueDate->greaterThan(CarbonImmutable::now(config('app.timezone'))->startOfDay())) {
            throw TaskTransitionException::invariant('revision_due_date', 'The revision deadline must be in the future.');
        }

        $existingCycle = $this->unresolvedActiveCycle($task);
        if ($existingCycle && ! $existingCycle->resubmitted_at) {
            throw TaskTransitionException::invalidState('The current revision cycle must be resubmitted before another revision is requested.');
        }
    }

    public function apply(Task $task, User $actor, TaskOperationContext $context): TaskTransitionEffects
    {
        $previousCycle = $this->unresolvedActiveCycle($task);
        $previousCycle?->forceFill(['resolved_at' => $context->occurredAt])->save();

        $cycleNumber = ((int) TaskRevisionCycle::query()
            ->where('task_id', $task->id)
            ->max('cycle_number')) + 1;
        $dueDate = CarbonImmutable::parse($this->revisionDueDate, config('app.timezone'))->startOfDay();

        $cycle = TaskRevisionCycle::query()->create([
            'task_id' => $task->id,
            'cycle_number' => $cycleNumber,
            'requested_by' => $actor->id,
            'requested_at' => $context->occurredAt,
            'formal_feedback' => trim($this->formalFeedback),
            'revision_due_date' => $dueDate,
            'origin' => 'review_revision',
        ]);

        $changes = [
            'status' => ['before' => TaskState::InReview->value, 'after' => TaskState::RevisionRequested->value],
            'revision_count' => ['before' => (int) $task->revision_count, 'after' => (int) $task->revision_count + 1],
            'active_revision_cycle_id' => ['before' => $task->active_revision_cycle_id, 'after' => (int) $cycle->id],
            'revision_due_date' => ['before' => $task->revision_due_date?->toDateString(), 'after' => $dueDate->toDateString()],
        ];

        $task->forceFill([
            'status' => TaskState::RevisionRequested,
            'revision_count' => (int) $task->revision_count + 1,
            'active_revision_cycle_id' => $cycle->id,
            'revision_due_date' => $dueDate,
        ])->save();

        return new TaskTransitionEffects(
            historyAction: 'revision_requested',
            historyChanges: $changes,
            events: [
                [
                    'type' => TaskEventRecorder::FEEDBACK_ADDED,
                    'changed_fields' => [],
                    'metadata' => [
                        'revision_cycle_id' => (int) $cycle->id,
                        'reviewer_id' => (int) $actor->id,
                        'feedback_reference' => "task_revision_cycles:{$cycle->id}",
                    ],
                ],
                [
                    'type' => TaskEventRecorder::REVISION_REQUESTED,
                    'changed_fields' => $changes,
                    'metadata' => [
                        'revision_cycle_id' => (int) $cycle->id,
                        'cycle_number' => $cycleNumber,
                        'revision_due_date' => $dueDate->toDateString(),
                        'resolved_revision_cycle_id' => $previousCycle?->id,
                    ],
                ],
            ],
            afterCommit: fn (Task $committedTask) => $this->notifications
                ->dispatchTaskRevisionRequested($committedTask, $actor),
            metadata: ['revision_cycle_id' => (int) $cycle->id],
        );
    }

    private function unresolvedActiveCycle(Task $task): ?TaskRevisionCycle
    {
        if (! $task->active_revision_cycle_id) {
            return null;
        }

        return TaskRevisionCycle::query()
            ->whereKey($task->active_revision_cycle_id)
            ->where('task_id', $task->id)
            ->whereNull('resolved_at')
            ->first();
    }
}
