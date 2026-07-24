<?php

namespace Tests\Feature;

use App\Enums\TaskState;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\TaskHistory;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Notifications\TaskReviewWorkflowNotification;
use App\Services\TaskEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TaskSubmissionReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Notification::fake();
    }

    public function test_assignee_submits_once_with_stable_identity_submission_audit_and_after_commit_notifications(): void
    {
        $f = $this->fixtures();
        $id = $f['task']->id;
        $uid = $f['task']->task_uid;

        $this->actingAs($f['assignee'])
            ->postJson(route('tasks.submit', $f['task']), ['submission_note' => '  Ready for review.  '])
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Task submitted for review.']);

        $task = $f['task']->fresh();
        $this->assertSame($id, $task->id);
        $this->assertSame($uid, $task->task_uid);
        $this->assertSame(TaskState::Submitted, $task->machineState());
        $this->assertNotNull($task->submitted_at);
        $this->assertSame(now(config('app.timezone'))->addDays(2)->toDateString(), $task->review_due_date->toDateString());

        $submission = TaskSubmission::query()->whereBelongsTo($task)->sole();
        $this->assertNull($submission->revision_cycle_id);
        $this->assertSame($f['assignee']->id, $submission->submitted_by);
        $this->assertSame('Ready for review.', $submission->submission_note);

        $event = TaskEvent::query()->where('event_type', TaskEventRecorder::SUBMITTED)->sole();
        $this->assertSame($submission->id, $event->metadata['submission_id']);
        $this->assertSame('sla', $event->metadata['review_due_date_source']);
        $this->assertArrayNotHasKey('submission_note', $event->metadata);
        $this->assertDatabaseCount('task_histories', 1);

        Notification::assertSentTo($f['reviewer'], TaskReviewWorkflowNotification::class);
        Notification::assertSentTo($f['creator'], TaskReviewWorkflowNotification::class);

        $this->postJson(route('tasks.submit', $task), ['submission_note' => 'duplicate'])
            ->assertStatus(422);
        $this->assertDatabaseCount('task_submissions', 1);
        $this->assertDatabaseCount('task_events', 1);
        $this->assertDatabaseCount('task_histories', 1);
    }

    public function test_submission_requires_current_assignee_membership_and_eligible_distinct_reviewer(): void
    {
        $f = $this->fixtures();

        $this->actingAs($f['other_member'])->postJson(route('tasks.submit', $f['task']))->assertForbidden();

        foreach ([
            null,
            $f['assignee']->id,
            $f['other_project_manager']->id,
        ] as $reviewerId) {
            $f['task']->forceFill(['reviewer_id' => $reviewerId])->save();
            $this->actingAs($f['assignee'])
                ->postJson(route('tasks.submit', $f['task']))
                ->assertStatus(422)
                ->assertJsonValidationErrors('reviewer_id');
        }

        $f['task']->forceFill(['reviewer_id' => $f['reviewer']->id])->save();
        $f['project']->members()->detach($f['assignee']->id);
        $this->actingAs($f['assignee'])
            ->postJson(route('tasks.submit', $f['task']))
            ->assertForbidden();

        $this->assertDatabaseCount('task_submissions', 0);
        $this->assertDatabaseCount('task_events', 0);
        Notification::assertNothingSent();
    }

    public function test_explicit_future_review_deadline_is_preserved(): void
    {
        $f = $this->fixtures();
        $explicit = now()->addDays(8)->toDateString();
        $f['task']->forceFill(['review_due_date' => $explicit])->save();

        $this->actingAs($f['assignee'])->postJson(route('tasks.submit', $f['task']))->assertOk();

        $this->assertSame($explicit, $f['task']->fresh()->review_due_date->toDateString());
        $this->assertSame(
            'explicit',
            TaskEvent::query()->where('event_type', TaskEventRecorder::SUBMITTED)->sole()->metadata['review_due_date_source'],
        );
    }

    public function test_submission_rolls_back_record_history_event_and_notification_when_event_write_fails(): void
    {
        $f = $this->fixtures();
        $this->mock(TaskEventRecorder::class)
            ->shouldReceive('record')
            ->once()
            ->andThrow(new \RuntimeException('event failed'));

        $this->actingAs($f['assignee'])
            ->postJson(route('tasks.submit', $f['task']))
            ->assertServerError();

        $this->assertSame(TaskState::InProgress, $f['task']->fresh()->machineState());
        $this->assertDatabaseCount('task_submissions', 0);
        $this->assertDatabaseCount('task_histories', 0);
        $this->assertDatabaseCount('task_events', 0);
        Notification::assertNothingSent();
    }

    public function test_assigned_reviewer_starts_review_once_and_preserves_deadline(): void
    {
        $f = $this->fixtures();
        $this->actingAs($f['assignee'])->postJson(route('tasks.submit', $f['task']))->assertOk();
        $deadline = $f['task']->fresh()->review_due_date->toDateString();

        $this->actingAs($f['other_project_manager'])
            ->postJson(route('tasks.review.start', $f['task']))
            ->assertForbidden();

        $this->actingAs($f['reviewer'])
            ->postJson(route('tasks.review.start', $f['task']))
            ->assertOk()
            ->assertJson(['message' => 'Task review started.']);

        $task = $f['task']->fresh();
        $this->assertSame(TaskState::InReview, $task->machineState());
        $this->assertNotNull($task->review_started_at);
        $this->assertSame($deadline, $task->review_due_date->toDateString());
        $this->assertSame(1, TaskHistory::query()->where('action', 'review_started')->count());
        $this->assertSame(1, TaskEvent::query()->where('event_type', TaskEventRecorder::REVIEW_STARTED)->count());
        Notification::assertSentTo($f['assignee'], TaskReviewWorkflowNotification::class);

        $this->postJson(route('tasks.review.start', $task))->assertStatus(422);
        $this->assertSame(1, TaskHistory::query()->where('action', 'review_started')->count());
        $this->assertSame(1, TaskEvent::query()->where('event_type', TaskEventRecorder::REVIEW_STARTED)->count());
    }

    public function test_inactive_reviewer_cannot_start_review(): void
    {
        $f = $this->fixtures();
        $this->actingAs($f['assignee'])->postJson(route('tasks.submit', $f['task']))->assertOk();
        $f['reviewer']->update(['active' => false]);

        $this->actingAs($f['reviewer'])
            ->postJson(route('tasks.review.start', $f['task']))
            ->assertForbidden();

        $this->assertSame(TaskState::Submitted, $f['task']->fresh()->machineState());
    }

    public function test_generic_update_cannot_submit_start_review_or_modify_frozen_tasks(): void
    {
        $f = $this->fixtures();

        $this->actingAs($f['creator'])
            ->putJson(route('tasks.update', $f['task']), ['status' => TaskState::Submitted->value])
            ->assertStatus(422);

        $this->actingAs($f['assignee'])->postJson(route('tasks.submit', $f['task']))->assertOk();

        foreach ([
            ['assignee_id' => $f['other_member']->id],
            ['reviewer_id' => $f['creator']->id],
            ['title' => 'Changed after submission'],
            ['review_started_at' => now()->toAtomString()],
        ] as $payload) {
            $this->actingAs($f['creator'])
                ->putJson(route('tasks.update', $f['task']), $payload)
                ->assertStatus(422);
        }
    }

    public function test_management_assigns_only_an_eligible_reviewer_before_submission(): void
    {
        $f = $this->fixtures(TaskState::NotStarted, ['reviewer_id' => null]);

        $this->actingAs($f['project_manager'])
            ->postJson(route('tasks.reviewer.reassign', $f['task']), [
                'reviewer_id' => $f['project_manager']->id,
            ])
            ->assertOk();
        $this->assertSame($f['project_manager']->id, $f['task']->fresh()->reviewer_id);

        $this->postJson(route('tasks.reviewer.reassign', $f['task']), [
            'reviewer_id' => $f['other_project_manager']->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reviewer_id');

        $this->actingAs($f['assignee'])
            ->postJson(route('tasks.reviewer.reassign', $f['task']), [
                'reviewer_id' => $f['creator']->id,
            ])
            ->assertForbidden();
    }

    public function test_task_page_scopes_submission_and_review_controls_and_payload_hides_management_fields(): void
    {
        $f = $this->fixtures();
        $submitUrl = route('tasks.submit', $f['task']);

        $this->actingAs($f['assignee'])->get(route('tasks'))
            ->assertOk()->assertSee($submitUrl);
        $this->actingAs($f['creator'])->get(route('tasks'))
            ->assertOk()->assertDontSee($submitUrl);

        $this->actingAs($f['assignee'])->postJson($submitUrl)->assertOk();
        $reviewUrl = route('tasks.review.start', $f['task']);
        $this->actingAs($f['reviewer'])->get(route('tasks'))
            ->assertOk()->assertSee($reviewUrl);

        Notification::assertSentTo($f['reviewer'], TaskReviewWorkflowNotification::class, function ($notification) use ($f) {
            $payload = $notification->toArray($f['reviewer']);
            $this->assertArrayNotHasKey('priority', $payload);
            $this->assertArrayNotHasKey('calculated_urgency_score', $payload);
            $this->assertSame($f['task']->task_uid, $payload['task_uid']);

            return true;
        });
    }

    private function fixtures(TaskState $state = TaskState::InProgress, array $taskAttributes = []): array
    {
        $creator = $this->user('manager');
        $projectManager = $this->user('project_manager');
        $otherProjectManager = $this->user('project_manager');
        $assignee = $this->user('team_member');
        $otherMember = $this->user('team_member');
        $project = Project::factory()->create(['project_manager_id' => $projectManager->id]);
        $project->members()->attach([$assignee->id, $otherMember->id]);

        $task = Task::query()->create([
            'project_id' => $project->id,
            'title' => 'Submission task',
            'description' => 'Ready for review',
            'assignee_id' => $assignee->id,
            'created_by' => $creator->id,
            'priority' => 'High',
            'status' => $state,
            'progress' => 75,
            'due_date' => now()->addDays(5)->toDateString(),
        ]);
        $task->forceFill(array_merge([
            'reviewer_id' => $projectManager->id,
            'execution_due_date' => now()->addDays(5)->toDateString(),
        ], $taskAttributes))->save();

        return compact(
            'creator',
            'projectManager',
            'otherProjectManager',
            'assignee',
            'otherMember',
            'project',
            'task',
        ) + [
            'project_manager' => $projectManager,
            'other_project_manager' => $otherProjectManager,
            'other_member' => $otherMember,
            'reviewer' => $projectManager,
        ];
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('name', $role)->value('id'),
        ]);
    }
}
