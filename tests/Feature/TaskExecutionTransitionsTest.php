<?php

namespace Tests\Feature;

use App\Enums\TaskState;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\TaskHistory;
use App\Models\User;
use App\Notifications\TaskWorkflowTransitionNotification;
use App\Services\TaskEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

class TaskExecutionTransitionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Notification::fake();
    }

    public function test_assignee_can_start_hold_and_resume_with_canonical_identity_and_audit_data(): void
    {
        $fixtures = $this->fixtures();
        $task = $fixtures['task'];
        $taskId = $task->id;
        $taskUid = $task->task_uid;
        $deadline = $task->execution_due_date->toDateString();

        $this->actingAs($fixtures['assignee'])
            ->postJson(route('tasks.start', $task))
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Work started.']);

        $started = $task->fresh();
        $this->assertSame($taskId, $started->id);
        $this->assertSame($taskUid, $started->task_uid);
        $this->assertSame(TaskState::InProgress, $started->machineState());
        $this->assertNotNull($started->started_at);
        $firstStartedAt = $started->started_at->toAtomString();
        $this->assertSame(25, $started->progress);
        $this->assertTransitionRecorded($task, TaskEventRecorder::STARTED, 'started');
        $startedEvent = TaskEvent::query()
            ->where('task_id', $task->id)
            ->where('event_type', TaskEventRecorder::STARTED)
            ->sole();
        $this->assertSame(
            ['before' => TaskState::NotStarted->value, 'after' => TaskState::InProgress->value],
            $startedEvent->changed_fields['status'],
        );
        $this->assertSame($fixtures['assignee']->id, $startedEvent->changed_fields['assignee_id']['after']);
        $this->assertSame($firstStartedAt, $startedEvent->changed_fields['started_at']['after']);

        $this->postJson(route('tasks.hold', $task), ['reason' => '  Waiting for source files.  '])
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Task placed on hold.']);

        $held = $task->fresh();
        $this->assertSame(TaskState::OnHold, $held->machineState());
        $this->assertNotNull($held->held_at);
        $this->assertSame($fixtures['assignee']->id, $held->held_by);
        $this->assertSame('Waiting for source files.', $held->hold_reason);
        $this->assertSame($deadline, $held->execution_due_date->toDateString());
        $this->assertSame($firstStartedAt, $held->started_at->toAtomString());
        $this->assertTransitionRecorded($task, TaskEventRecorder::HELD, 'held');

        $heldEvent = TaskEvent::query()
            ->where('task_id', $task->id)
            ->where('event_type', TaskEventRecorder::HELD)
            ->sole();
        $this->assertSame('Waiting for source files.', $heldEvent->changed_fields['hold_reason']['after']);
        $this->assertSame($deadline, $heldEvent->changed_fields['active_deadline']['before']);
        $this->assertSame($deadline, $heldEvent->changed_fields['active_deadline']['after']);

        $this->postJson(route('tasks.resume', $task))
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Work resumed.']);

        $resumed = $task->fresh();
        $this->assertSame($taskId, $resumed->id);
        $this->assertSame($taskUid, $resumed->task_uid);
        $this->assertSame(TaskState::InProgress, $resumed->machineState());
        $this->assertNull($resumed->held_at);
        $this->assertNull($resumed->held_by);
        $this->assertNull($resumed->hold_reason);
        $this->assertSame($deadline, $resumed->execution_due_date->toDateString());
        $this->assertSame($firstStartedAt, $resumed->started_at->toAtomString());
        $this->assertTransitionRecorded($task, TaskEventRecorder::RESUMED, 'resumed');

        $resumedEvent = TaskEvent::query()
            ->where('task_id', $task->id)
            ->where('event_type', TaskEventRecorder::RESUMED)
            ->sole();
        $this->assertSame('Waiting for source files.', $resumedEvent->changed_fields['hold_reason']['before']);
        $this->assertNull($resumedEvent->changed_fields['hold_reason']['after']);
        $this->assertArrayHasKey('hold_duration_seconds', $resumedEvent->changed_fields);
    }

    public function test_non_assignee_and_management_users_cannot_execute_another_users_task(): void
    {
        $fixtures = $this->fixtures();

        foreach ([$fixtures['manager'], $fixtures['project_manager'], $fixtures['other_member']] as $actor) {
            $this->actingAs($actor)
                ->postJson(route('tasks.start', $fixtures['task']))
                ->assertForbidden();
        }

        $this->assertSame(TaskState::NotStarted, $fixtures['task']->fresh()->machineState());
        $this->assertDatabaseCount('task_events', 0);
        $this->assertDatabaseCount('task_histories', 0);
        Notification::assertNothingSent();
    }

    public function test_missing_or_invalid_reviewer_blocks_start_without_side_effects(): void
    {
        $fixtures = $this->fixtures();
        $task = $fixtures['task'];

        $task->forceFill(['reviewer_id' => null])->save();
        $this->actingAs($fixtures['assignee'])
            ->postJson(route('tasks.start', $task))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reviewer_id');

        $task->forceFill(['reviewer_id' => $fixtures['other_project_manager']->id])->save();
        $this->postJson(route('tasks.start', $task))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reviewer_id');

        $this->assertSame(TaskState::NotStarted, $task->fresh()->machineState());
        $this->assertDatabaseCount('task_events', 0);
        $this->assertDatabaseCount('task_histories', 0);
        Notification::assertNothingSent();
    }

    public function test_inactive_or_removed_assignee_cannot_start_work(): void
    {
        $removedFixtures = $this->fixtures();
        $removedFixtures['project']->members()->detach($removedFixtures['assignee']->id);

        $this->actingAs($removedFixtures['assignee'])
            ->postJson(route('tasks.start', $removedFixtures['task']))
            ->assertForbidden();

        $inactiveFixtures = $this->fixtures();
        $inactiveFixtures['assignee']->forceFill(['active' => false])->save();

        $this->actingAs($inactiveFixtures['assignee'])
            ->postJson(route('tasks.start', $inactiveFixtures['task']))
            ->assertForbidden();

        $this->assertSame(TaskState::NotStarted, $removedFixtures['task']->fresh()->machineState());
        $this->assertSame(TaskState::NotStarted, $inactiveFixtures['task']->fresh()->machineState());
    }

    public function test_hold_requires_a_trimmed_non_empty_reason_and_rejects_arbitrary_state_input(): void
    {
        $fixtures = $this->fixtures(TaskState::InProgress);

        foreach ([[], ['reason' => '   ']] as $payload) {
            $this->actingAs($fixtures['assignee'])
                ->postJson(route('tasks.hold', $fixtures['task']), $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('reason');
        }

        $this->postJson(route('tasks.hold', $fixtures['task']), [
            'reason' => 'Valid reason',
            'status' => TaskState::Completed->value,
        ])->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->assertSame(TaskState::InProgress, $fixtures['task']->fresh()->machineState());
        $this->assertDatabaseCount('task_events', 0);
        $this->assertDatabaseCount('task_histories', 0);
    }

    public function test_duplicate_start_hold_and_resume_create_no_duplicate_side_effects(): void
    {
        $fixtures = $this->fixtures();
        $this->actingAs($fixtures['assignee']);

        $this->postJson(route('tasks.start', $fixtures['task']))->assertOk();
        $this->postJson(route('tasks.start', $fixtures['task']))->assertUnprocessable();
        $this->assertSame(1, $this->eventCount($fixtures['task'], TaskEventRecorder::STARTED));
        $this->assertSame(1, $this->historyCount($fixtures['task'], 'started'));

        $this->postJson(route('tasks.hold', $fixtures['task']), ['reason' => 'Dependency blocked'])->assertOk();
        $this->postJson(route('tasks.hold', $fixtures['task']), ['reason' => 'Duplicate'])->assertUnprocessable();
        $this->assertSame(1, $this->eventCount($fixtures['task'], TaskEventRecorder::HELD));
        $this->assertSame(1, $this->historyCount($fixtures['task'], 'held'));

        $this->postJson(route('tasks.resume', $fixtures['task']))->assertOk();
        $this->postJson(route('tasks.resume', $fixtures['task']))->assertUnprocessable();
        $this->assertSame(1, $this->eventCount($fixtures['task'], TaskEventRecorder::RESUMED));
        $this->assertSame(1, $this->historyCount($fixtures['task'], 'resumed'));
        Notification::assertSentToTimes(
            $fixtures['assignee'],
            TaskWorkflowTransitionNotification::class,
            2,
        );
        Notification::assertSentToTimes(
            $fixtures['manager'],
            TaskWorkflowTransitionNotification::class,
            3,
        );
        Notification::assertSentToTimes(
            $fixtures['project_manager'],
            TaskWorkflowTransitionNotification::class,
            3,
        );
    }

    public function test_generic_update_cannot_bypass_execution_transition_commands(): void
    {
        $fixtures = $this->fixtures();
        $assignee = $fixtures['assignee'];

        foreach ([
            [TaskState::NotStarted, TaskState::InProgress],
            [TaskState::InProgress, TaskState::OnHold],
            [TaskState::OnHold, TaskState::InProgress],
        ] as [$from, $to]) {
            $task = $this->task($fixtures, $from, ['title' => "{$from->value} generic lockdown"]);

            $this->actingAs($assignee)
                ->putJson(route('tasks.update', $task), ['status' => $to->value])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('status');

            $this->assertSame($from, $task->fresh()->machineState());
        }

        $this->assertDatabaseCount('task_events', 0);
        $this->assertDatabaseCount('task_histories', 0);
    }

    public function test_notifications_wait_for_commit_and_rollback_sends_nothing(): void
    {
        $fixtures = $this->fixtures();

        DB::transaction(function () use ($fixtures): void {
            $this->actingAs($fixtures['assignee'])
                ->postJson(route('tasks.start', $fixtures['task']))
                ->assertOk();
            Notification::assertNothingSent();
        });

        Notification::assertSentTo($fixtures['manager'], TaskWorkflowTransitionNotification::class);
        Notification::assertSentTo($fixtures['project_manager'], TaskWorkflowTransitionNotification::class);
        Notification::assertNotSentTo($fixtures['assignee'], TaskWorkflowTransitionNotification::class);

        Notification::fake();
        $rollbackFixtures = $this->fixtures();

        try {
            DB::transaction(function () use ($rollbackFixtures): void {
                $this->actingAs($rollbackFixtures['assignee'])
                    ->postJson(route('tasks.start', $rollbackFixtures['task']))
                    ->assertOk();
                Notification::assertNothingSent();

                throw new RuntimeException('Rollback the execution transition.');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Rollback the execution transition.', $exception->getMessage());
        }

        Notification::assertNothingSent();
        $this->assertSame(TaskState::NotStarted, $rollbackFixtures['task']->fresh()->machineState());
        $this->assertSame(0, $this->eventCount($rollbackFixtures['task'], TaskEventRecorder::STARTED));
        $this->assertSame(0, $this->historyCount($rollbackFixtures['task'], 'started'));
    }

    public function test_notification_recipients_are_deduplicated_and_payload_omits_hidden_fields(): void
    {
        $fixtures = $this->fixtures(TaskState::InProgress);
        $task = $fixtures['task'];
        $task->forceFill([
            'created_by' => $fixtures['project_manager']->id,
            'reviewer_id' => $fixtures['project_manager']->id,
            'priority_override_reason' => 'Management-only priority rationale',
        ])->save();

        $this->actingAs($fixtures['assignee'])
            ->postJson(route('tasks.hold', $task), ['reason' => 'Sensitive operational blocker'])
            ->assertOk();

        Notification::assertSentToTimes(
            $fixtures['assignee'],
            TaskWorkflowTransitionNotification::class,
            1,
        );
        Notification::assertSentToTimes(
            $fixtures['project_manager'],
            TaskWorkflowTransitionNotification::class,
            1,
        );
        Notification::assertSentToTimes(
            $fixtures['manager'],
            TaskWorkflowTransitionNotification::class,
            0,
        );

        Notification::assertSentTo(
            $fixtures['assignee'],
            TaskWorkflowTransitionNotification::class,
            function (TaskWorkflowTransitionNotification $notification) use ($fixtures): bool {
                $payload = $notification->toArray($fixtures['assignee']);

                $this->assertSame(TaskState::OnHold->value, $payload['current_state']);
                $this->assertSame($fixtures['task']->task_uid, $payload['task_uid']);
                $this->assertArrayNotHasKey('priority', $payload);
                $this->assertArrayNotHasKey('priority_override_reason', $payload);
                $this->assertArrayNotHasKey('hold_reason', $payload);

                return true;
            },
        );
    }

    public function test_notification_failure_is_reported_after_committed_transition(): void
    {
        $fixtures = $this->fixtures();
        Notification::shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException('Injected transition notification failure.'));

        $response = $this->actingAs($fixtures['assignee'])
            ->postJson(route('tasks.start', $fixtures['task']));

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'notification_status' => 'delivery_failed',
        ]);

        $this->assertSame(TaskState::InProgress, $fixtures['task']->fresh()->machineState());
        $this->assertSame(1, $this->eventCount($fixtures['task'], TaskEventRecorder::STARTED));
        $this->assertSame(1, $this->historyCount($fixtures['task'], 'started'));
    }

    public function test_task_page_shows_only_the_assignees_state_appropriate_execution_control(): void
    {
        $fixtures = $this->fixtures();
        $startUrl = route('tasks.start', $fixtures['task']);

        $this->actingAs($fixtures['assignee'])
            ->get(route('tasks'))
            ->assertOk()
            ->assertSee('Start Work')
            ->assertSee($startUrl);

        $this->actingAs($fixtures['manager'])
            ->get(route('tasks'))
            ->assertOk()
            ->assertDontSee('data-url="'.$startUrl.'"', false);
    }

    private function fixtures(TaskState $state = TaskState::NotStarted): array
    {
        $manager = $this->userWithRole('manager');
        $projectManager = $this->userWithRole('project_manager');
        $otherProjectManager = $this->userWithRole('project_manager');
        $assignee = $this->userWithRole('team_member');
        $otherMember = $this->userWithRole('team_member');
        $project = Project::factory()->create(['project_manager_id' => $projectManager->id]);
        $project->members()->attach([$assignee->id, $otherMember->id]);
        $fixtures = [
            'manager' => $manager,
            'project_manager' => $projectManager,
            'other_project_manager' => $otherProjectManager,
            'assignee' => $assignee,
            'other_member' => $otherMember,
            'project' => $project,
        ];
        $fixtures['task'] = $this->task($fixtures, $state);

        return $fixtures;
    }

    private function task(array $fixtures, TaskState $state, array $attributes = []): Task
    {
        $task = Task::query()->create(array_merge([
            'project_id' => $fixtures['project']->id,
            'title' => 'Execution transition task',
            'description' => 'Transition behavior fixture',
            'assignee_id' => $fixtures['assignee']->id,
            'created_by' => $fixtures['manager']->id,
            'priority' => 'High',
            'status' => $state,
            'progress' => 25,
            'due_date' => now()->addDays(5)->toDateString(),
        ], $attributes));
        $task->forceFill([
            'reviewer_id' => $fixtures['project_manager']->id,
            'execution_due_date' => now()->addDays(5)->toDateString(),
        ])->save();

        if ($state === TaskState::OnHold) {
            $task->forceFill([
                'started_at' => now()->subDay(),
                'held_at' => now()->subHour(),
                'held_by' => $fixtures['assignee']->id,
                'hold_reason' => 'Existing hold reason',
            ])->save();
        }

        return $task->fresh();
    }

    private function userWithRole(string $role): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('name', $role)->value('id'),
        ]);
    }

    private function assertTransitionRecorded(Task $task, string $eventType, string $historyAction): void
    {
        $this->assertSame(1, $this->eventCount($task, $eventType));
        $this->assertSame(1, $this->historyCount($task, $historyAction));
    }

    private function eventCount(Task $task, string $eventType): int
    {
        return TaskEvent::query()
            ->where('task_id', $task->id)
            ->where('event_type', $eventType)
            ->count();
    }

    private function historyCount(Task $task, string $action): int
    {
        return TaskHistory::query()
            ->where('task_id', $task->id)
            ->where('action', $action)
            ->count();
    }
}
