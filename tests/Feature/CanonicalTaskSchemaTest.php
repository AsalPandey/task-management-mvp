<?php

namespace Tests\Feature;

use App\Enums\TaskState;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CanonicalTaskSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_fields_are_nullable_without_changing_existing_task_attributes(): void
    {
        $id = DB::table('tasks')->insertGetId([
            'title' => 'Existing task',
            'priority' => 'Medium',
            'status' => 'In Progress',
            'progress' => 40,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tasks')->insert([
            'title' => 'Another existing task',
            'priority' => 'Medium',
            'status' => 'Not Started',
            'progress' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $first = Task::query()->findOrFail($id);

        $this->assertSame('Existing task', $first->title);
        $this->assertSame('In Progress', $first->status);
        $this->assertSame(40, $first->progress);
        $this->assertNull($first->task_uid);
        $this->assertNull($first->completed_at);
        $this->assertNull($first->completed_by);
        $this->assertSame(2, Task::query()->whereNull('task_uid')->count());
    }

    public function test_task_uid_is_not_mass_assignable_and_numeric_route_binding_is_unchanged(): void
    {
        $uid = (string) Str::ulid();
        $task = $this->createTask([
            'title' => 'Route binding task',
            'task_uid' => $uid,
        ]);

        $this->assertNotSame($uid, $task->fresh()->task_uid);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $task->fresh()->task_uid);
        $this->assertSame('id', $task->getRouteKeyName());
        $this->assertSame($task->getKey(), $task->getRouteKey());
        $this->assertSame('/tasks/'.$task->id.'/edit', route('tasks.edit', $task, false));
    }

    public function test_duplicate_non_null_task_uids_are_rejected_while_multiple_nulls_are_allowed(): void
    {
        $uid = (string) Str::ulid();
        $first = $this->createTask(['title' => 'First UID task']);
        $second = $this->createTask(['title' => 'Second UID task']);
        $this->createTask(['title' => 'Second null UID task']);

        DB::table('tasks')->where('id', $first->id)->update(['task_uid' => $uid]);

        $this->expectException(QueryException::class);
        DB::table('tasks')->where('id', $second->id)->update(['task_uid' => $uid]);
    }

    public function test_completed_by_retains_the_task_when_a_user_is_deactivated_or_removed(): void
    {
        $completer = User::factory()->create();
        $task = $this->createTask(['title' => 'Completion metadata task']);
        $completedAt = now()->startOfSecond();

        $task->forceFill([
            'completed_at' => $completedAt,
            'completed_by' => $completer->id,
        ])->save();

        $this->assertTrue($task->fresh()->completed_at->equalTo($completedAt));
        $this->assertTrue($task->fresh()->completedBy->is($completer));

        $completer->forceFill(['active' => false])->save();
        $this->assertSame($completer->id, $task->fresh()->completed_by);
        $this->assertFalse($task->fresh()->completedBy->active);

        $completer->forceDelete();
        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
        $this->assertNull($task->fresh()->completed_by);
    }

    public function test_task_events_cast_json_and_relationships_are_ordered_by_sequence(): void
    {
        $actor = User::factory()->create();
        $task = $this->createTask(['title' => 'Event ordering task']);

        $second = $this->createEvent($task, 2, [
            'event_type' => 'task.updated',
            'actor_id' => $actor->id,
            'changed_fields' => ['progress' => ['before' => 20, 'after' => 40]],
            'metadata' => ['request' => ['channel' => 'web']],
        ]);
        $first = $this->createEvent($task, 1, [
            'event_type' => 'task.created',
            'actor_id' => $actor->id,
        ]);

        $this->assertSame([1, 2], $task->events()->pluck('sequence')->all());
        $this->assertTrue($first->fresh()->task->is($task));
        $this->assertTrue($second->fresh()->actor->is($actor));
        $this->assertSame(
            ['progress' => ['before' => 20, 'after' => 40]],
            $second->fresh()->changed_fields,
        );
        $this->assertSame(['request' => ['channel' => 'web']], $second->fresh()->metadata);
        $this->assertInstanceOf(\DateTimeInterface::class, $second->fresh()->occurred_at);
    }

    public function test_task_event_identity_fields_are_guarded_from_normal_mass_assignment(): void
    {
        $event = new TaskEvent([
            'event_uid' => (string) Str::ulid(),
            'task_id' => 123,
            'sequence' => 1,
            'event_type' => 'task.created',
            'source' => 'web',
        ]);

        $this->assertNull($event->event_uid);
        $this->assertNull($event->task_id);
        $this->assertNull($event->sequence);
        $this->assertSame('task.created', $event->event_type);
        $this->assertSame('web', $event->source);
    }

    public function test_duplicate_event_uids_are_rejected(): void
    {
        $task = $this->createTask(['title' => 'Event UID task']);
        $uid = (string) Str::ulid();

        $this->createEvent($task, 1, ['event_uid' => $uid]);

        $this->expectException(QueryException::class);
        $this->createEvent($task, 2, ['event_uid' => $uid]);
    }

    public function test_duplicate_task_event_sequences_are_rejected(): void
    {
        $task = $this->createTask(['title' => 'Event sequence task']);

        $this->createEvent($task, 1);

        $this->expectException(QueryException::class);
        $this->createEvent($task, 1);
    }

    public function test_task_event_foreign_keys_preserve_audit_rows(): void
    {
        $actor = User::factory()->create();
        $task = $this->createTask(['title' => 'Audit retention task']);
        $event = $this->createEvent($task, 1, ['actor_id' => $actor->id]);

        $actor->forceDelete();
        $this->assertDatabaseHas('task_events', ['id' => $event->id, 'actor_id' => null]);

        try {
            $task->forceDelete();
            $this->fail('Hard-deleting a task with audit events should be restricted.');
        } catch (QueryException) {
            $this->assertDatabaseHas('tasks', ['id' => $task->id]);
            $this->assertDatabaseHas('task_events', ['id' => $event->id]);
        }
    }

    public function test_completion_and_reopen_preserve_the_canonical_task_identity(): void
    {
        $managerRole = Role::query()->create(['name' => 'manager', 'label' => 'Manager']);
        $memberRole = Role::query()->create(['name' => 'team_member', 'label' => 'Team Member']);
        $manager = User::factory()->create(['role_id' => $managerRole->id]);
        $assignee = User::factory()->create(['role_id' => $memberRole->id]);
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $project->members()->attach($assignee->id);
        $task = $this->createTask([
            'project_id' => $project->id,
            'assignee_id' => $assignee->id,
            'created_by' => $manager->id,
            'assigned_by' => $manager->id,
            'title' => 'Legacy lifecycle task',
            'status' => 'In Progress',
            'progress' => 100,
        ]);

        $taskId = $task->id;
        $taskUid = $task->task_uid;
        $completed = $this->approveTask($task, $manager);

        $this->assertSame($taskId, $completed->id);
        $this->assertSame($taskUid, $completed->task_uid);
        $this->assertDatabaseHas('tasks', [
            'id' => $taskId,
            'status' => TaskState::Completed->value,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseCount('completed_tasks', 0);

        $reopened = $this->reopenApprovedTask($completed, $manager);

        $this->assertSame($taskId, $reopened->id);
        $this->assertSame($taskUid, $reopened->task_uid);
        $this->assertDatabaseHas('tasks', [
            'id' => $taskId,
            'status' => TaskState::RevisionRequested->value,
            'deleted_at' => null,
        ]);
        $this->assertSame(
            ['task.approved', 'task.completed', 'task.reopened', 'task.revision_requested'],
            $reopened->events()->pluck('event_type')->all(),
        );
    }

    private function createTask(array $attributes = []): Task
    {
        return Task::query()->create(array_merge([
            'title' => 'Canonical schema task',
            'priority' => 'Medium',
            'status' => 'Not Started',
            'progress' => 0,
        ], $attributes));
    }

    private function createEvent(Task $task, int $sequence, array $attributes = []): TaskEvent
    {
        return TaskEvent::query()->forceCreate(array_merge([
            'event_uid' => (string) Str::ulid(),
            'task_id' => $task->id,
            'sequence' => $sequence,
            'event_type' => 'task.created',
            'actor_id' => null,
            'source' => 'web',
            'correlation_id' => null,
            'changed_fields' => null,
            'metadata' => null,
            'occurred_at' => now(),
        ], $attributes));
    }
}
