<?php

namespace Tests;

use App\Enums\TaskState;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Services\TaskTransitionExecutor;
use App\TaskTransitions\ApproveTask;
use App\TaskTransitions\OverrideApproveTask;
use App\TaskTransitions\ReopenApprovedTask;
use App\ValueObjects\TaskOperationContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function approveTask(
        Task $task,
        User $actor,
        ?TaskOperationContext $context = null,
    ): Task {
        $task->loadMissing('project');
        $reviewer = $task->reviewer;

        if (! $reviewer || ! $reviewer->isActive() || (int) $reviewer->id === (int) $task->assignee_id) {
            $reviewer = $task->project?->projectManager;
        }

        if (! $reviewer || ! $reviewer->isActive() || (int) $reviewer->id === (int) $task->assignee_id) {
            $reviewer = User::factory()->create([
                'role_id' => Role::query()->where('name', 'manager')->value('id'),
            ]);
        }

        $task->forceFill([
            'status' => TaskState::InReview,
            'reviewer_id' => $reviewer->id,
            'submitted_at' => $task->submitted_at ?? now()->subHour(),
            'review_started_at' => $task->review_started_at ?? now()->subMinutes(30),
        ])->save();

        TaskSubmission::query()->firstOrCreate(
            ['task_id' => $task->id, 'revision_cycle_id' => null],
            [
                'submitted_by' => $task->assignee_id ?? $actor->id,
                'submitted_at' => $task->submitted_at,
                'submission_note' => 'Test approval fixture.',
            ],
        );

        $command = (int) $actor->id === (int) $reviewer->id
            ? app(ApproveTask::class)
            : app()->make(OverrideApproveTask::class, ['overrideReason' => 'Test fixture approval.']);

        return app(TaskTransitionExecutor::class)
            ->execute($task, $actor, $command, $context)
            ->task;
    }

    protected function reopenApprovedTask(
        Task $task,
        User $actor,
        ?TaskOperationContext $context = null,
    ): Task {
        $command = app()->make(ReopenApprovedTask::class, [
            'reopenReason' => 'Approved result requires additional work.',
            'revisionDueDate' => now(config('app.timezone'))->addDays(3)->toDateString(),
        ]);

        return app(TaskTransitionExecutor::class)
            ->execute($task, $actor, $command, $context)
            ->task;
    }
}
