<?php

namespace Tests\Feature;

use App\Enums\TaskState;
use App\Http\Middleware\EnsureTaskCorrelationId;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\TaskHistory;
use App\Models\User;
use App\Notifications\TaskRevertedNotification;
use App\Services\TaskEventRecorder;
use App\Services\TaskLifecycleService;
use App\ValueObjects\TaskOperationContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_http_generic_completion_is_rejected_without_mutating_the_canonical_row(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $task = $this->task($project, $assignee, ['progress' => 60]);
        $taskId = $task->id;
        $taskUid = $task->task_uid;
        $taskCount = Task::withTrashed()->count();
        $correlationId = 'complete-request-1';

        $this->actingAs($manager)
            ->withHeader(EnsureTaskCorrelationId::HEADER, $correlationId)
            ->putJson(route('tasks.update', $task), [
                'status' => 'Completed',
                'progress' => 100,
            ])
            ->assertUnprocessable()
            ->assertHeader(EnsureTaskCorrelationId::HEADER, $correlationId);

        $unchanged = $task->fresh();
        $this->assertSame($taskCount, Task::withTrashed()->count());
        $this->assertSame($taskId, $unchanged->id);
        $this->assertSame($taskUid, $unchanged->task_uid);
        $this->assertSame('In Progress', $unchanged->status);
        $this->assertSame(60, $unchanged->progress);
        $this->assertNull($unchanged->completed_at);
        $this->assertDatabaseCount('completed_tasks', 0);
        $this->assertDatabaseCount('task_histories', 0);
        $this->assertDatabaseCount('task_events', 0);
        Notification::assertNothingSent();
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

        $completed = $this->approveTask(
            $task,
            $manager,
            TaskOperationContext::test($manager->id, 'complete-operation', $completedAt),
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
        $this->assertSame(3, $event->sequence);
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

    public function test_duplicate_approval_and_reopen_are_deterministic_and_side_effect_free(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $service = app(TaskLifecycleService::class);
        $task = $this->task($project, $assignee);
        $completed = $this->approveTask($task, $manager);
        $afterCompletion = $this->lifecycleCounts();

        $this->actingAs($manager)
            ->postJson(route('tasks.approve.override', $completed), ['override_reason' => 'Duplicate'])
            ->assertUnprocessable();
        $this->assertSame($afterCompletion, $this->lifecycleCounts());

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

    public function test_removed_completion_service_cannot_be_called_and_reopen_authorization_still_applies(): void
    {
        [$manager, $project, $assignee, $coworker] = $this->managedProject(withCoworker: true);
        $outside = $this->userWithRole('team_member');
        $inactive = $this->userWithRole('team_member', ['active' => false]);
        $this->assertFalse(method_exists(TaskLifecycleService::class, 'complete'));
        $completed = $this->approveTask($this->task($project, $assignee), $manager);
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
        $completed = $this->approveTask($task, $manager);
        $afterCompletion = $this->lifecycleCounts();

        $this->actingAs($coworker)
            ->postJson(route('tasks.reopen', $completed))
            ->assertForbidden();

        $this->assertSame($afterCompletion, $this->lifecycleCounts());
        $this->assertSame('Completed', $completed->fresh()->status);
    }

    public function test_only_assigned_reviewer_approval_completes_before_existing_reopen_access(): void
    {
        [$manager, $project, $assignee, , $projectManager] = $this->managedProject(withCoworker: true);
        $service = app(TaskLifecycleService::class);

        $projectManagerTask = $this->task($project, $assignee, ['title' => 'Project manager transition']);
        $service->reopen($this->approveTask($projectManagerTask, $projectManager), $projectManager);

        $this->actingAs($assignee)
            ->postJson(route('tasks.complete', $this->task($project, $assignee)))
            ->assertGone();

        $this->assertSame(1, TaskEvent::query()->where('event_type', TaskEventRecorder::COMPLETED)->count());
        $this->assertSame(1, TaskEvent::query()->where('event_type', TaskEventRecorder::REOPENED)->count());
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
            ->assertGone();

        $this->assertSame(0, Task::query()->where('status', TaskState::Completed->value)->count());
        $this->assertSame(0, TaskEvent::query()->where('event_type', TaskEventRecorder::COMPLETED)->count());
        $this->assertSame(0, TaskHistory::query()->where('action', 'bulk_completed')->count());
        $this->assertDatabaseCount('completed_tasks', 0);
        Notification::assertNothingSent();
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
            ->assertGone();

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
            ->assertGone();

        $this->assertSame('In Progress', $active->fresh()->status);
        $this->assertSame('In Progress', $outsideTask->fresh()->status);
        $this->assertDatabaseCount('task_events', 0);
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
        $completed = $this->approveTask($this->task($project, $assignee), $manager);
        $before = $this->lifecycleCounts();

        $this->actingAs($manager)
            ->putJson(route('tasks.update', $completed), ['comments' => 'Bypass attempt'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('task');

        $this->assertSame('Completed', $completed->fresh()->status);
        $this->assertNull($completed->fresh()->comments);
        $this->assertSame($before, $this->lifecycleCounts());
    }

    public function test_create_as_completed_is_rejected_without_creating_any_row(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();

        $this->actingAs($manager)
            ->postJson(route('tasks.store'), [
                'title' => 'Completed on create',
                'project_id' => $project->id,
                'assignee_id' => $assignee->id,
                'priority' => 'High',
                'status' => 'Completed',
                'progress' => 100,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('task_events', 0);
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

    private function lifecycleCounts(): array
    {
        return [
            'tasks' => Task::withTrashed()->count(),
            'completed_tasks' => DB::table('completed_tasks')->count(),
            'histories' => TaskHistory::query()->count(),
            'events' => TaskEvent::query()->count(),
        ];
    }
}
