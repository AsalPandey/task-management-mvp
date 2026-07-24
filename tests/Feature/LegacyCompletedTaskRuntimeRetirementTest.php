<?php

namespace Tests\Feature;

use App\Http\Controllers\CompletedTasksController;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskHistory;
use App\Models\User;
use App\Notifications\TaskCompletedNotification;
use App\Notifications\TaskRevertedNotification;
use App\Policies\TaskPolicy;
use App\Providers\AuthServiceProvider;
use App\Services\TaskLifecycleService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

class LegacyCompletedTaskRuntimeRetirementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Notification::fake();
    }

    public function test_legacy_revert_url_returns_gone_without_querying_or_mutating_legacy_data(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $canonical = $this->task($project, $assignee, [
            'status' => 'Completed',
            'progress' => 100,
            'completed_at' => now(),
            'completed_by' => $manager->id,
        ]);
        $legacyId = $this->legacyCompletion($project, $assignee, $manager, $canonical->id);
        $legacyBefore = DB::table('completed_tasks')->where('id', $legacyId)->first();
        $historyCount = TaskHistory::query()->count();
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = mb_strtolower($query->sql);
        });

        $this->actingAs($manager)
            ->postJson(route('completed-tasks.revert', ['completedTask' => $legacyId]))
            ->assertStatus(410)
            ->assertExactJson([
                'success' => false,
                'message' => 'The legacy completed-task reopen endpoint has been retired. Reopen the canonical task instead.',
            ]);

        $runtimeQueries = $queries;

        $this->assertSame('Completed', $canonical->fresh()->status);
        $this->assertSame($historyCount, TaskHistory::query()->count());
        $this->assertEquals($legacyBefore, DB::table('completed_tasks')->where('id', $legacyId)->first());
        $this->assertFalse(
            collect($runtimeQueries)->contains(fn (string $query): bool => str_contains($query, 'completed_tasks')),
            'The retired compatibility endpoint queried the legacy completed_tasks table.',
        );
        Notification::assertNothingSent();
    }

    public function test_runtime_contracts_no_longer_register_or_accept_the_legacy_domain(): void
    {
        $this->assertFileDoesNotExist(app_path('Models/CompletedTask.php'));
        $this->assertFileDoesNotExist(app_path('Policies/CompletedTaskPolicy.php'));
        $this->assertFalse(class_exists('App\\Models\\CompletedTask', false));
        $this->assertFalse(class_exists('App\\Policies\\CompletedTaskPolicy', false));
        $this->assertFalse(method_exists(TaskLifecycleService::class, 'revert'));
        $this->assertFalse(method_exists(TaskHistory::class, 'completedTask'));
        $this->assertNotContains('completed_task_id', (new TaskHistory)->getFillable());

        $provider = new AuthServiceProvider($this->app);
        $providerReflection = new ReflectionClass($provider);
        $policiesProperty = $providerReflection->getProperty('policies');
        $policies = $policiesProperty->getValue($provider);

        $this->assertSame(TaskPolicy::class, $policies[Task::class]);
        $this->assertArrayNotHasKey('App\\Models\\CompletedTask', $policies);

        $legacyRoute = Route::getRoutes()->getByName('completed-tasks.revert');
        $this->assertNotNull($legacyRoute);
        $this->assertSame(
            CompletedTasksController::class.'@retiredRevert',
            $legacyRoute->getActionName(),
        );

        foreach ((new ReflectionClass(TaskLifecycleService::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();

                if ($type instanceof ReflectionNamedType) {
                    $this->assertNotSame('App\\Models\\CompletedTask', $type->getName());
                }
            }
        }

        foreach ([TaskCompletedNotification::class, TaskRevertedNotification::class] as $notification) {
            $type = (new ReflectionClass($notification))->getConstructor()?->getParameters()[0]->getType();

            $this->assertInstanceOf(ReflectionNamedType::class, $type);
            $this->assertSame(Task::class, $type->getName());
        }
    }

    public function test_new_notifications_contain_only_canonical_task_identifiers(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $task = $this->task($project, $assignee, [
            'status' => 'Completed',
            'progress' => 100,
            'completed_at' => now(),
            'completed_by' => $manager->id,
        ]);

        foreach ([
            new TaskCompletedNotification($task, $manager),
            new TaskRevertedNotification($task, $manager),
        ] as $notification) {
            $payload = $notification->toArray($assignee);

            $this->assertSame($task->id, $payload['task_id']);
            $this->assertSame($task->task_uid, $payload['task_uid']);
            $this->assertArrayNotHasKey('completed_task_id', $payload);
            $this->assertArrayNotHasKey('original_task_id', $payload);
        }
    }

    public function test_canonical_lifecycle_never_queries_the_legacy_completed_tasks_table(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $task = $this->task($project, $assignee);
        $taskId = $task->id;
        $taskUid = $task->task_uid;
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = mb_strtolower($query->sql);
        });

        $reopened = $this->reopenApprovedTask($this->approveTask($task, $manager), $manager);

        $this->assertSame($taskId, $reopened->id);
        $this->assertSame($taskUid, $reopened->task_uid);
        $this->assertFalse(
            collect($queries)->contains(fn (string $query): bool => str_contains($query, 'completed_tasks')),
            'The canonical lifecycle queried the legacy completed_tasks table.',
        );
    }

    public function test_legacy_history_links_remain_readable_while_new_histories_never_write_them(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $legacyId = $this->legacyCompletion($project, $assignee, $manager);
        $legacyHistoryId = DB::table('task_histories')->insertGetId([
            'task_id' => null,
            'completed_task_id' => $legacyId,
            'project_id' => $project->id,
            'original_task_id' => 12345,
            'task_title' => 'Preserved legacy history',
            'user_id' => $manager->id,
            'action' => 'legacy_completed',
            'changes' => json_encode(['status' => 'Completed'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        $legacyHistory = TaskHistory::query()->findOrFail($legacyHistoryId);
        $this->assertSame($legacyId, (int) $legacyHistory->completed_task_id);

        $task = $this->task($project, $assignee);
        $this->reopenApprovedTask($this->approveTask($task, $manager), $manager);

        $newHistories = TaskHistory::query()->where('task_id', $task->id)->get();
        $this->assertCount(2, $newHistories);
        $this->assertTrue($newHistories->every(fn (TaskHistory $history): bool => $history->completed_task_id === null));
        $this->assertSame($legacyId, (int) TaskHistory::query()->findOrFail($legacyHistoryId)->completed_task_id);
        $this->assertDatabaseHas('completed_tasks', ['id' => $legacyId]);
    }

    private function managedProject(): array
    {
        $manager = $this->userWithRole('manager');
        $projectManager = $this->userWithRole('project_manager');
        $assignee = $this->userWithRole('team_member');
        $project = Project::factory()->create(['project_manager_id' => $projectManager->id]);
        $project->members()->attach($assignee->id);

        return [$manager, $project, $assignee];
    }

    private function userWithRole(string $role): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('name', $role)->value('id'),
        ]);
    }

    private function task(Project $project, User $assignee, array $attributes = []): Task
    {
        return Task::query()->create(array_merge([
            'project_id' => $project->id,
            'title' => 'Legacy retirement canonical task',
            'assignee_id' => $assignee->id,
            'priority' => 'Medium',
            'status' => 'In Progress',
            'progress' => 50,
        ], $attributes));
    }

    private function legacyCompletion(
        Project $project,
        User $assignee,
        User $manager,
        ?int $originalTaskId = null,
    ): int {
        return (int) DB::table('completed_tasks')->insertGetId([
            'original_task_id' => $originalTaskId,
            'project_id' => $project->id,
            'title' => 'Preserved legacy completion',
            'assignee_id' => $assignee->id,
            'priority' => 'Medium',
            'status' => 'Completed',
            'progress' => 100,
            'completed_at' => now(),
            'completed_by' => $manager->id,
            'reverted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
