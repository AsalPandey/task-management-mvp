<?php

namespace App\TaskTransitions;

use App\Contracts\TaskTransitionCommand;
use App\Enums\TaskState;
use App\Exceptions\TaskTransitionException;
use App\Models\Task;
use App\Models\TaskRevisionCycle;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Services\TaskEventRecorder;
use App\Services\TaskNotificationDispatcher;
use App\Services\TaskReviewEligibilityService;
use App\ValueObjects\TaskOperationContext;
use App\ValueObjects\TaskTransitionEffects;
use Carbon\CarbonImmutable;

final class ResubmitTask implements TaskTransitionCommand
{
    private ?TaskRevisionCycle $cycle = null;

    public function __construct(
        private readonly TaskReviewEligibilityService $eligibility,
        private readonly TaskNotificationDispatcher $notifications,
        private readonly ?string $submissionNote = null,
    ) {}

    public function ability(): string
    {
        return 'resubmit';
    }

    public function validate(Task $task, User $actor): void
    {
        if ($task->machineState() !== TaskState::InProgress) {
            throw TaskTransitionException::invalidState('Only an in-progress revision may be resubmitted.');
        }

        $this->eligibility->assertAssigneeMaySubmit($task, $actor);
        $this->cycle = $this->activeCycle($task);

        if (! $this->cycle->started_at || $this->cycle->resubmitted_at || $this->cycle->resolved_at) {
            throw TaskTransitionException::invalidState('The active revision cycle is not ready for resubmission.');
        }
    }

    public function apply(Task $task, User $actor, TaskOperationContext $context): TaskTransitionEffects
    {
        $cycle = $this->cycle ?? $this->activeCycle($task);
        $today = CarbonImmutable::instance($context->occurredAt)->setTimezone(config('app.timezone'))->startOfDay();
        $explicitDueDate = $task->review_due_date?->toImmutable()->startOfDay();
        $usesExplicitDate = $explicitDueDate !== null && $explicitDueDate->greaterThan($today);
        $reviewDueDate = $usesExplicitDate
            ? $explicitDueDate
            : $today->addDays((int) config('tasks.review_sla_days'));

        $submission = TaskSubmission::query()->create([
            'task_id' => $task->id,
            'revision_cycle_id' => $cycle->id,
            'submitted_by' => $actor->id,
            'submitted_at' => $context->occurredAt,
            'submission_note' => $this->submissionNote,
        ]);
        $cycle->forceFill(['resubmitted_at' => $context->occurredAt])->save();

        $changes = [
            'status' => ['before' => TaskState::InProgress->value, 'after' => TaskState::Submitted->value],
            'submitted_at' => ['before' => $task->submitted_at?->toAtomString(), 'after' => $context->occurredAt->toAtomString()],
            'review_due_date' => ['before' => $task->review_due_date?->toDateString(), 'after' => $reviewDueDate->toDateString()],
            'revision_due_date' => ['before' => $task->revision_due_date?->toDateString(), 'after' => null],
        ];
        $task->forceFill([
            'status' => TaskState::Submitted,
            'submitted_at' => $context->occurredAt,
            'review_due_date' => $reviewDueDate,
            'revision_due_date' => null,
        ])->save();

        return new TaskTransitionEffects(
            historyAction: 'resubmitted',
            historyChanges: $changes,
            events: [[
                'type' => TaskEventRecorder::RESUBMITTED,
                'changed_fields' => $changes,
                'metadata' => [
                    'submission_id' => (int) $submission->id,
                    'revision_cycle_id' => (int) $cycle->id,
                    'reviewer_id' => (int) $task->reviewer_id,
                    'review_due_date' => $reviewDueDate->toDateString(),
                    'review_due_date_source' => $usesExplicitDate ? 'explicit' : 'sla',
                ],
            ]],
            afterCommit: fn (Task $committedTask) => $this->notifications
                ->dispatchTaskResubmitted($committedTask, $actor),
            metadata: ['submission_id' => (int) $submission->id],
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
