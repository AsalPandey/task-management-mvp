<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskLifecycleService;
use App\Support\UlidGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TaskUidTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_tasks_receive_distinct_uppercase_ulids(): void
    {
        $first = Task::query()->create(['title' => 'First UID task']);
        $second = Task::query()->create(['title' => 'Second UID task']);

        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $first->task_uid);
        $this->assertSame(26, strlen($first->task_uid));
        $this->assertNotSame($first->task_uid, $second->task_uid);
    }

    public function test_task_uid_is_not_mass_assignable_and_normal_updates_cannot_replace_it(): void
    {
        $supplied = '00000000000000000000000001';
        $replacement = '00000000000000000000000002';
        $task = Task::query()->create([
            'title' => 'Immutable UID task',
            'task_uid' => $supplied,
        ]);
        $assigned = $task->task_uid;

        $this->assertNotSame($supplied, $assigned);

        $task->forceFill([
            'title' => 'Updated immutable UID task',
            'task_uid' => $replacement,
        ])->save();

        $this->assertSame('Updated immutable UID task', $task->fresh()->title);
        $this->assertSame($assigned, $task->fresh()->task_uid);
    }

    public function test_lifecycle_creation_retries_a_task_uid_collision(): void
    {
        $this->seed();
        Notification::fake();
        $collision = '00000000000000000000000001';
        $replacement = '00000000000000000000000002';
        $this->insertTaskWithoutModel(['task_uid' => $collision]);
        $this->app->instance(UlidGenerator::class, new SequenceTaskUlidGenerator([
            $collision,
            $collision,
            $replacement,
        ]));
        $manager = User::factory()->create([
            'role_id' => Role::query()->where('name', 'manager')->value('id'),
        ]);
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);

        $task = app(TaskLifecycleService::class)->create([
            'title' => 'Collision retry task',
            'project_id' => $project->id,
            'assignee_id' => null,
            'priority' => 'Medium',
            'status' => 'Not Started',
            'progress' => 0,
        ], $manager);

        $this->assertInstanceOf(Task::class, $task);
        $this->assertSame($replacement, $task->task_uid);
        $this->assertSame(2, Task::withTrashed()->distinct()->count('task_uid'));
    }

    public function test_numeric_route_binding_remains_unchanged(): void
    {
        $task = Task::query()->create(['title' => 'Numeric route task']);

        $this->assertSame('id', $task->getRouteKeyName());
        $this->assertSame($task->id, $task->getRouteKey());
        $this->assertSame('/tasks/'.$task->id.'/edit', route('tasks.edit', $task, false));
    }

    private function insertTaskWithoutModel(array $attributes = []): int
    {
        return DB::table('tasks')->insertGetId(array_merge([
            'title' => 'Existing task',
            'priority' => 'Medium',
            'status' => 'Not Started',
            'progress' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }
}

class SequenceTaskUlidGenerator extends UlidGenerator
{
    private int $position = 0;

    public function __construct(private readonly array $ulids) {}

    public function generate(): string
    {
        $position = min($this->position++, count($this->ulids) - 1);

        return $this->ulids[$position];
    }
}
