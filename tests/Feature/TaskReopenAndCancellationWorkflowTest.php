<?php

namespace Tests\Feature;

use App\Enums\TaskState;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskApproval;
use App\Models\TaskEvent;
use App\Models\TaskHistory;
use App\Models\TaskRevisionCycle;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Notifications\TaskReviewWorkflowNotification;
use App\Services\TaskEventRecorder;
use App\Services\TaskReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TaskReopenAndCancellationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Notification::fake();
    }

    public function test_manager_reopens_approved_work_into_one_revision_cycle_atomically(): void
    {
        $f = $this->fixtures();
        $completed = $this->approveTask($f['task'], $f['manager']);
        $taskId = $completed->id;
        $taskUid = $completed->task_uid;
        $approvalId = TaskApproval::query()->where('task_id', $taskId)->sole()->id;
        Notification::fake();

        $response = $this->actingAs($f['manager'])->postJson(route('tasks.reopen', $completed), [
            'reopen_reason' => '  The approved result needs a corrected final section.  ',
            'revision_due_date' => now()->addDays(4)->toDateString(),
        ])->assertOk();

        $task = $completed->fresh();
        $cycle = TaskRevisionCycle::query()->where('task_id', $taskId)->sole();
        $this->assertSame($taskId, $task->id);
        $this->assertSame($taskUid, $task->task_uid);
        $this->assertSame(TaskState::RevisionRequested, $task->machineState());
        $this->assertSame(99, $task->progress);
        $this->assertSame(1, $task->revision_count);
        $this->assertSame($cycle->id, $task->active_revision_cycle_id);
        $this->assertSame('completed_reopen', $cycle->origin);
        $this->assertSame('The approved result needs a corrected final section.', $cycle->reopen_reason);
        $this->assertNull($task->approved_at);
        $this->assertNull($task->approved_by);
        $this->assertNull($task->completed_at);
        $this->assertNull($task->completed_by);
        $this->assertDatabaseHas('task_approvals', ['id' => $approvalId, 'task_id' => $taskId]);
        $this->assertSame(
            [TaskEventRecorder::REOPENED, TaskEventRecorder::REVISION_REQUESTED],
            TaskEvent::query()->where('task_id', $taskId)->latest('sequence')->limit(2)->get()->reverse()->pluck('event_type')->all(),
        );
        $this->assertDatabaseHas('task_histories', [
            'task_id' => $taskId,
            'action' => 'reopened_revision_requested',
            'user_id' => $f['manager']->id,
        ]);
        $response->assertJsonPath('task.id', $taskId)
            ->assertJsonPath('task.task_uid', $taskUid)
            ->assertJsonPath('task.status', 'Revision Requested');
        Notification::assertSentToTimes($f['assignee'], TaskReviewWorkflowNotification::class, 1);
        Notification::assertSentToTimes($f['reviewer'], TaskReviewWorkflowNotification::class, 1);
    }

    public function test_managing_project_manager_may_reopen_but_team_member_and_unrelated_manager_may_not(): void
    {
        $f = $this->fixtures();
        $completed = $this->approveTask($f['task'], $f['reviewer']);
        Notification::fake();
        $unrelated = $this->user('project_manager');
        $payload = [
            'reopen_reason' => 'Further work is required.',
            'revision_due_date' => now()->addDays(2)->toDateString(),
        ];

        $this->actingAs($f['assignee'])->postJson(route('tasks.reopen', $completed), $payload)->assertForbidden();
        $this->actingAs($unrelated)->postJson(route('tasks.reopen', $completed), $payload)->assertForbidden();
        $this->actingAs($f['reviewer'])->postJson(route('tasks.reopen', $completed), $payload)->assertOk();
    }

    public function test_reopen_requires_reason_future_deadline_and_valid_current_participants(): void
    {
        $f = $this->fixtures();
        $completed = $this->approveTask($f['task'], $f['manager']);

        $this->actingAs($f['manager'])->postJson(route('tasks.reopen', $completed), [
            'reopen_reason' => '   ',
            'revision_due_date' => now()->addDay()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('reopen_reason');
        $this->postJson(route('tasks.reopen', $completed), [
            'reopen_reason' => 'Valid reason',
            'revision_due_date' => today()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('revision_due_date');

        $f['assignee']->update(['active' => false]);
        $this->postJson(route('tasks.reopen', $completed), [
            'reopen_reason' => 'Valid reason',
            'revision_due_date' => now()->addDays(2)->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('assignee_id');
        $this->assertSame(TaskState::Completed, $completed->fresh()->machineState());
        $this->assertDatabaseCount('task_revision_cycles', 0);
    }

    public function test_duplicate_reopen_has_no_additional_side_effects(): void
    {
        $f = $this->fixtures();
        $completed = $this->approveTask($f['task'], $f['manager']);
        $payload = [
            'reopen_reason' => 'Further revision required.',
            'revision_due_date' => now()->addDays(2)->toDateString(),
        ];
        $this->actingAs($f['manager'])->postJson(route('tasks.reopen', $completed), $payload)->assertOk();
        $counts = $this->counts();
        Notification::fake();

        $this->postJson(route('tasks.reopen', $completed), $payload)->assertUnprocessable();

        $this->assertSame($counts, $this->counts());
        Notification::assertNothingSent();
    }

    public function test_manager_and_managing_project_manager_cancel_non_final_tasks(): void
    {
        foreach (['manager', 'reviewer'] as $actorKey) {
            $f = $this->fixtures();
            $task = $f['task'];
            $id = $task->id;
            $uid = $task->task_uid;

            $this->actingAs($f[$actorKey])->postJson(route('tasks.cancel', $task), [
                'cancellation_reason' => '  Work is no longer required.  ',
            ])->assertOk();

            $cancelled = $task->fresh();
            $this->assertSame($id, $cancelled->id);
            $this->assertSame($uid, $cancelled->task_uid);
            $this->assertSame(TaskState::Cancelled, $cancelled->machineState());
            $this->assertSame(40, $cancelled->progress);
            $this->assertSame('Work is no longer required.', $cancelled->cancellation_reason);
            $this->assertNotNull($cancelled->cancelled_at);
            $this->assertSame($f[$actorKey]->id, $cancelled->cancelled_by);
            $this->assertSame(1, TaskEvent::query()->where('task_id', $id)->where('event_type', TaskEventRecorder::CANCELLED)->count());
            Notification::assertSentToTimes($f['assignee'], TaskReviewWorkflowNotification::class, 1);
            Notification::fake();
        }
    }

    public function test_cancellation_rejects_team_member_completed_and_duplicate_attempts(): void
    {
        $f = $this->fixtures();
        $payload = ['cancellation_reason' => 'No longer required.'];

        $this->actingAs($f['assignee'])->postJson(route('tasks.cancel', $f['task']), $payload)->assertForbidden();
        $completed = $this->approveTask($f['task'], $f['manager']);
        $this->actingAs($f['manager'])->postJson(route('tasks.cancel', $completed), $payload)->assertUnprocessable();

        $active = $this->task($f);
        $this->postJson(route('tasks.cancel', $active), $payload)->assertOk();
        $counts = $this->counts();
        $this->postJson(route('tasks.cancel', $active), $payload)->assertUnprocessable();
        $this->assertSame($counts, $this->counts());
    }

    public function test_cancellation_resolves_active_cycle_and_preserves_workflow_records(): void
    {
        $f = $this->fixtures();
        $task = $f['task'];
        $submission = TaskSubmission::query()->create([
            'task_id' => $task->id,
            'submitted_by' => $f['assignee']->id,
            'submitted_at' => now()->subHour(),
        ]);
        $cycle = TaskRevisionCycle::query()->create([
            'task_id' => $task->id,
            'cycle_number' => 1,
            'requested_by' => $f['reviewer']->id,
            'requested_at' => now()->subMinutes(30),
            'revision_due_date' => now()->addDays(2),
            'origin' => 'review_revision',
        ]);
        $task->forceFill([
            'status' => TaskState::RevisionRequested,
            'revision_count' => 1,
            'active_revision_cycle_id' => $cycle->id,
            'revision_due_date' => now()->addDays(2),
        ])->save();

        $this->actingAs($f['manager'])->postJson(route('tasks.cancel', $task), [
            'cancellation_reason' => 'Project direction changed.',
        ])->assertOk();

        $this->assertNotNull($cycle->fresh()->resolved_at);
        $this->assertNull($task->fresh()->active_revision_cycle_id);
        $this->assertNull($task->fresh()->revision_due_date);
        $this->assertDatabaseHas('task_submissions', ['id' => $submission->id]);
        $this->assertDatabaseHas('task_revision_cycles', ['id' => $cycle->id]);
    }

    public function test_generic_update_cannot_reopen_cancel_or_write_finalization_metadata(): void
    {
        $f = $this->fixtures();
        $completed = $this->approveTask($f['task'], $f['manager']);

        $this->actingAs($f['manager'])->putJson(route('tasks.update', $completed), [
            'status' => 'In Progress',
            'progress' => 50,
        ])->assertUnprocessable();

        $active = $this->task($f);
        foreach ([
            ['status' => 'Cancelled'],
            ['cancellation_reason' => 'Bypass'],
            ['cancelled_at' => now()->toAtomString()],
            ['completed_at' => null],
            ['revision_due_date' => now()->addDays(2)->toDateString()],
        ] as $payload) {
            $this->putJson(route('tasks.update', $active), $payload)->assertUnprocessable();
        }
    }

    public function test_delete_is_limited_to_draft_like_tasks_without_meaningful_activity(): void
    {
        $f = $this->fixtures();
        $draft = $this->task($f, TaskState::NotStarted);
        $this->actingAs($f['manager'])->deleteJson(route('tasks.destroy', $draft))->assertOk();
        $this->assertSoftDeleted('tasks', ['id' => $draft->id]);

        $meaningful = $this->task($f, TaskState::NotStarted);
        DB::table('task_events')->insert([
            'event_uid' => strtolower((string) str()->ulid()),
            'task_id' => $meaningful->id,
            'sequence' => 1,
            'event_type' => TaskEventRecorder::STARTED,
            'source' => 'test',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->deleteJson(route('tasks.destroy', $meaningful))->assertUnprocessable();
        $this->assertNotSoftDeleted('tasks', ['id' => $meaningful->id]);
    }

    public function test_cancelled_tasks_leave_active_and_completed_queries_and_reopened_tasks_enter_revision_queue(): void
    {
        $f = $this->fixtures();
        $cancelled = $f['task'];
        $this->actingAs($f['manager'])->postJson(route('tasks.cancel', $cancelled), [
            'cancellation_reason' => 'Cancelled for reporting test.',
        ])->assertOk();
        $completed = $this->approveTask($this->task($f), $f['manager']);
        $this->postJson(route('tasks.reopen', $completed), [
            'reopen_reason' => 'Revision queue correction.',
            'revision_due_date' => now()->addDays(2)->toDateString(),
        ])->assertOk();

        $reads = app(TaskReadService::class);
        $this->assertFalse((clone $reads->activeVisibleTo($f['manager']))->whereKey($cancelled->id)->exists());
        $this->assertFalse((clone $reads->completedVisibleTo($f['manager']))->whereKey($cancelled->id)->exists());
        $this->assertFalse((clone $reads->completedVisibleTo($f['manager']))->whereKey($completed->id)->exists());
        $this->assertDatabaseHas('tasks', [
            'id' => $completed->id,
            'status' => TaskState::RevisionRequested->value,
        ]);
    }

    public function test_ui_exposes_only_state_and_role_appropriate_reopen_and_cancel_controls(): void
    {
        $f = $this->fixtures();

        $this->actingAs($f['manager'])
            ->get(route('tasks'))
            ->assertOk()
            ->assertSee('Cancel Task');

        $completed = $this->approveTask($f['task'], $f['manager']);
        $this->actingAs($f['manager'])
            ->get(route('completed-tasks'))
            ->assertOk()
            ->assertSee('Reopen for Revision');
        $this->actingAs($f['assignee'])
            ->get(route('completed-tasks'))
            ->assertOk()
            ->assertDontSee('class="btn-small btn-primary reopen-revision-btn"', false);

        $active = $this->task($f);
        $this->actingAs($f['manager'])->postJson(route('tasks.cancel', $active), [
            'cancellation_reason' => 'UI cancellation state.',
        ])->assertOk();
        $this->get(route('tasks'))
            ->assertOk()
            ->assertSee('Cancelled')
            ->assertDontSee('data-url="'.route('tasks.cancel', $active).'"', false);
    }

    private function fixtures(): array
    {
        $manager = $this->user('manager');
        $reviewer = $this->user('project_manager');
        $assignee = $this->user('team_member');
        $creator = $this->user('manager');
        $project = Project::factory()->create(['project_manager_id' => $reviewer->id]);
        $project->members()->attach($assignee->id);

        $fixtures = compact('manager', 'reviewer', 'assignee', 'creator', 'project');
        $fixtures['task'] = $this->task($fixtures);

        return $fixtures;
    }

    private function task(array $fixtures, TaskState $state = TaskState::InProgress): Task
    {
        return Task::query()->create([
            'project_id' => $fixtures['project']->id,
            'assignee_id' => $fixtures['assignee']->id,
            'reviewer_id' => $fixtures['reviewer']->id,
            'created_by' => $fixtures['creator']->id,
            'assigned_by' => $fixtures['manager']->id,
            'title' => 'Workflow finalization task',
            'priority' => 'Medium',
            'status' => $state,
            'progress' => $state === TaskState::NotStarted ? 0 : 40,
        ]);
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'active' => true,
            'role_id' => Role::query()->where('name', $role)->value('id'),
        ]);
    }

    private function counts(): array
    {
        return [
            Task::withTrashed()->count(),
            TaskRevisionCycle::query()->count(),
            TaskApproval::query()->count(),
            TaskHistory::query()->count(),
            TaskEvent::query()->count(),
        ];
    }
}
