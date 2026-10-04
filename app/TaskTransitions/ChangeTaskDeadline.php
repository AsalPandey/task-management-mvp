<?php

namespace App\TaskTransitions;

use App\Contracts\TaskTransitionCommand;
use App\Exceptions\TaskTransitionException;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskEventRecorder;
use App\Services\TaskNotificationDispatcher;
use App\Support\InputContracts;
use App\Support\TaskDeadlineRules;
use App\ValueObjects\TaskOperationContext;
use App\ValueObjects\TaskTransitionEffects;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

final class ChangeTaskDeadline implements TaskTransitionCommand
{
    public function __construct(
        private readonly TaskNotificationDispatcher $notifications,
        private readonly string $deadlineType,
        private readonly string $dueDate,
        private readonly ?string $reason = null,
    ) {}

    public function ability(): string
    {
        return 'changeDeadline';
    }

    public function validate(Task $task, User $actor): void
    {
        Validator::make(['due_date' => $this->dueDate], ['due_date' => InputContracts::date('required')])->validate();
        if (! TaskDeadlineRules::supports($this->deadlineType)) {
            throw TaskTransitionException::invariant('deadline_type', 'The deadline type is invalid.');
        }

        if (! TaskDeadlineRules::allows($task, $this->deadlineType)) {
            throw TaskTransitionException::invalidState(
                ucfirst($this->deadlineType).' deadline changes are not valid in the current task state.',
            );
        }

        if ($this->deadlineType === 'revision' && ! $task->active_revision_cycle_id) {
            throw TaskTransitionException::missingData(
                'active_revision_cycle_id',
                'An active revision cycle is required.',
            );
        }

        if (! $this->parsedDueDate()->isAfter(today(config('app.timezone')))) {
            throw TaskTransitionException::invariant('due_date', 'The deadline must be in the future.');
        }

        if ($this->deadlineType === 'execution' && $task->start_date && $this->parsedDueDate()->toDateString() < $task->start_date->toDateString()) {
            throw TaskTransitionException::invariant('due_date', 'The execution deadline must be on or after the task start date.');
        }

        if (TaskDeadlineRules::reasonRequired($task, $this->deadlineType) && trim((string) $this->reason) === '') {
            throw TaskTransitionException::missingData(
                'reason',
                'A reason is required after this workflow period begins.',
            );
        }
    }

    public function apply(Task $task, User $actor, TaskOperationContext $context): TaskTransitionEffects
    {
        $field = match ($this->deadlineType) {
            'execution' => 'execution_due_date',
            'review' => 'review_due_date',
            'revision' => 'revision_due_date',
        };
        $dueDate = $this->parsedDueDate();
        $before = $task->getAttribute($field)?->toDateString();
        $reason = trim((string) $this->reason);
        $reasonReference = $reason === '' ? null : TaskTransitionEffects::HISTORY_REFERENCE;
        $changes = [
            $field => ['before' => $before, 'after' => $dueDate->toDateString()],
        ];

        $attributes = [$field => $dueDate];
        if ($this->deadlineType === 'execution') {
            $attributes['due_date'] = $dueDate;
            $changes['due_date'] = [
                'before' => $task->due_date?->toDateString(),
                'after' => $dueDate->toDateString(),
            ];
        }
        $task->forceFill($attributes)->save();

        if ($this->deadlineType === 'revision') {
            $task->activeRevisionCycle()
                ->whereNull('resolved_at')
                ->update(['revision_due_date' => $dueDate]);
        }

        return new TaskTransitionEffects(
            historyAction: 'deadline_changed',
            historyChanges: [
                'deadline_type' => $this->deadlineType,
                'reason' => $reason === '' ? null : $reason,
                'changes' => $changes,
            ],
            events: [[
                'type' => TaskEventRecorder::DEADLINE_CHANGED,
                'changed_fields' => $changes,
                'metadata' => [
                    'deadline_type' => $this->deadlineType,
                    'reason_reference' => $reasonReference,
                    'state' => $task->machineState()->value,
                ],
            ]],
            afterCommit: fn (Task $committedTask) => $this->notifications
                ->dispatchDeadlineChanged($committedTask, $actor, $this->deadlineType),
        );
    }

    private function parsedDueDate(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->dueDate, config('app.timezone'))->startOfDay();
    }
}
