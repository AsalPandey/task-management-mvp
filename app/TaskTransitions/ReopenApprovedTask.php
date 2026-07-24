<?php

namespace App\TaskTransitions;

use App\Contracts\TaskTransitionCommand;
use App\Enums\TaskState;
use App\Exceptions\TaskTransitionException;
use App\Models\Task;
use App\Models\TaskRevisionCycle;
use App\Models\User;
use App\Services\ReviewerEligibilityService;
use App\Services\TaskEventRecorder;
use App\Services\TaskNotificationDispatcher;
use App\ValueObjects\TaskOperationContext;
use App\ValueObjects\TaskTransitionEffects;
use Carbon\CarbonImmutable;

final class ReopenApprovedTask implements TaskTransitionCommand
{
    public function __construct(
        private readonly ReviewerEligibilityService $reviewers,
        private readonly TaskNotificationDispatcher $notifications,
        private readonly string $reopenReason,
        private readonly string $revisionDueDate,
    ) {}

    public function ability(): string
    {
        return 'reopen';
    }

    public function validate(Task $task, User $actor): void
    {
        if ($task->machineState() !== TaskState::Completed) {
            throw TaskTransitionException::invalidState('Only completed approved work may be reopened for revision.');
        }

        if (! $actor->isActive() || ! $this->controlsManagement($task, $actor)) {
            throw TaskTransitionException::invariant('actor_id', 'Only active authorized management may reopen approved work.');
        }

        if (trim($this->reopenReason) === '') {
            throw TaskTransitionException::missingData('reopen_reason', 'A reopen reason is required.');
        }

        $dueDate = $this->dueDate();
        if (! $dueDate->greaterThan(CarbonImmutable::now(config('app.timezone'))->startOfDay())) {
            throw TaskTransitionException::invariant('revision_due_date', 'The revision deadline must be in the future.');
        }

        if (! $task->project || ! $task->assignee || ! $task->assignee->isActive()) {
            throw TaskTransitionException::invariant('assignee_id', 'An active task assignee and project are required.');
        }

        if (! $task->project->members()->whereKey($task->assignee_id)->exists()) {
            throw TaskTransitionException::invariant('assignee_id', 'The assignee must still belong to the task project.');
        }

        if (! $task->reviewer) {
            throw TaskTransitionException::missingData('reviewer_id', 'An eligible reviewer is required.');
        }

        $this->reviewers->assertEligibleForTask($task->reviewer, $task);

        if (! $task->approval) {
            throw TaskTransitionException::missingData('approval_id', 'The completed task must retain an approval record.');
        }
    }

    public function apply(Task $task, User $actor, TaskOperationContext $context): TaskTransitionEffects
    {
        $dueDate = $this->dueDate();
        $cycleNumber = ((int) TaskRevisionCycle::query()
            ->where('task_id', $task->id)
            ->max('cycle_number')) + 1;
        $approval = $task->approval;
        $approvalReference = "task_approvals:{$approval->id}";

        $cycle = TaskRevisionCycle::query()->create([
            'task_id' => $task->id,
            'cycle_number' => $cycleNumber,
            'requested_by' => $actor->id,
            'requested_at' => $context->occurredAt,
            'revision_due_date' => $dueDate,
            'origin' => 'completed_reopen',
            'reopen_reason' => trim($this->reopenReason),
        ]);

        $changes = [
            'status' => ['before' => TaskState::Completed->value, 'after' => TaskState::RevisionRequested->value],
            'progress' => ['before' => (int) $task->progress, 'after' => min((int) $task->progress, 99)],
            'approved_at' => ['before' => $task->approved_at?->toAtomString(), 'after' => null],
            'approved_by' => ['before' => $task->approved_by, 'after' => null],
            'completed_at' => ['before' => $task->completed_at?->toAtomString(), 'after' => null],
            'completed_by' => ['before' => $task->completed_by, 'after' => null],
            'revision_count' => ['before' => (int) $task->revision_count, 'after' => (int) $task->revision_count + 1],
            'active_revision_cycle_id' => ['before' => $task->active_revision_cycle_id, 'after' => (int) $cycle->id],
            'revision_due_date' => ['before' => $task->revision_due_date?->toDateString(), 'after' => $dueDate->toDateString()],
        ];
        $priorCompletionTimestamp = $task->completed_at?->toAtomString();

        $task->forceFill([
            'status' => TaskState::RevisionRequested,
            'progress' => min((int) $task->progress, 99),
            'approved_at' => null,
            'approved_by' => null,
            'completed_at' => null,
            'completed_by' => null,
            'revision_count' => (int) $task->revision_count + 1,
            'active_revision_cycle_id' => $cycle->id,
            'revision_due_date' => $dueDate,
        ])->save();

        $cycleReference = "task_revision_cycles:{$cycle->id}";

        return new TaskTransitionEffects(
            historyAction: 'reopened_revision_requested',
            historyChanges: [
                'approval_reference' => $approvalReference,
                'reopen_reason_reference' => $cycleReference,
                'revision_cycle_id' => (int) $cycle->id,
                'changes' => $changes,
            ],
            events: [
                [
                    'type' => TaskEventRecorder::REOPENED,
                    'changed_fields' => collect($changes)
                        ->only(['approved_at', 'approved_by', 'completed_at', 'completed_by'])
                        ->all(),
                    'metadata' => [
                        'previous_state' => TaskState::Completed->value,
                        'prior_approval_reference' => $approvalReference,
                        'prior_completion_timestamp' => $priorCompletionTimestamp,
                        'revision_cycle_id' => (int) $cycle->id,
                        'reopen_reason_reference' => $cycleReference,
                    ],
                ],
                [
                    'type' => TaskEventRecorder::REVISION_REQUESTED,
                    'changed_fields' => collect($changes)
                        ->only(['status', 'progress', 'revision_count', 'active_revision_cycle_id', 'revision_due_date'])
                        ->all(),
                    'metadata' => [
                        'revision_cycle_id' => (int) $cycle->id,
                        'cycle_number' => $cycleNumber,
                        'revision_due_date' => $dueDate->toDateString(),
                        'state_before' => TaskState::Completed->value,
                        'state_after' => TaskState::RevisionRequested->value,
                    ],
                ],
            ],
            afterCommit: fn (Task $committedTask) => $this->notifications
                ->dispatchApprovedTaskReopened($committedTask, $actor),
            metadata: ['revision_cycle_id' => (int) $cycle->id],
        );
    }

    private function dueDate(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->revisionDueDate, config('app.timezone'))->startOfDay();
    }

    private function controlsManagement(Task $task, User $actor): bool
    {
        return $actor->hasRole('manager')
            || ($actor->hasRole('project_manager')
                && $task->project
                && (int) $task->project->project_manager_id === (int) $actor->id);
    }
}
