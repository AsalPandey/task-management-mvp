<?php

namespace App\TaskTransitions;

use App\Enums\TaskState;
use App\Exceptions\TaskTransitionException;
use App\Models\Task;
use App\Models\TaskApproval;
use App\Models\TaskRevisionCycle;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Services\ReviewerEligibilityService;
use App\Services\TaskEventRecorder;
use App\Services\TaskNotificationDispatcher;
use App\ValueObjects\TaskOperationContext;
use App\ValueObjects\TaskTransitionEffects;

abstract class AbstractApproveTask
{
    public function __construct(
        protected readonly ReviewerEligibilityService $reviewers,
        protected readonly TaskNotificationDispatcher $notifications,
    ) {}

    protected function validateApproval(Task $task, User $actor): void
    {
        if ($task->machineState() !== TaskState::InReview) {
            throw TaskTransitionException::invalidState('Only a task in review may be approved.');
        }

        if ((int) $task->assignee_id === (int) $actor->id) {
            throw TaskTransitionException::invariant('approver_id', 'A task assignee may never approve their own task.');
        }

        if (! $task->project || ! $task->assignee || ! $task->assignee->isActive()) {
            throw TaskTransitionException::invariant('assignee_id', 'An active task assignee and project are required for approval.');
        }

        if (! $task->project->members()->whereKey($task->assignee_id)->exists()) {
            throw TaskTransitionException::invariant('assignee_id', 'The assignee must still belong to the task project.');
        }

        if (! $task->reviewer) {
            throw TaskTransitionException::missingData('reviewer_id', 'An assigned reviewer is required for approval.');
        }

        $this->reviewers->assertEligibleForTask($task->reviewer, $task);
        $submission = $this->latestSubmission($task);
        $cycle = $this->activeCycle($task);

        if ($cycle && (! $cycle->resubmitted_at || (int) $submission->revision_cycle_id !== (int) $cycle->id)) {
            throw TaskTransitionException::invariant(
                'active_revision_cycle_id',
                'The active revision cycle must have a latest linked resubmission before approval.',
            );
        }
    }

    protected function completeApprovedTask(
        Task $task,
        User $actor,
        TaskOperationContext $context,
        bool $isOverride,
        ?string $approvalComment,
        ?string $overrideReason,
    ): TaskTransitionEffects {
        $submission = $this->latestSubmission($task);
        $cycle = $this->activeCycle($task);
        $approvalEventSequence = ((int) $task->events()->max('sequence')) + 1;

        $approval = TaskApproval::query()->create([
            'task_id' => $task->id,
            'submission_id' => $submission->id,
            'revision_cycle_id' => $cycle?->id,
            'approved_by' => $actor->id,
            'assigned_reviewer_id' => $task->reviewer_id,
            'approved_at' => $context->occurredAt,
            'is_override' => $isOverride,
            'approval_comment' => $approvalComment,
            'override_reason' => $overrideReason,
        ]);

        $before = [
            'status' => $task->machineState()->value,
            'progress' => (int) $task->progress,
            'approved_at' => $task->approved_at?->toAtomString(),
            'approved_by' => $task->approved_by,
            'completed_at' => $task->completed_at?->toAtomString(),
            'completed_by' => $task->completed_by,
            'active_revision_cycle_id' => $task->active_revision_cycle_id,
            'revision_due_date' => $task->revision_due_date?->toDateString(),
        ];

        $cycle?->forceFill(['resolved_at' => $context->occurredAt])->save();
        $task->forceFill([
            'status' => TaskState::Completed,
            'progress' => 100,
            'approved_at' => $context->occurredAt,
            'approved_by' => $actor->id,
            'completed_at' => $context->occurredAt,
            'completed_by' => $actor->id,
            'active_revision_cycle_id' => null,
            'revision_due_date' => null,
        ])->save();

        $after = [
            'status' => TaskState::Completed->value,
            'progress' => 100,
            'approved_at' => $context->occurredAt->toAtomString(),
            'approved_by' => $actor->id,
            'completed_at' => $context->occurredAt->toAtomString(),
            'completed_by' => $actor->id,
            'active_revision_cycle_id' => null,
            'revision_due_date' => null,
        ];
        $changes = collect($after)
            ->filter(fn ($value, $field) => $before[$field] !== $value)
            ->mapWithKeys(fn ($value, $field) => [$field => ['before' => $before[$field], 'after' => $value]])
            ->all();
        $approvalReference = "task_approvals:{$approval->id}";

        return new TaskTransitionEffects(
            historyAction: 'approved_completed',
            historyChanges: [
                'approval_reference' => $approvalReference,
                'approver_id' => (int) $actor->id,
                'assigned_reviewer_id' => (int) $task->reviewer_id,
                'override' => $isOverride,
                'changes' => $changes,
            ],
            events: [
                [
                    'type' => TaskEventRecorder::APPROVED,
                    'changed_fields' => collect($changes)->only(['approved_at', 'approved_by'])->all(),
                    'metadata' => [
                        'approver_id' => (int) $actor->id,
                        'assigned_reviewer_id' => (int) $task->reviewer_id,
                        'submission_id' => (int) $submission->id,
                        'revision_cycle_id' => $cycle?->id,
                        'approval_reference' => $approvalReference,
                        'approval_comment_reference' => $approvalComment ? $approvalReference : null,
                        'override' => $isOverride,
                        'override_reason_reference' => $isOverride ? $approvalReference : null,
                        'approved_at' => $context->occurredAt->toAtomString(),
                    ],
                ],
                [
                    'type' => TaskEventRecorder::COMPLETED,
                    'changed_fields' => collect($changes)
                        ->only(['status', 'progress', 'completed_at', 'completed_by', 'active_revision_cycle_id', 'revision_due_date'])
                        ->all(),
                    'metadata' => [
                        'completed_by' => (int) $actor->id,
                        'completed_at' => $context->occurredAt->toAtomString(),
                        'approval_event_sequence' => $approvalEventSequence,
                        'approval_reference' => $approvalReference,
                    ],
                ],
            ],
            afterCommit: fn (Task $committedTask) => $this->notifications
                ->dispatchTaskApprovedAndCompleted($committedTask, $actor),
            metadata: ['approval_id' => (int) $approval->id],
        );
    }

    private function latestSubmission(Task $task): TaskSubmission
    {
        $submission = TaskSubmission::query()
            ->where('task_id', $task->id)
            ->latest('submitted_at')
            ->latest('id')
            ->first();

        if (! $submission) {
            throw TaskTransitionException::missingData('submission_id', 'At least one task submission is required for approval.');
        }

        return $submission;
    }

    private function activeCycle(Task $task): ?TaskRevisionCycle
    {
        if (! $task->active_revision_cycle_id) {
            return null;
        }

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
