<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureTaskCorrelationId;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\TaskHistory;
use App\Models\User;
use App\Services\TaskEventRecorder;
use App\Services\TaskLifecycleService;
use App\Support\UlidGenerator;
use App\ValueObjects\TaskOperationContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class TaskEventRecordingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Notification::fake();
    }

    public function test_http_creation_rejects_protected_fields_then_records_one_created_event_with_safe_context(): void
    {
        [$manager, $project, $assignee] = $this->managerAndProject();
        $suppliedUid = '00000000000000000000000001';
        $correlationId = 'browser-create-request-1';

        $this->actingAs($manager)
            ->postJson(route('tasks.store'), [
                'task_uid' => $suppliedUid,
                'title' => 'Protected field attempt',
                'project_id' => $project->id,
                'assignee_id' => $assignee->id,
                'reviewer_id' => $manager->id,
                'priority' => 'High',
                'status' => 'not_started',
                'progress' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['task_uid', 'status', 'progress']);

        $this->actingAs($manager)
            ->withHeader(EnsureTaskCorrelationId::HEADER, $correlationId)
            ->postJson(route('tasks.store'), [
                'title' => 'प्रतिवेदन तयार गर्नुहोस्',
                'project_id' => $project->id,
                'assignee_id' => $assignee->id,
                'reviewer_id' => $manager->id,
                'priority' => 'High',
                'start_date' => '2026-07-19',
                'comments' => 'Keep "quotes" and punctuation!',
            ])
            ->assertOk()
            ->assertHeader(EnsureTaskCorrelationId::HEADER, $correlationId);

        $task = Task::query()->where('title', 'प्रतिवेदन तयार गर्नुहोस्')->firstOrFail();
        $event = TaskEvent::query()->sole();

        $this->assertNotSame($suppliedUid, $task->task_uid);
        $this->assertSame(TaskEventRecorder::CREATED, $event->event_type);
        $this->assertSame(1, $event->sequence);
        $this->assertSame($manager->id, $event->actor_id);
        $this->assertSame('web', $event->source);
        $this->assertSame($correlationId, $event->correlation_id);
        $this->assertSame('प्रतिवेदन तयार गर्नुहोस्', $event->changed_fields['title']['after']);
        $this->assertSame('2026-07-19', $event->changed_fields['start_date']['after']);
        $this->assertSame('Keep "quotes" and punctuation!', $event->changed_fields['comments']['after']);
        $this->assertSame([
            'project_id',
            'title',
            'description',
            'assignee_id',
            'reviewer_id',
            'priority',
            'status',
            'progress',
            'start_date',
            'due_date',
            'review_due_date',
            'comments',
        ], array_keys($event->changed_fields));
        $this->assertArrayNotHasKey('task_uid', $event->changed_fields);
        $this->assertDatabaseHas('task_histories', [
            'task_id' => $task->id,
            'action' => 'created',
            'user_id' => $manager->id,
        ]);
    }

    public function test_ordinary_update_records_only_real_approved_changes_with_the_next_sequence(): void
    {
        [$manager, $project] = $this->managerAndProject();
        $service = app(TaskLifecycleService::class);
        $createdAt = CarbonImmutable::parse('2026-07-19T10:00:00+05:45');
        $updatedAt = CarbonImmutable::parse('2026-07-19T11:00:00+05:45');
        $task = $service->create($this->taskData($project), $manager, TaskOperationContext::test(
            $manager->id,
            'create-operation-1',
            $createdAt,
        ));

        $updated = $service->update($task, [
            'title' => 'Updated title',
            'start_date' => '2026-07-20',
        ], $manager, TaskOperationContext::test(
            $manager->id,
            'update-operation-1',
            $updatedAt,
        ));

        $event = $updated->events()->where('event_type', TaskEventRecorder::UPDATED)->sole();
        $this->assertSame(2, $event->sequence);
        $this->assertSame(['title', 'start_date'], array_keys($event->changed_fields));
        $this->assertSame(['before' => 'Test task', 'after' => 'Updated title'], $event->changed_fields['title']);
        $this->assertSame(['before' => null, 'after' => '2026-07-20'], $event->changed_fields['start_date']);
        $this->assertSame($manager->id, $event->actor_id);
        $this->assertSame('test', $event->source);
        $this->assertSame('update-operation-1', $event->correlation_id);
        $this->assertTrue($updatedAt->equalTo($event->occurred_at));
        $this->assertSame([1, 2], $updated->events()->pluck('sequence')->all());
        $this->assertSame(2, TaskHistory::query()->where('task_id', $task->id)->count());
    }

    public function test_no_op_update_keeps_legacy_history_but_does_not_create_an_event(): void
    {
        [$manager, $project] = $this->managerAndProject();
        $service = app(TaskLifecycleService::class);
        $task = $service->create($this->taskData($project), $manager, TaskOperationContext::test($manager->id));

        $service->update($task, ['title' => 'Test task'], $manager, TaskOperationContext::test($manager->id));

        $this->assertSame(1, $task->events()->count());
        $this->assertSame(2, TaskHistory::query()->where('task_id', $task->id)->count());
    }

    public function test_http_update_cannot_replace_uid_and_returns_its_correlation_id(): void
    {
        [$manager, $project] = $this->managerAndProject();
        $task = app(TaskLifecycleService::class)->create(
            $this->taskData($project),
            $manager,
            TaskOperationContext::test($manager->id),
        );
        $originalUid = $task->task_uid;
        $correlationId = 'browser-update-request-1';

        $this->actingAs($manager)
            ->withHeader(EnsureTaskCorrelationId::HEADER, $correlationId)
            ->putJson(route('tasks.update', $task), [
                'task_uid' => '00000000000000000000000009',
                'title' => 'HTTP updated title',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('task_uid')
            ->assertHeader(EnsureTaskCorrelationId::HEADER, $correlationId);

        $this->assertSame($originalUid, $task->fresh()->task_uid);
        $this->withHeader(EnsureTaskCorrelationId::HEADER, $correlationId)
            ->putJson(route('tasks.update', $task), [
                'title' => 'HTTP updated title',
                'expected_version' => $task->lock_version,
            ])
            ->assertOk();
        $event = $task->events()->where('event_type', TaskEventRecorder::UPDATED)->sole();
        $this->assertSame($correlationId, $event->correlation_id);
        $this->assertSame('web', $event->source);
        $this->assertSame($manager->id, $event->actor_id);
        $this->assertSame(['title'], array_keys($event->changed_fields));
    }

    public function test_event_failure_rolls_back_task_creation_and_update_with_legacy_history(): void
    {
        [$manager, $project] = $this->managerAndProject();
        $normalService = app(TaskLifecycleService::class);
        $existing = $normalService->create(
            $this->taskData($project, ['title' => 'Rollback update task']),
            $manager,
            TaskOperationContext::test($manager->id),
        );
        $taskCount = Task::withTrashed()->count();
        $historyCount = TaskHistory::query()->count();
        $eventCount = TaskEvent::query()->count();
        $this->app->instance(TaskEventRecorder::class, new FailingTaskEventRecorder);
        $failingService = app(TaskLifecycleService::class);

        try {
            $failingService->create(
                $this->taskData($project, ['title' => 'Rollback create task']),
                $manager,
                TaskOperationContext::test($manager->id),
            );
            $this->fail('Expected task creation to fail with its event write.');
        } catch (RuntimeException) {
            $this->assertSame($taskCount, Task::withTrashed()->count());
            $this->assertSame($historyCount, TaskHistory::query()->count());
            $this->assertSame($eventCount, TaskEvent::query()->count());
        }

        try {
            $failingService->update(
                $existing->fresh(),
                ['title' => 'Mutation that must roll back'],
                $manager,
                TaskOperationContext::test($manager->id),
            );
            $this->fail('Expected task update to fail with its event write.');
        } catch (RuntimeException) {
            $this->assertSame('Rollback update task', $existing->fresh()->title);
            $this->assertSame($historyCount, TaskHistory::query()->count());
            $this->assertSame($eventCount, TaskEvent::query()->count());
        }
    }

    public function test_a_failed_task_mutation_leaves_no_event(): void
    {
        [$manager, $project] = $this->managerAndProject();
        $service = app(TaskLifecycleService::class);
        $task = $service->create($this->taskData($project), $manager, TaskOperationContext::test($manager->id));
        $eventCount = TaskEvent::query()->count();

        try {
            $service->update($task, [
                'status' => 'Completed',
                'progress' => 50,
            ], $manager, TaskOperationContext::test($manager->id));
            $this->fail('Expected invalid completion state to fail.');
        } catch (ValidationException) {
            $this->assertSame('Not Started', $task->fresh()->status);
            $this->assertSame(0, $task->fresh()->progress);
            $this->assertSame($eventCount, TaskEvent::query()->count());
        }
    }

    public function test_recorder_retries_identity_collisions_orders_sequences_and_sends_no_notifications(): void
    {
        $task = Task::query()->create(['title' => 'Recorder collision task']);
        $collisionUid = '00000000000000000000000001';
        $replacementUid = '00000000000000000000000002';
        TaskEvent::query()->forceCreate([
            'event_uid' => $collisionUid,
            'task_id' => $task->id,
            'sequence' => 1,
            'event_type' => TaskEventRecorder::CREATED,
            'actor_id' => null,
            'source' => 'test',
            'correlation_id' => null,
            'changed_fields' => ['title' => ['before' => null, 'after' => $task->title]],
            'metadata' => null,
            'occurred_at' => now(),
        ]);
        $this->app->instance(UlidGenerator::class, new SequenceEventUlidGenerator([
            $collisionUid,
            $replacementUid,
        ]));

        $event = app(TaskEventRecorder::class)->record(
            $task,
            TaskEventRecorder::UPDATED,
            TaskOperationContext::test(correlationId: 'recorder-retry-1'),
            ['progress' => ['before' => 0, 'after' => 10]],
            ['integration' => ['attempt' => 2]],
        );

        $this->assertSame($replacementUid, $event->event_uid);
        $this->assertSame(2, $event->sequence);
        $this->assertSame(['integration' => ['attempt' => 2]], $event->metadata);
        $this->assertSame([1, 2], $task->events()->pluck('sequence')->all());
        Notification::assertNothingSent();
    }

    public function test_rolled_back_event_writes_do_not_leave_a_sequence_gap(): void
    {
        $task = Task::query()->create(['title' => 'Sequence rollback task']);
        $recorder = app(TaskEventRecorder::class);
        $recorder->record(
            $task,
            TaskEventRecorder::CREATED,
            TaskOperationContext::test(),
            ['title' => ['before' => null, 'after' => $task->title]],
        );

        try {
            DB::transaction(function () use ($recorder, $task) {
                $recorder->record(
                    $task,
                    TaskEventRecorder::UPDATED,
                    TaskOperationContext::test(),
                    ['progress' => ['before' => 0, 'after' => 10]],
                );

                throw new RuntimeException('Roll back the containing operation.');
            });
        } catch (RuntimeException) {
            $this->assertSame([1], $task->events()->pluck('sequence')->all());
        }

        $event = $recorder->record(
            $task,
            TaskEventRecorder::UPDATED,
            TaskOperationContext::test(),
            ['progress' => ['before' => 0, 'after' => 20]],
        );

        $this->assertSame(2, $event->sequence);
        $this->assertSame([1, 2], $task->events()->pluck('sequence')->all());
    }

    public function test_recorder_requires_a_persisted_task_and_schema_rejects_a_missing_uid(): void
    {
        $recorder = app(TaskEventRecorder::class);
        $unpersisted = new Task(['title' => 'Unpersisted task']);

        try {
            $recorder->record(
                $unpersisted,
                TaskEventRecorder::CREATED,
                TaskOperationContext::test(),
                [],
            );
            $this->fail('Expected an unpersisted task to be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('task_events', 0);
        }

        $this->expectException(QueryException::class);
        DB::table('tasks')->insertGetId([
            'title' => 'Task without UID',
            'priority' => 'Medium',
            'status' => 'Not Started',
            'progress' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

    }

    public function test_completion_and_reopen_record_canonical_events_on_the_same_task(): void
    {
        [$manager, $project] = $this->managerAndProject();
        $service = app(TaskLifecycleService::class);
        $assignee = User::factory()->create([
            'role_id' => Role::query()->where('name', 'team_member')->value('id'),
        ]);
        $project->members()->attach($assignee->id);
        $task = $service->create(
            $this->taskData($project, ['assignee_id' => $assignee->id]),
            $manager,
            TaskOperationContext::test($manager->id),
        );

        $completed = $this->approveTask($task, $manager, TaskOperationContext::test($manager->id));

        $this->assertSame($task->id, $completed->id);
        $this->assertSame($task->task_uid, $completed->task_uid);
        $this->assertNotNull($completed->completed_at);
        $this->assertDatabaseCount('completed_tasks', 0);

        $reopened = $this->reopenApprovedTask($completed, $manager, TaskOperationContext::test($manager->id));

        $this->assertSame($task->id, $reopened->id);
        $this->assertSame($task->task_uid, $reopened->task_uid);
        $this->assertSame([
            TaskEventRecorder::CREATED,
            TaskEventRecorder::APPROVED,
            TaskEventRecorder::COMPLETED,
            TaskEventRecorder::REOPENED,
            TaskEventRecorder::REVISION_REQUESTED,
        ], $reopened->events()->pluck('event_type')->all());
    }

    private function managerAndProject(): array
    {
        $manager = User::factory()->create([
            'role_id' => Role::query()->where('name', 'manager')->value('id'),
        ]);
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $assignee = User::factory()->create([
            'role_id' => Role::query()->where('name', 'team_member')->value('id'),
        ]);
        $project->members()->attach($assignee->id);

        return [$manager, $project, $assignee];
    }

    private function taskData(Project $project, array $attributes = []): array
    {
        return array_merge([
            'title' => 'Test task',
            'project_id' => $project->id,
            'assignee_id' => $project->members()->firstOrFail()->id,
            'reviewer_id' => $project->project_manager_id,
            'priority' => 'Medium',
        ], $attributes);
    }
}

class FailingTaskEventRecorder extends TaskEventRecorder
{
    public function __construct() {}

    public function record(
        Task $task,
        string $eventType,
        TaskOperationContext $context,
        array $changedFields,
        ?array $metadata = null,
    ): TaskEvent {
        throw new RuntimeException('Injected task event failure.');
    }
}

class SequenceEventUlidGenerator extends UlidGenerator
{
    private int $position = 0;

    public function __construct(private readonly array $ulids) {}

    public function generate(): string
    {
        return $this->ulids[$this->position++];
    }
}
