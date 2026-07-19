<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureTaskCorrelationId;
use App\Models\CompletedTask;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\TaskHistory;
use App\Models\User;
use App\Notifications\TaskCompletedNotification;
use App\Notifications\TaskRevertedNotification;
use App\Services\TaskEventRecorder;
use App\Services\TaskLifecycleService;
use App\ValueObjects\TaskOperationContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CanonicalTaskLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Notification::fake();
    }

    public function test_http_completion_updates_the_same_row_and_keeps_the_legacy_response_shape(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $task = $this->task($project, $assignee, ['progress' => 60]);
        $taskId = $task->id;
        $taskUid = $task->task_uid;
        $taskCount = Task::withTrashed()->count();
        $correlationId = 'complete-request-1';

        $response = $this->actingAs($manager)
            ->withHeader(EnsureTaskCorrelationId::HEADER, $correlationId)
            ->putJson(route('tasks.update', $task), [
                'status' => 'Completed',
                'progress' => 100,
            ])
            ->assertOk()
            ->assertHeader(EnsureTaskCorrelationId::HEADER, $correlationId)
            ->assertJson([
                'success' => true,
                'moved' => true,
                'task' => [
                    'id' => $taskId,
                    'task_uid' => $taskUid,
                    'status' => 'Completed',
                    'progress' => 100,
                ],
            ]);

        $completed = $task->fresh();
        $this->assertSame($taskId, $response->json('task.id'));
        $this->assertSame($taskCount, Task::withTrashed()->count());
        $this->assertSame($taskUid, $completed->task_uid);
        $this->assertSame('Completed', $completed->status);
        $this->assertSame(100, $completed->progress);
        $this->assertNotNull($completed->completed_at);
        $this->assertSame($manager->id, $completed->completed_by);
        $this->assertNull($completed->deleted_at);
        $this->assertDatabaseCount('completed_tasks', 0);
        $this->assertDatabaseHas('task_histories', [
            'task_id' => $taskId,
            'completed_task_id' => null,
            'action' => 'completed',
            'user_id' => $manager->id,
        ]);

        $event = TaskEvent::query()->sole();
        $this->assertSame(TaskEventRecorder::COMPLETED, $event->event_type);
        $this->assertSame($correlationId, $event->correlation_id);
        $this->assertSame($manager->id, $event->actor_id);
        $this->assertSame('web', $event->source);
        $this->assertSame(['status', 'progress', 'completed_at', 'completed_by'], array_keys($event->changed_fields));
        $this->assertSame(['before' => 'In Progress', 'after' => 'Completed'], $event->changed_fields['status']);
        $this->assertSame(['before' => 60, 'after' => 100], $event->changed_fields['progress']);

        Notification::assertSentToTimes($assignee, TaskCompletedNotification::class, 1);
        Notification::assertSentTo(
            $assignee,
            TaskCompletedNotification::class,
            fn (TaskCompletedNotification $notification) => $notification->toArray($assignee)['task_uid'] === $taskUid
                && $notification->toArray($assignee)['task_id'] === $taskId,
        );
    }

    public function test_canonical_reopen_preserves_identity_clears_metadata_and_records_inverse_changes(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $task = $this->task($project, $assignee, ['progress' => 60]);
        $taskId = $task->id;
        $taskUid = $task->task_uid;
        $completedAt = CarbonImmutable::parse('2026-07-19T12:00:00+05:45');
        $reopenedAt = CarbonImmutable::parse('2026-07-19T13:00:00+05:45');
        $service = app(TaskLifecycleService::class);

        $completed = $service->complete(
            $task,
            $manager,
            context: TaskOperationContext::test($manager->id, 'complete-operation', $completedAt),
        );
        $reopened = $service->reopen(
            $completed,
            $manager,
            TaskOperationContext::test($manager->id, 'reopen-operation', $reopenedAt),
        );

        $this->assertSame($taskId, $reopened->id);
        $this->assertSame($taskUid, $reopened->task_uid);
        $this->assertSame(1, Task::withTrashed()->count());
        $this->assertSame('In Progress', $reopened->status);
        $this->assertSame(99, $reopened->progress);
        $this->assertNull($reopened->completed_at);
        $this->assertNull($reopened->completed_by);
        $this->assertNull($reopened->deleted_at);
        $this->assertDatabaseCount('completed_tasks', 0);
        $this->assertDatabaseHas('task_histories', [
            'task_id' => $taskId,
            'completed_task_id' => null,
            'action' => 'reverted',
            'user_id' => $manager->id,
        ]);

        $event = $reopened->events()->where('event_type', TaskEventRecorder::REOPENED)->sole();
        $this->assertSame(2, $event->sequence);
        $this->assertSame('reopen-operation', $event->correlation_id);
        $this->assertTrue($reopenedAt->equalTo($event->occurred_at));
        $this->assertSame(['status', 'progress', 'completed_at', 'completed_by'], array_keys($event->changed_fields));
        $this->assertSame(['before' => 'Completed', 'after' => 'In Progress'], $event->changed_fields['status']);
        $this->assertSame(['before' => 100, 'after' => 99], $event->changed_fields['progress']);
        $this->assertNull($event->changed_fields['completed_at']['after']);
        $this->assertNull($event->changed_fields['completed_by']['after']);
        Notification::assertSentToTimes($assignee, TaskRevertedNotification::class, 1);
        Notification::assertSentTo(
            $assignee,
            TaskRevertedNotification::class,
            fn (TaskRevertedNotification $notification) => $notification->toArray($assignee)['task_uid'] === $taskUid
                && $notification->toArray($assignee)['task_id'] === $taskId,
        );
    }

    public function test_duplicate_completion_and_reopen_are_deterministic_and_side_effect_free(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $service = app(TaskLifecycleService::class);
        $task = $this->task($project, $assignee);
        $completed = $service->complete($task, $manager);
        $afterCompletion = $this->lifecycleCounts();

        try {
            $service->complete($completed, $manager);
            $this->fail('Expected duplicate completion to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame('Task is already completed.', $exception->errors()['task'][0]);
            $this->assertSame($afterCompletion, $this->lifecycleCounts());
            Notification::assertSentToTimes($assignee, TaskCompletedNotification::class, 1);
        }

        $reopened = $service->reopen($completed, $manager);
        $afterReopen = $this->lifecycleCounts();

        try {
            $service->reopen($reopened, $manager);
            $this->fail('Expected duplicate reopen to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame('Task is already active.', $exception->errors()['task'][0]);
            $this->assertSame($afterReopen, $this->lifecycleCounts());
            Notification::assertSentToTimes($assignee, TaskRevertedNotification::class, 1);
        }
    }

    public function test_service_authorization_denies_unrelated_and_inactive_actors_without_side_effects(): void
    {
        [$manager, $project, $assignee, $coworker] = $this->managedProject(withCoworker: true);
        $outside = $this->userWithRole('team_member');
        $inactive = $this->userWithRole('team_member', ['active' => false]);
        $task = $this->task($project, $assignee);
        $before = $this->lifecycleCounts();

        foreach ([$coworker, $outside, $inactive] as $actor) {
            try {
                app(TaskLifecycleService::class)->complete($task, $actor);
                $this->fail('Expected unauthorized completion to fail.');
            } catch (AuthorizationException) {
                $this->assertSame($before, $this->lifecycleCounts());
            }
        }

        $completed = app(TaskLifecycleService::class)->complete($task, $manager);
        $completedCounts = $this->lifecycleCounts();

        foreach ([$coworker, $outside, $inactive] as $actor) {
            try {
                app(TaskLifecycleService::class)->reopen($completed, $actor);
                $this->fail('Expected unauthorized reopen to fail.');
            } catch (AuthorizationException) {
                $this->assertSame($completedCounts, $this->lifecycleCounts());
            }
        }
    }

    public function test_controller_authorization_denies_unrelated_canonical_completion_and_reopen(): void
    {
        [$manager, $project, $assignee, $coworker] = $this->managedProject(withCoworker: true);
        $task = $this->task($project, $assignee);
        $before = $this->lifecycleCounts();

        $this->actingAs($coworker)
            ->putJson(route('tasks.update', $task), [
                'status' => 'Completed',
                'progress' => 100,
            ])
            ->assertForbidden();

        $this->assertSame($before, $this->lifecycleCounts());
        $completed = app(TaskLifecycleService::class)->complete($task, $manager);
        $afterCompletion = $this->lifecycleCounts();

        $this->actingAs($coworker)
            ->postJson(route('tasks.reopen', $completed))
            ->assertForbidden();

        $this->assertSame($afterCompletion, $this->lifecycleCounts());
        $this->assertSame('Completed', $completed->fresh()->status);
    }

    public function test_authorized_manager_project_manager_and_assignee_keep_lifecycle_access(): void
    {
        [$manager, $project, $assignee, , $projectManager] = $this->managedProject(withCoworker: true);
        $service = app(TaskLifecycleService::class);

        $managerTask = $this->task($project, $assignee, ['title' => 'Manager transition']);
        $service->reopen($service->complete($managerTask, $manager), $manager);

        $projectManagerTask = $this->task($project, $assignee, ['title' => 'Project manager transition']);
        $service->reopen($service->complete($projectManagerTask, $projectManager), $projectManager);

        $assigneeTask = $this->task($project, $assignee, ['title' => 'Assignee transition']);
        $service->reopen($service->complete($assigneeTask, $assignee), $assignee);

        $this->assertSame(3, Task::query()->where('status', 'In Progress')->count());
        $this->assertSame(3, TaskEvent::query()->where('event_type', TaskEventRecorder::COMPLETED)->count());
        $this->assertSame(3, TaskEvent::query()->where('event_type', TaskEventRecorder::REOPENED)->count());
    }

    public function test_bulk_completion_deduplicates_ids_and_transitions_every_task_once(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $first = $this->task($project, $assignee, ['title' => 'First bulk task']);
        $second = $this->task($project, $assignee, ['title' => 'Second bulk task']);

        $this->actingAs($manager)
            ->postJson(route('tasks.bulk-complete'), [
                'task_ids' => [$second->id, $first->id, $first->id],
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(2, Task::query()->where('status', 'Completed')->count());
        $this->assertSame(2, TaskEvent::query()->where('event_type', TaskEventRecorder::COMPLETED)->count());
        $this->assertSame(2, TaskHistory::query()->where('action', 'bulk_completed')->count());
        $this->assertDatabaseCount('completed_tasks', 0);
        Notification::assertSentToTimes($assignee, TaskCompletedNotification::class, 2);
    }

    public function test_bulk_completion_is_all_or_nothing_for_invalid_state_and_authorization(): void
    {
        [$manager, $project, $assignee, , $projectManager] = $this->managedProject(withCoworker: true);
        $active = $this->task($project, $assignee, ['title' => 'Active bulk task']);
        $alreadyCompleted = $this->task($project, $assignee, [
            'title' => 'Already completed bulk task',
            'status' => 'Completed',
            'progress' => 100,
            'completed_at' => now(),
            'completed_by' => $manager->id,
        ]);

        $this->actingAs($manager)
            ->postJson(route('tasks.bulk-complete'), ['task_ids' => [$active->id, $alreadyCompleted->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('task');

        $this->assertSame('In Progress', $active->fresh()->status);
        $this->assertDatabaseCount('task_events', 0);
        $this->assertDatabaseCount('task_histories', 0);
        Notification::assertNothingSent();

        $otherManager = $this->userWithRole('project_manager');
        $otherProject = Project::factory()->create(['project_manager_id' => $otherManager->id]);
        $otherAssignee = $this->userWithRole('team_member');
        $otherProject->members()->attach($otherAssignee->id);
        $outsideTask = $this->task($otherProject, $otherAssignee);

        $this->actingAs($projectManager)
            ->postJson(route('tasks.bulk-complete'), ['task_ids' => [$active->id, $outsideTask->id]])
            ->assertForbidden();

        $this->assertSame('In Progress', $active->fresh()->status);
        $this->assertSame('In Progress', $outsideTask->fresh()->status);
        $this->assertDatabaseCount('task_events', 0);
    }

    public function test_legacy_route_reopens_only_the_explicitly_mapped_original_row(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $original = $this->task($project, $assignee, [
            'title' => 'Original title',
            'progress' => 40,
        ]);
        $originalId = $original->id;
        $originalUid = $original->task_uid;
        $original->delete();
        $legacy = CompletedTask::query()->create([
            'original_task_id' => $originalId,
            'project_id' => $project->id,
            'title' => 'Explicit snapshot title',
            'description' => 'Snapshot content',
            'assignee_id' => $assignee->id,
            'priority' => 'High',
            'status' => 'Completed',
            'progress' => 100,
            'completed_at' => now(),
            'completed_by' => $manager->id,
        ]);

        $response = $this->actingAs($manager)
            ->postJson(route('completed-tasks.revert', $legacy))
            ->assertOk()
            ->assertJson(['success' => true]);

        $reopened = Task::query()->findOrFail($response->json('task.id'));
        $this->assertSame($originalId, $reopened->id);
        $this->assertSame($originalUid, $reopened->task_uid);
        $this->assertSame('Explicit snapshot title', $reopened->title);
        $this->assertSame('In Progress', $reopened->status);
        $this->assertSame(99, $reopened->progress);
        $this->assertSame(1, Task::withTrashed()->count());
        $this->assertTrue($legacy->fresh()->reverted);
        $this->assertDatabaseHas('task_histories', [
            'task_id' => $originalId,
            'completed_task_id' => $legacy->id,
            'action' => 'reverted',
        ]);
        $this->assertDatabaseHas('task_events', [
            'task_id' => $originalId,
            'event_type' => TaskEventRecorder::REOPENED,
        ]);
        $event = $reopened->events()->where('event_type', TaskEventRecorder::REOPENED)->sole();
        $this->assertSame(
            ['before' => 'Original title', 'after' => 'Explicit snapshot title'],
            $event->changed_fields['title'],
        );
    }

    public function test_legacy_route_rejects_missing_and_ambiguous_mappings_without_guessing(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $matchingTask = $this->task($project, $assignee, ['title' => 'Matching title']);
        $unmapped = CompletedTask::query()->create([
            'original_task_id' => null,
            'project_id' => $project->id,
            'title' => $matchingTask->title,
            'assignee_id' => $matchingTask->assignee_id,
            'priority' => 'Medium',
            'status' => 'Completed',
            'progress' => 100,
            'completed_at' => now(),
        ]);

        $this->actingAs($manager)
            ->postJson(route('completed-tasks.revert', $unmapped))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('task');
        $this->assertSame('In Progress', $matchingTask->fresh()->status);

        $original = $this->task($project, $assignee, ['title' => 'Ambiguous original']);
        $original->delete();
        $first = $this->legacyCompletion($original, $manager);
        $this->legacyCompletion($original, $manager);

        $this->actingAs($manager)
            ->postJson(route('completed-tasks.revert', $first))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('task');

        $this->assertSoftDeleted('tasks', ['id' => $original->id]);
        $this->assertFalse($first->fresh()->reverted);
        $this->assertDatabaseCount('task_events', 0);
        Notification::assertNothingSent();
    }

    public function test_completed_canonical_tasks_are_excluded_from_the_active_task_page(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $active = $this->task($project, $assignee, ['title' => 'Visible active task']);
        $completed = $this->task($project, $assignee, [
            'title' => 'Hidden canonical completion',
            'status' => 'Completed',
            'progress' => 100,
            'completed_at' => now(),
            'completed_by' => $manager->id,
        ]);

        $response = $this->actingAs($manager)->get(route('tasks'))->assertOk();
        $ids = $response->viewData('tasks')->getCollection()->pluck('id')->all();

        $this->assertContains($active->id, $ids);
        $this->assertNotContains($completed->id, $ids);
    }

    public function test_completed_tasks_cannot_bypass_reopen_through_the_update_endpoint(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $completed = app(TaskLifecycleService::class)->complete($this->task($project, $assignee), $manager);
        $before = $this->lifecycleCounts();

        $this->actingAs($manager)
            ->putJson(route('tasks.update', $completed), ['comments' => 'Bypass attempt'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('task');

        $this->assertSame('Completed', $completed->fresh()->status);
        $this->assertNull($completed->fresh()->comments);
        $this->assertSame($before, $this->lifecycleCounts());
    }

    public function test_create_as_completed_keeps_one_row_and_records_created_then_completed(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();

        $response = $this->actingAs($manager)
            ->postJson(route('tasks.store'), [
                'title' => 'Completed on create',
                'project_id' => $project->id,
                'assignee_id' => $assignee->id,
                'priority' => 'High',
                'status' => 'Completed',
                'progress' => 100,
            ])
            ->assertOk()
            ->assertJson(['success' => true, 'moved' => true]);

        $task = Task::query()->findOrFail($response->json('task.id'));
        $this->assertSame('Completed', $task->status);
        $this->assertNotNull($task->completed_at);
        $this->assertSame($manager->id, $task->completed_by);
        $this->assertNull($task->deleted_at);
        $this->assertSame(
            [TaskEventRecorder::CREATED, TaskEventRecorder::COMPLETED],
            $task->events()->pluck('event_type')->all(),
        );
        $this->assertDatabaseCount('completed_tasks', 0);
    }

    private function managedProject(bool $withCoworker = false): array
    {
        $manager = $this->userWithRole('manager');
        $projectManager = $this->userWithRole('project_manager');
        $project = Project::factory()->create(['project_manager_id' => $projectManager->id]);
        $assignee = $this->userWithRole('team_member');
        $members = [$assignee->id];
        $coworker = null;

        if ($withCoworker) {
            $coworker = $this->userWithRole('team_member');
            $members[] = $coworker->id;
        }

        $project->members()->attach($members);

        return [$manager, $project, $assignee, $coworker, $projectManager];
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role_id' => Role::query()->where('name', $role)->value('id'),
        ], $attributes));
    }

    private function task(Project $project, User $assignee, array $attributes = []): Task
    {
        return Task::query()->create(array_merge([
            'project_id' => $project->id,
            'title' => 'Canonical lifecycle task',
            'description' => 'Preserve canonical content',
            'assignee_id' => $assignee->id,
            'priority' => 'Medium',
            'status' => 'In Progress',
            'progress' => 40,
        ], $attributes));
    }

    private function legacyCompletion(Task $original, User $manager): CompletedTask
    {
        return CompletedTask::query()->create([
            'original_task_id' => $original->id,
            'project_id' => $original->project_id,
            'title' => $original->title,
            'description' => $original->description,
            'assignee_id' => $original->assignee_id,
            'priority' => $original->priority,
            'status' => 'Completed',
            'progress' => 100,
            'completed_at' => now(),
            'completed_by' => $manager->id,
        ]);
    }

    private function lifecycleCounts(): array
    {
        return [
            'tasks' => Task::withTrashed()->count(),
            'completed_tasks' => CompletedTask::withTrashed()->count(),
            'histories' => TaskHistory::query()->count(),
            'events' => TaskEvent::query()->count(),
        ];
    }
}
