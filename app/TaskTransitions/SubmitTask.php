<?php

namespace App\TaskTransitions;

use App\Contracts\TaskTransitionCommand;
use App\Enums\TaskState;
use App\Exceptions\TaskTransitionException;
use App\Models\Task;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Services\TaskEventRecorder;
use App\Services\TaskNotificationDispatcher;
use App\Services\TaskReviewEligibilityService;
use App\ValueObjects\TaskOperationContext;
use App\ValueObjects\TaskTransitionEffects;
use Carbon\CarbonImmutable;

final class SubmitTask implements TaskTransitionCommand
{
    public function __construct(
        private readonly TaskReviewEligibilityService $eligibility,
        private readonly TaskNotificationDispatcher $notifications,
        private readonly ?string $submissionNote = null,
    ) {}

    public function ability(): string
    {
        return 'submit';
    }

    public function validate(Task $task, User $actor): void
    {
        if ($task->machineState() !== TaskState::InProgress) {
            throw TaskTransitionException::invalidState('Only an in-progress task may be submitted.');
        }

        $this->eligibility->assertAssigneeMaySubmit($task, $actor);
    }

    public function apply(Task $task, User $actor, TaskOperationContext $context): TaskTransitionEffects
    {
        $submittedAt = $context->occurredAt;
        $today = CarbonImmutable::instance($submittedAt)->setTimezone(config('app.timezone'))->startOfDay();
        $explicitDueDate = $task->review_due_date?->toImmutable()->startOfDay();
        $usesExplicitDate = $explicitDueDate !== null && $explicitDueDate->greaterThan($today);
        $reviewDueDate = $usesExplicitDate
            ? $explicitDueDate
            : $today->addDays((int) config('tasks.review_sla_days'));

        $submission = TaskSubmission::query()->create([
            'task_id' => $task->id,
            'revision_cycle_id' => null,
            'submitted_by' => $actor->id,
            'submitted_at' => $submittedAt,
            'submission_note' => $this->submissionNote,
        ]);

        $changes = [
            'status' => ['before' => TaskState::InProgress->value, 'after' => TaskState::Submitted->value],
            'submitted_at' => ['before' => null, 'after' => $submittedAt->toAtomString()],
            'review_due_date' => [
                'before' => $task->review_due_date?->toDateString(),
                'after' => $reviewDueDate->toDateString(),
            ],
        ];

        $task->forceFill([
            'status' => TaskState::Submitted,
            'submitted_at' => $submittedAt,
            'review_due_date' => $reviewDueDate,
        ])->save();

        return new TaskTransitionEffects(
            historyAction: 'submitted',
            historyChanges: $changes,
            events: [[
                'type' => TaskEventRecorder::SUBMITTED,
                'changed_fields' => $changes,
                'metadata' => [
                    'submission_id' => (int) $submission->id,
                    'assignee_id' => (int) $task->assignee_id,
                    'reviewer_id' => (int) $task->reviewer_id,
                    'submitted_at' => $submittedAt->toAtomString(),
                    'review_due_date' => $reviewDueDate->toDateString(),
                    'review_due_date_source' => $usesExplicitDate ? 'explicit' : 'sla',
                ],
            ]],
            afterCommit: fn (Task $committedTask) => $this->notifications->dispatchTaskSubmitted($committedTask, $actor),
            metadata: ['submission_id' => (int) $submission->id],
        );
    }
}
