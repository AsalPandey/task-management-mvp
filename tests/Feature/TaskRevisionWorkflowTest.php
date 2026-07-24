<?php

namespace Tests\Feature;

use App\Enums\TaskState;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\TaskHistory;
use App\Models\TaskRevisionCycle;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Notifications\TaskReviewWorkflowNotification;
use App\Services\TaskEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

class TaskRevisionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Notification::fake();
    }

    public function test_reviewer_requests_one_revision_with_cycle_feedback_events_and_notifications(): void
    {
        $f = $this->fixtures();
        $dueDate = now()->addDays(4)->toDateString();

        $this->actingAs($f['reviewer'])
            ->postJson(route('tasks.revision.request', $f['task']), [
                'formal_feedback' => '  Correct the totals and cite the source.  ',
                'revision_due_date' => $dueDate,
            ])
            ->assertOk()
            ->assertJson(['message' => 'Revision requested.']);

        $task = $f['task']->fresh();
        $cycle = TaskRevisionCycle::query()->where('task_id', $task->id)->sole();
        $this->assertSame(TaskState::RevisionRequested, $task->machineState());
        $this->assertSame(1, $task->revision_count);
        $this->assertSame($cycle->id, $task->active_revision_cycle_id);
        $this->assertSame(1, $cycle->cycle_number);
        $this->assertSame('Correct the totals and cite the source.', $cycle->formal_feedback);
        $this->assertSame($dueDate, $cycle->revision_due_date->toDateString());
        $this->assertSame('review_revision', $cycle->origin);

        $events = TaskEvent::query()->where('task_id', $task->id)->orderBy('sequence')->get();
        $this->assertSame(
            [TaskEventRecorder::FEEDBACK_ADDED, TaskEventRecorder::REVISION_REQUESTED],
            $events->pluck('event_type')->all(),
        );
        $this->assertArrayNotHasKey('formal_feedback', $events[0]->metadata);
        $this->assertSame("task_revision_cycles:{$cycle->id}", $events[0]->metadata['feedback_reference']);
        $this->assertSame(1, TaskHistory::query()->where('action', 'revision_requested')->count());

        Notification::assertSentTo($f['assignee'], TaskReviewWorkflowNotification::class);
        Notification::assertSentTo($f['creator'], TaskReviewWorkflowNotification::class);

        Notification::fake();
        $this->postJson(route('tasks.revision.request', $task), [
            'formal_feedback' => 'Duplicate',
            'revision_due_date' => $dueDate,
        ])->assertStatus(422);
        $this->assertDatabaseCount('task_revision_cycles', 1);
        $this->assertDatabaseCount('task_events', 2);
        $this->assertDatabaseCount('task_histories', 1);
        Notification::assertNothingSent();
    }

    public function test_revision_request_requires_assigned_eligible_reviewer_feedback_and_future_deadline(): void
    {
        $f = $this->fixtures();

        $this->actingAs($f['other_reviewer'])
            ->postJson(route('tasks.revision.request', $f['task']), [
                'formal_feedback' => 'Feedback',
                'revision_due_date' => now()->addDay()->toDateString(),
            ])->assertForbidden();

        foreach ([
            ['revision_due_date' => now()->addDay()->toDateString()],
            ['formal_feedback' => 'Feedback'],
            ['formal_feedback' => 'Feedback', 'revision_due_date' => now()->subDay()->toDateString()],
        ] as $payload) {
            $this->actingAs($f['reviewer'])
                ->postJson(route('tasks.revision.request', $f['task']), $payload)
                ->assertStatus(422);
        }

        $f['task']->forceFill(['reviewer_id' => $f['assignee']->id])->save();
        $this->actingAs($f['assignee'])
            ->postJson(route('tasks.revision.request', $f['task']), [
                'formal_feedback' => 'Feedback',
                'revision_due_date' => now()->addDay()->toDateString(),
            ])->assertForbidden();

        $this->assertDatabaseCount('task_revision_cycles', 0);
    }

    public function test_revision_request_rolls_back_cycle_history_and_notifications_when_event_fails(): void
    {
        $f = $this->fixtures();
        $this->mock(TaskEventRecorder::class)
            ->shouldReceive('record')
            ->once()
            ->andThrow(new RuntimeException('event failed'));

        $this->actingAs($f['reviewer'])
            ->postJson(route('tasks.revision.request', $f['task']), [
                'formal_feedback' => 'Feedback',
                'revision_due_date' => now()->addDays(2)->toDateString(),
            ])->assertServerError();

        $this->assertSame(TaskState::InReview, $f['task']->fresh()->machineState());
        $this->assertDatabaseCount('task_revision_cycles', 0);
        $this->assertDatabaseCount('task_histories', 0);
        Notification::assertNothingSent();
    }

    public function test_revision_notifications_wait_for_commit_and_outer_rollback_removes_all_side_effects(): void
    {
        $f = $this->fixtures();

        try {
            DB::transaction(function () use ($f): void {
                $this->actingAs($f['reviewer'])
                    ->postJson(route('tasks.revision.request', $f['task']), [
                        'formal_feedback' => 'Feedback inside outer transaction.',
                        'revision_due_date' => now()->addDays(2)->toDateString(),
                    ])
                    ->assertOk();

                Notification::assertNothingSent();
                throw new RuntimeException('Rollback revision request.');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Rollback revision request.', $exception->getMessage());
        }

        Notification::assertNothingSent();
        $this->assertSame(TaskState::InReview, $f['task']->fresh()->machineState());
        $this->assertDatabaseCount('task_revision_cycles', 0);
        $this->assertDatabaseCount('task_histories', 0);
        $this->assertDatabaseCount('task_events', 0);
    }

    public function test_assignee_begins_revision_once_and_preserves_deadline(): void
    {
        $f = $this->fixtures();
        $cycle = $this->requestRevision($f);
        $dueDate = $cycle->revision_due_date->toDateString();

        $this->actingAs($f['other_member'])
            ->postJson(route('tasks.revision.start', $f['task']))
            ->assertForbidden();

        $this->actingAs($f['assignee'])
            ->postJson(route('tasks.revision.start', $f['task']))
            ->assertOk();

        $this->assertSame(TaskState::InProgress, $f['task']->fresh()->machineState());
        $this->assertNotNull($cycle->fresh()->started_at);
        $this->assertSame($dueDate, $f['task']->fresh()->revision_due_date->toDateString());
        $this->assertSame(1, TaskEvent::query()->where('event_type', TaskEventRecorder::REVISION_STARTED)->count());
        $this->assertSame(1, TaskHistory::query()->where('action', 'revision_started')->count());

        $this->postJson(route('tasks.revision.start', $f['task']))->assertStatus(422);
        $this->assertSame(1, TaskEvent::query()->where('event_type', TaskEventRecorder::REVISION_STARTED)->count());
    }

    public function test_assignee_resubmits_started_revision_once_with_linked_submission_and_stable_identity(): void
    {
        $f = $this->fixtures();
        $taskId = $f['task']->id;
        $taskUid = $f['task']->task_uid;
        $cycle = $this->requestRevision($f);
        $this->actingAs($f['assignee'])->postJson(route('tasks.revision.start', $f['task']))->assertOk();

        $this->postJson(route('tasks.resubmit', $f['task']), ['submission_note' => '  Updated totals.  '])
            ->assertOk()
            ->assertJson(['message' => 'Task resubmitted for review.']);

        $task = $f['task']->fresh();
        $submission = TaskSubmission::query()->where('revision_cycle_id', $cycle->id)->sole();
        $this->assertSame($taskId, $task->id);
        $this->assertSame($taskUid, $task->task_uid);
        $this->assertSame(TaskState::Submitted, $task->machineState());
        $this->assertSame($cycle->id, $task->active_revision_cycle_id);
        $this->assertSame($cycle->id, $submission->revision_cycle_id);
        $this->assertSame('Updated totals.', $submission->submission_note);
        $this->assertNotNull($cycle->fresh()->resubmitted_at);
        $this->assertNull($task->revision_due_date);
        $this->assertNotNull($cycle->fresh()->revision_due_date);
        $this->assertSame(now(config('app.timezone'))->addDays(2)->toDateString(), $task->review_due_date->toDateString());
        $this->assertSame(1, TaskEvent::query()->where('event_type', TaskEventRecorder::RESUBMITTED)->count());
        Notification::assertSentTo($f['reviewer'], TaskReviewWorkflowNotification::class);

        Notification::fake();
        $this->postJson(route('tasks.resubmit', $task))->assertStatus(422);
        $this->assertSame(1, TaskSubmission::query()->where('revision_cycle_id', $cycle->id)->count());
        Notification::assertNothingSent();
    }

    public function test_resubmission_requires_started_unresolved_cycle_and_eligible_reviewer(): void
    {
        $f = $this->fixtures();
        $this->requestRevision($f);
        $f['task']->forceFill(['status' => TaskState::InProgress])->save();

        $this->actingAs($f['assignee'])->postJson(route('tasks.resubmit', $f['task']))->assertStatus(422);

        $cycle = TaskRevisionCycle::query()->where('task_id', $f['task']->id)->sole();
        $cycle->forceFill(['started_at' => now()])->save();
        $f['reviewer']->update(['active' => false]);
        $this->postJson(route('tasks.resubmit', $f['task']))->assertStatus(422);
        $this->assertDatabaseCount('task_submissions', 0);
    }

    public function test_next_revision_request_resolves_prior_cycle_and_keeps_only_one_cycle_active(): void
    {
        $f = $this->fixtures();
        $firstCycle = $this->requestRevision($f);
        $this->actingAs($f['assignee'])->postJson(route('tasks.revision.start', $f['task']))->assertOk();
        $this->postJson(route('tasks.resubmit', $f['task']))->assertOk();
        $this->actingAs($f['reviewer'])->postJson(route('tasks.review.start', $f['task']))->assertOk();

        $this->postJson(route('tasks.revision.request', $f['task']), [
            'formal_feedback' => 'Second revision round.',
            'revision_due_date' => now()->addDays(4)->toDateString(),
        ])->assertOk();

        $task = $f['task']->fresh();
        $secondCycle = TaskRevisionCycle::query()
            ->where('task_id', $task->id)
            ->where('cycle_number', 2)
            ->sole();

        $this->assertNotNull($firstCycle->fresh()->resolved_at);
        $this->assertNull($secondCycle->resolved_at);
        $this->assertSame(2, $task->revision_count);
        $this->assertSame($secondCycle->id, $task->active_revision_cycle_id);
        $this->assertSame(1, TaskRevisionCycle::query()
            ->where('task_id', $task->id)
            ->whereNull('resolved_at')
            ->count());
    }

    public function test_generic_update_and_initial_submit_cannot_bypass_active_revision_workflow(): void
    {
        $f = $this->fixtures();
        $this->requestRevision($f);

        foreach ([
            ['status' => TaskState::InProgress->value],
            ['revision_count' => 99],
            ['active_revision_cycle_id' => null],
            ['revision_due_date' => now()->addWeek()->toDateString()],
            ['assignee_id' => $f['other_member']->id],
            ['reviewer_id' => $f['creator']->id],
        ] as $payload) {
            $this->actingAs($f['creator'])
                ->putJson(route('tasks.update', $f['task']), $payload)
                ->assertStatus(422);
        }

        $this->actingAs($f['assignee'])->postJson(route('tasks.revision.start', $f['task']))->assertOk();
        $this->postJson(route('tasks.submit', $f['task']))->assertStatus(422);
    }

    public function test_revision_ui_controls_are_state_and_role_scoped_and_payload_is_safe(): void
    {
        $f = $this->fixtures();
        $requestUrl = route('tasks.revision.request', $f['task']);
        $this->actingAs($f['reviewer'])->get(route('tasks'))->assertOk()->assertSee($requestUrl);
        $this->actingAs($f['assignee'])->get(route('tasks'))->assertOk()->assertDontSee($requestUrl);

        $cycle = $this->requestRevision($f);
        $startUrl = route('tasks.revision.start', $f['task']);
        $this->actingAs($f['assignee'])->get(route('tasks'))
            ->assertOk()
            ->assertSee($startUrl)
            ->assertSeeText($cycle->formal_feedback);

        Notification::assertSentTo($f['assignee'], TaskReviewWorkflowNotification::class, function ($notification) use ($f, $cycle) {
            $payload = $notification->toArray($f['assignee']);
            $this->assertArrayNotHasKey('priority', $payload);
            $this->assertArrayNotHasKey('calculated_urgency_score', $payload);
            $this->assertSame("task_revision_cycles:{$cycle->id}", $payload['feedback_reference']);

            return true;
        });
    }

    private function requestRevision(array $fixtures): TaskRevisionCycle
    {
        $this->actingAs($fixtures['reviewer'])->postJson(route('tasks.revision.request', $fixtures['task']), [
            'formal_feedback' => 'Revise the calculations.',
            'revision_due_date' => now()->addDays(3)->toDateString(),
        ])->assertOk();

        return TaskRevisionCycle::query()->where('task_id', $fixtures['task']->id)->sole();
    }

    private function fixtures(): array
    {
        $creator = $this->user('manager');
        $reviewer = $this->user('project_manager');
        $otherReviewer = $this->user('project_manager');
        $assignee = $this->user('team_member');
        $otherMember = $this->user('team_member');
        $project = Project::factory()->create(['project_manager_id' => $reviewer->id]);
        $project->members()->attach([$assignee->id, $otherMember->id]);
        $task = Task::query()->create([
            'project_id' => $project->id,
            'title' => 'Revision workflow task',
            'assignee_id' => $assignee->id,
            'created_by' => $creator->id,
            'priority' => 'High',
            'status' => TaskState::InReview,
            'progress' => 80,
            'due_date' => now()->addDays(5)->toDateString(),
        ]);
        $task->forceFill([
            'reviewer_id' => $reviewer->id,
            'submitted_at' => now()->subHour(),
            'review_started_at' => now()->subMinutes(30),
            'review_due_date' => now()->addDays(2)->toDateString(),
        ])->save();

        return [
            'creator' => $creator,
            'reviewer' => $reviewer,
            'other_reviewer' => $otherReviewer,
            'assignee' => $assignee,
            'other_member' => $otherMember,
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
