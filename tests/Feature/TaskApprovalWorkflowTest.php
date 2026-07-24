<?php

namespace Tests\Feature;

use App\Enums\TaskState;
use App\Http\Middleware\EnsureTaskCorrelationId;
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
use App\Services\TaskLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TaskApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Notification::fake();
    }

    public function test_assigned_reviewer_approves_and_completes_atomically_with_shared_event_context(): void
    {
        $f = $this->fixtures();
        $taskId = $f['task']->id;
        $taskUid = $f['task']->task_uid;
        $correlationId = 'approval-correlation';

        $this->actingAs($f['reviewer'])
            ->withHeader(EnsureTaskCorrelationId::HEADER, $correlationId)
            ->postJson(route('tasks.approve', $f['task']), ['approval_comment' => '  Approved cleanly.  '])
            ->assertOk()
            ->assertHeader(EnsureTaskCorrelationId::HEADER, $correlationId)
            ->assertJson(['success' => true, 'message' => 'Task approved and completed.']);

        $task = $f['task']->fresh();
        $approval = TaskApproval::query()->whereBelongsTo($task)->sole();
        $events = TaskEvent::query()->whereBelongsTo($task)->orderBy('sequence')->get();

        $this->assertSame($taskId, $task->id);
        $this->assertSame($taskUid, $task->task_uid);
        $this->assertSame(TaskState::Completed, $task->machineState());
        $this->assertSame(100, $task->progress);
        $this->assertSame($f['reviewer']->id, $task->approved_by);
        $this->assertSame($f['reviewer']->id, $task->completed_by);
        $this->assertNotNull($task->approved_at);
        $this->assertNotNull($task->completed_at);
        $this->assertSame('Approved cleanly.', $approval->approval_comment);
        $this->assertFalse($approval->is_override);
        $this->assertDatabaseCount('completed_tasks', 0);
        $this->assertSame(
            [TaskEventRecorder::APPROVED, TaskEventRecorder::COMPLETED],
            $events->pluck('event_type')->all(),
        );
        $this->assertSame([1, 2], $events->pluck('sequence')->all());
        $this->assertSame($f['reviewer']->id, $events[0]->actor_id);
        $this->assertSame($events[0]->actor_id, $events[1]->actor_id);
        $this->assertSame('web', $events[0]->source);
        $this->assertSame($events[0]->source, $events[1]->source);
        $this->assertSame($correlationId, $events[0]->correlation_id);
        $this->assertSame($events[0]->correlation_id, $events[1]->correlation_id);
        $this->assertTrue($events[0]->occurred_at->equalTo($events[1]->occurred_at));
        $this->assertSame(1, TaskHistory::query()->where('action', 'approved_completed')->count());
        $this->assertSame(
            $events[0]->sequence,
            $events[1]->metadata['approval_event_sequence'],
        );

        foreach ([$f['assignee'], $f['creator'], $f['reviewer']] as $recipient) {
            Notification::assertSentTo($recipient, TaskReviewWorkflowNotification::class);
        }
        Notification::assertSentTo($f['assignee'], TaskReviewWorkflowNotification::class, function ($notification) use ($f, $task, $approval) {
            $payload = $notification->toArray($f['assignee']);

            return $payload['task_id'] === $task->id
                && $payload['task_uid'] === $task->task_uid
                && $payload['current_state'] === TaskState::Completed->value
                && $payload['approver_id'] === $f['reviewer']->id
                && $payload['approval_comment_reference'] === "task_approvals:{$approval->id}"
                && ! array_key_exists('override_reason', $payload)
                && ! array_key_exists('priority', $payload);
        });
    }

    public function test_approval_resolves_the_active_revision_cycle_and_preserves_records(): void
    {
        $f = $this->fixtures();
        $cycle = TaskRevisionCycle::query()->create([
            'task_id' => $f['task']->id,
            'cycle_number' => 1,
            'requested_by' => $f['reviewer']->id,
            'requested_at' => now()->subDays(2),
            'formal_feedback' => 'Revise this.',
            'revision_due_date' => now()->addDay(),
            'started_at' => now()->subDay(),
            'resubmitted_at' => now()->subHours(2),
            'origin' => 'review_revision',
        ]);
        $f['task']->forceFill([
            'active_revision_cycle_id' => $cycle->id,
            'revision_due_date' => now()->addDay()->toDateString(),
        ])->save();
        TaskSubmission::query()->create([
            'task_id' => $f['task']->id,
            'revision_cycle_id' => $cycle->id,
            'submitted_by' => $f['assignee']->id,
            'submitted_at' => now()->subHour(),
            'submission_note' => 'Revision submitted.',
        ]);

        $this->actingAs($f['reviewer'])->postJson(route('tasks.approve', $f['task']))->assertOk();

        $task = $f['task']->fresh();
        $this->assertNull($task->active_revision_cycle_id);
        $this->assertNull($task->revision_due_date);
        $this->assertNotNull($cycle->fresh()->resolved_at);
        $this->assertDatabaseCount('task_revision_cycles', 1);
        $this->assertDatabaseCount('task_submissions', 2);
        $this->assertSame($cycle->id, TaskApproval::query()->sole()->revision_cycle_id);
    }

    public function test_normal_approval_enforces_reviewer_assignee_submission_and_current_eligibility(): void
    {
        $f = $this->fixtures();

        $this->actingAs($f['other_project_manager'])
            ->postJson(route('tasks.approve', $f['task']))
            ->assertForbidden();

        $f['reviewer']->update(['active' => false]);
        $this->actingAs($f['reviewer'])
            ->postJson(route('tasks.approve', $f['task']))
            ->assertForbidden();
        $f['reviewer']->update(['active' => true]);

        $f['project']->forceFill(['project_manager_id' => $f['other_project_manager']->id])->save();
        $this->actingAs($f['reviewer'])
            ->postJson(route('tasks.approve', $f['task']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reviewer_id');
        $f['project']->forceFill(['project_manager_id' => $f['reviewer']->id])->save();

        TaskSubmission::query()->where('task_id', $f['task']->id)->delete();
        $this->actingAs($f['reviewer'])->postJson(route('tasks.approve', $f['task']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('submission_id');

        $f['task']->forceFill(['assignee_id' => $f['reviewer']->id])->save();
        $this->postJson(route('tasks.approve', $f['task']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('approver_id');

        $this->assertSame(TaskState::InReview, $f['task']->fresh()->machineState());
        $this->assertDatabaseCount('task_approvals', 0);
        $this->assertDatabaseCount('task_events', 0);
        $this->assertDatabaseCount('task_histories', 0);
        Notification::assertNothingSent();
    }

    public function test_duplicate_approval_is_deterministic_and_side_effect_free(): void
    {
        $f = $this->fixtures();
        $this->actingAs($f['reviewer'])->postJson(route('tasks.approve', $f['task']))->assertOk();
        $counts = $this->approvalCounts();
        Notification::fake();

        $this->postJson(route('tasks.approve', $f['task']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('task');

        $this->assertSame($counts, $this->approvalCounts());
        Notification::assertNothingSent();
    }

    public function test_approval_rolls_back_all_state_and_dispatches_nothing_when_event_recording_fails(): void
    {
        $f = $this->fixtures();
        $this->mock(TaskEventRecorder::class)
            ->shouldReceive('record')
            ->once()
            ->andThrow(new \RuntimeException('event recording failed'));

        $this->actingAs($f['reviewer'])
            ->postJson(route('tasks.approve', $f['task']))
            ->assertServerError();

        $task = $f['task']->fresh();
        $this->assertSame(TaskState::InReview, $task->machineState());
        $this->assertSame(80, $task->progress);
        $this->assertNull($task->approved_at);
        $this->assertNull($task->completed_at);
        $this->assertDatabaseCount('task_approvals', 0);
        $this->assertDatabaseCount('task_histories', 0);
        $this->assertDatabaseCount('task_events', 0);
        Notification::assertNothingSent();
    }

    public function test_manager_override_is_separate_reasoned_audited_and_notifies_assigned_reviewer(): void
    {
        $f = $this->fixtures();

        $this->actingAs($f['creator'])
            ->postJson(route('tasks.approve.override', $f['task']), ['override_reason' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('override_reason');

        $this->actingAs($f['other_project_manager'])
            ->postJson(route('tasks.approve.override', $f['task']), ['override_reason' => 'Urgent release'])
            ->assertForbidden();

        $reason = 'Emergency customer recovery — internal only.';
        $this->actingAs($f['creator'])
            ->postJson(route('tasks.approve.override', $f['task']), [
                'override_reason' => "  {$reason}  ",
                'approval_comment' => 'Approved by management.',
            ])
            ->assertOk();

        $approval = TaskApproval::query()->sole();
        $approvedEvent = TaskEvent::query()->where('event_type', TaskEventRecorder::APPROVED)->sole();
        $this->assertTrue($approval->is_override);
        $this->assertSame($reason, $approval->override_reason);
        $this->assertSame($f['reviewer']->id, $approval->assigned_reviewer_id);
        $this->assertSame($f['creator']->id, $approval->approved_by);
        $this->assertSame("task_approvals:{$approval->id}", $approvedEvent->metadata['override_reason_reference']);
        $this->assertStringNotContainsString($reason, json_encode($approvedEvent->metadata, JSON_THROW_ON_ERROR));
        Notification::assertSentTo($f['reviewer'], TaskReviewWorkflowNotification::class, function ($notification) use ($f, $reason) {
            $payload = $notification->toArray($f['reviewer']);

            return $payload['approver_id'] === $f['creator']->id
                && ! str_contains(json_encode($payload, JSON_THROW_ON_ERROR), $reason);
        });
    }

    public function test_manager_override_fails_when_manager_is_the_assignee(): void
    {
        $f = $this->fixtures();
        $f['project']->members()->syncWithoutDetaching($f['creator']->id);
        $f['task']->forceFill(['assignee_id' => $f['creator']->id])->save();

        $this->actingAs($f['creator'])
            ->postJson(route('tasks.approve.override', $f['task']), ['override_reason' => 'Emergency'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('approver_id');

        $this->assertDatabaseCount('task_approvals', 0);
    }

    public function test_every_direct_completion_request_and_service_entry_point_is_retired(): void
    {
        $f = $this->fixtures(TaskState::InProgress);

        $this->actingAs($f['creator'])
            ->putJson(route('tasks.update', $f['task']), ['status' => 'Completed', 'progress' => 100])
            ->assertUnprocessable();

        $this->postJson(route('tasks.store'), [
            'title' => 'Bypass',
            'project_id' => $f['project']->id,
            'assignee_id' => $f['assignee']->id,
            'priority' => 'High',
            'status' => 'Completed',
            'progress' => 100,
        ])->assertUnprocessable();

        $this->postJson(route('tasks.complete', $f['task']))->assertGone();
        $this->postJson(route('tasks.bulk-complete'), ['task_ids' => [$f['task']->id]])->assertGone();
        try {
            app(TaskLifecycleService::class)->update($f['task'], ['progress' => 100], $f['creator']);
            $this->fail('Expected direct service progress completion to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('progress', $exception->errors());
        }
        $this->assertFalse(method_exists(TaskLifecycleService::class, 'complete'));
        $this->assertFalse(method_exists(TaskLifecycleService::class, 'completeMany'));
        $this->assertSame(TaskState::InProgress, $f['task']->fresh()->machineState());
        $this->assertDatabaseCount('task_events', 0);
        $this->assertDatabaseCount('task_histories', 0);
    }

    public function test_task_page_scopes_approval_and_override_controls_to_the_correct_actor(): void
    {
        $f = $this->fixtures();
        $approveUrl = route('tasks.approve', $f['task']);
        $overrideUrl = route('tasks.approve.override', $f['task']);
        $approveControl = 'data-transition="approve" data-url="'.$approveUrl.'"';
        $overrideControl = 'data-transition="override-approve" data-url="'.$overrideUrl.'"';

        $this->actingAs($f['reviewer'])->get(route('tasks'))
            ->assertOk()
            ->assertSee($approveControl, false)
            ->assertDontSee($overrideControl, false);
        $this->actingAs($f['assignee'])->get(route('tasks'))
            ->assertOk()
            ->assertDontSee($approveControl, false)
            ->assertDontSee($overrideControl, false);
        $this->actingAs($f['creator'])->get(route('tasks'))
            ->assertOk()
            ->assertDontSee($approveControl, false)
            ->assertSee($overrideControl, false);

        $f['project']->members()->syncWithoutDetaching($f['reviewer']->id);
        $f['task']->forceFill(['assignee_id' => $f['reviewer']->id])->save();
        $this->actingAs($f['reviewer'])->get(route('tasks'))
            ->assertOk()
            ->assertDontSee($approveControl, false);
    }

    private function approvalCounts(): array
    {
        return [
            TaskApproval::query()->count(),
            TaskHistory::query()->count(),
            TaskEvent::query()->count(),
        ];
    }

    private function fixtures(TaskState $state = TaskState::InReview): array
    {
        CarbonImmutable::setTestNow('2026-07-24 12:00:00');
        $creator = $this->user('manager');
        $reviewer = $this->user('project_manager');
        $otherProjectManager = $this->user('project_manager');
        $assignee = $this->user('team_member');
        $project = Project::factory()->create(['project_manager_id' => $reviewer->id]);
        $project->members()->attach($assignee->id);
        $task = Task::query()->create([
            'project_id' => $project->id,
            'title' => 'Approval workflow task',
            'assignee_id' => $assignee->id,
            'created_by' => $creator->id,
            'priority' => 'High',
            'status' => $state,
            'progress' => 80,
            'due_date' => now()->addDays(5)->toDateString(),
        ]);
        $task->forceFill([
            'reviewer_id' => $reviewer->id,
            'submitted_at' => now()->subHour(),
            'review_started_at' => now()->subMinutes(30),
            'review_due_date' => now()->addDays(2)->toDateString(),
        ])->save();

        if ($state === TaskState::InReview) {
            TaskSubmission::query()->create([
                'task_id' => $task->id,
                'submitted_by' => $assignee->id,
                'submitted_at' => now()->subHour(),
                'submission_note' => 'Ready.',
            ]);
        }

        return [
            'creator' => $creator,
            'reviewer' => $reviewer,
            'other_project_manager' => $otherProjectManager,
            'assignee' => $assignee,
            'project' => $project,
            'task' => $task->fresh(),
        ];
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('name', $role)->value('id'),
        ]);
    }
}
