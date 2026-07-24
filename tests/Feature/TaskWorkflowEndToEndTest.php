<?php

namespace Tests\Feature;

use App\Enums\TaskState;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TaskWorkflowEndToEndTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Notification::fake();
    }

    public function test_approval_path_preserves_identity_and_ordered_side_effects(): void
    {
        $f = $this->fixtures();
        $task = $this->task($f);
        [$id, $uid] = [$task->id, $task->task_uid];

        $this->startAndSubmit($f, $task);
        $this->actingAs($f['reviewer'])->postJson(route('tasks.review.start', $task))->assertOk();
        $this->postJson(route('tasks.approve', $task), ['approval_comment' => 'Accepted.'])->assertOk();

        $task->refresh();
        $this->assertSame($id, $task->id);
        $this->assertSame($uid, $task->task_uid);
        $this->assertSame(TaskState::Completed, $task->machineState());
        $this->assertSame([
            TaskEventRecorder::STARTED,
            TaskEventRecorder::SUBMITTED,
            TaskEventRecorder::REVIEW_STARTED,
            TaskEventRecorder::APPROVED,
            TaskEventRecorder::COMPLETED,
        ], $task->events()->pluck('event_type')->all());
        $this->assertDatabaseCount('task_submissions', 1);
        $this->assertDatabaseCount('task_approvals', 1);
        $this->assertDatabaseCount('completed_tasks', 0);
    }

    public function test_revision_path_preserves_identity_records_and_event_order(): void
    {
        $f = $this->fixtures();
        $task = $this->task($f);
        $uid = $task->task_uid;

        $this->startAndSubmit($f, $task);
        $this->actingAs($f['reviewer'])->postJson(route('tasks.review.start', $task))->assertOk();
        $this->postJson(route('tasks.revision.request', $task), [
            'formal_feedback' => 'Correct the calculations.',
            'revision_due_date' => now()->addDays(3)->toDateString(),
        ])->assertOk();
        $this->actingAs($f['assignee'])->postJson(route('tasks.revision.start', $task))->assertOk();
        $this->postJson(route('tasks.resubmit', $task), ['submission_note' => 'Corrected.'])->assertOk();
        $this->actingAs($f['reviewer'])->postJson(route('tasks.review.start', $task))->assertOk();
        $this->postJson(route('tasks.approve', $task))->assertOk();

        $task->refresh();
        $this->assertSame($uid, $task->task_uid);
        $this->assertSame(TaskState::Completed, $task->machineState());
        $this->assertSame([
            TaskEventRecorder::STARTED,
            TaskEventRecorder::SUBMITTED,
            TaskEventRecorder::REVIEW_STARTED,
            TaskEventRecorder::FEEDBACK_ADDED,
            TaskEventRecorder::REVISION_REQUESTED,
            TaskEventRecorder::REVISION_STARTED,
            TaskEventRecorder::RESUBMITTED,
            TaskEventRecorder::REVIEW_STARTED,
            TaskEventRecorder::APPROVED,
            TaskEventRecorder::COMPLETED,
        ], $task->events()->pluck('event_type')->all());
        $this->assertDatabaseCount('task_revision_cycles', 1);
        $this->assertDatabaseCount('task_submissions', 2);
        $this->assertDatabaseCount('task_approvals', 1);
    }

    public function test_hold_path_reaches_submission_without_duplicate_effects(): void
    {
        $f = $this->fixtures();
        $task = $this->task($f);

        $this->actingAs($f['assignee'])->postJson(route('tasks.start', $task))->assertOk();
        $this->postJson(route('tasks.hold', $task), ['reason' => 'Waiting for access.'])->assertOk();
        $this->postJson(route('tasks.resume', $task))->assertOk();
        $this->postJson(route('tasks.submit', $task))->assertOk();

        $this->assertSame(TaskState::Submitted, $task->fresh()->machineState());
        $this->assertSame([
            TaskEventRecorder::STARTED,
            TaskEventRecorder::HELD,
            TaskEventRecorder::RESUMED,
            TaskEventRecorder::SUBMITTED,
        ], $task->events()->pluck('event_type')->all());
    }

    public function test_reopen_path_reuses_same_row_through_second_approval(): void
    {
        $f = $this->fixtures();
        $task = $this->task($f);
        [$id, $uid] = [$task->id, $task->task_uid];

        $this->startAndSubmit($f, $task);
        $this->actingAs($f['reviewer'])->postJson(route('tasks.review.start', $task))->assertOk();
        $this->postJson(route('tasks.approve', $task))->assertOk();
        $this->actingAs($f['manager'])->postJson(route('tasks.reopen', $task), [
            'reopen_reason' => 'One more correction is required.',
            'revision_due_date' => now()->addDays(4)->toDateString(),
        ])->assertOk();
        $this->actingAs($f['assignee'])->postJson(route('tasks.revision.start', $task))->assertOk();
        $this->postJson(route('tasks.resubmit', $task))->assertOk();
        $this->actingAs($f['reviewer'])->postJson(route('tasks.review.start', $task))->assertOk();
        $this->postJson(route('tasks.approve', $task))->assertOk();

        $task->refresh();
        $this->assertSame($id, $task->id);
        $this->assertSame($uid, $task->task_uid);
        $this->assertSame(TaskState::Completed, $task->machineState());
        $this->assertSame(2, $task->approvals()->count());
        $this->assertSame(2, $task->submissions()->count());
        $this->assertSame(1, $task->events()->where('event_type', TaskEventRecorder::REOPENED)->count());
    }

    public function test_cancellation_succeeds_once_from_every_permitted_non_final_state(): void
    {
        $f = $this->fixtures();
        $states = [
            TaskState::NotStarted,
            TaskState::InProgress,
            TaskState::OnHold,
            TaskState::Submitted,
            TaskState::InReview,
            TaskState::RevisionRequested,
        ];

        foreach ($states as $state) {
            $task = $this->task($f, $state, ['title' => 'Cancel '.$state->value]);
            $id = $task->id;
            $uid = $task->task_uid;

            $this->actingAs($f['manager'])
                ->postJson(route('tasks.cancel', $task), [
                    'cancellation_reason' => 'No longer required.',
                ])
                ->assertOk();
            $this->postJson(route('tasks.cancel', $task), [
                'cancellation_reason' => 'Duplicate attempt.',
            ])->assertUnprocessable();

            $task->refresh();
            $this->assertSame($id, $task->id);
            $this->assertSame($uid, $task->task_uid);
            $this->assertSame(TaskState::Cancelled, $task->machineState());
            $this->assertSame(1, $task->events()->where('event_type', TaskEventRecorder::CANCELLED)->count());
        }
    }

    private function startAndSubmit(array $fixtures, Task $task): void
    {
        $this->actingAs($fixtures['assignee'])->postJson(route('tasks.start', $task))->assertOk();
        $this->postJson(route('tasks.submit', $task), ['submission_note' => 'Ready.'])->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function fixtures(): array
    {
        $manager = $this->user('manager');
        $reviewer = $this->user('project_manager');
        $assignee = $this->user('team_member');
        $project = Project::factory()->create(['project_manager_id' => $reviewer->id]);
        $project->members()->attach($assignee->id);

        return compact('manager', 'reviewer', 'assignee', 'project');
    }

    private function task(array $fixtures, TaskState $state = TaskState::NotStarted, array $attributes = []): Task
    {
        $task = Task::query()->create(array_merge([
            'project_id' => $fixtures['project']->id,
            'title' => 'End-to-end workflow task',
            'assignee_id' => $fixtures['assignee']->id,
            'created_by' => $fixtures['manager']->id,
            'priority' => 'High',
            'status' => $state,
            'progress' => 75,
            'due_date' => now()->addDays(5),
        ], $attributes));
        $task->forceFill([
            'reviewer_id' => $fixtures['reviewer']->id,
            'execution_due_date' => now()->addDays(5),
        ])->save();

        return $task;
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('name', $role)->value('id'),
        ]);
    }
}
