<?php

namespace Tests\Feature;

use App\Enums\TaskState;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskApproval;
use App\Models\TaskEvent;
use App\Models\TaskHistory;
use App\Models\TaskRevisionCycle;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Services\TaskEventRecorder;
use App\Support\UlidGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TaskLockVersionMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_database_has_lock_version_column_with_default_value(): void
    {
        $this->assertTrue(Schema::hasColumn('tasks', 'lock_version'));

        $ulid = app(UlidGenerator::class)->generate();
        $id = DB::table('tasks')->insertGetId([
            'task_uid' => $ulid,
            'title' => 'Fresh task without explicit lock_version',
            'status' => 'not_started',
            'priority' => 'Medium',
            'progress' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $task = Task::query()->findOrFail($id);
        $this->assertSame(1, $task->lock_version);
    }

    public function test_legacy_upgrade_backfills_all_task_states_and_preserves_identities_and_audit_data(): void
    {
        $this->seed();
        $manager = User::factory()->create(['role_id' => Role::query()->where('name', 'manager')->value('id')]);
        $assignee = User::factory()->create(['role_id' => Role::query()->where('name', 'team_member')->value('id')]);
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $project->members()->attach($assignee->id);

        $ulids = app(UlidGenerator::class);

        // 1. Active task
        $activeUid = $ulids->generate();
        $activeId = DB::table('tasks')->insertGetId([
            'task_uid' => $activeUid,
            'title' => 'Legacy active task',
            'project_id' => $project->id,
            'assignee_id' => $assignee->id,
            'reviewer_id' => $manager->id,
            'status' => TaskState::InProgress->value,
            'priority' => 'High',
            'progress' => 50,
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(1),
        ]);

        // 2. Completed task
        $completedUid = $ulids->generate();
        $completedId = DB::table('tasks')->insertGetId([
            'task_uid' => $completedUid,
            'title' => 'Legacy completed task',
            'project_id' => $project->id,
            'assignee_id' => $assignee->id,
            'reviewer_id' => $manager->id,
            'status' => TaskState::Completed->value,
            'priority' => 'Medium',
            'progress' => 100,
            'completed_at' => now()->subDays(2),
            'completed_by' => $manager->id,
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(2),
        ]);

        // 3. Cancelled task
        $cancelledUid = $ulids->generate();
        $cancelledId = DB::table('tasks')->insertGetId([
            'task_uid' => $cancelledUid,
            'title' => 'Legacy cancelled task',
            'project_id' => $project->id,
            'assignee_id' => $assignee->id,
            'reviewer_id' => $manager->id,
            'status' => TaskState::Cancelled->value,
            'priority' => 'Low',
            'progress' => 10,
            'cancelled_at' => now()->subDay(),
            'cancelled_by' => $manager->id,
            'cancellation_reason' => 'Legacy project scope reduction',
            'created_at' => now()->subDays(7),
            'updated_at' => now()->subDay(),
        ]);

        // 4. Soft-deleted task
        $softDeletedUid = $ulids->generate();
        $softDeletedId = DB::table('tasks')->insertGetId([
            'task_uid' => $softDeletedUid,
            'title' => 'Legacy soft-deleted task',
            'project_id' => $project->id,
            'assignee_id' => $assignee->id,
            'reviewer_id' => $manager->id,
            'status' => TaskState::NotStarted->value,
            'priority' => 'Low',
            'progress' => 0,
            'created_at' => now()->subDays(20),
            'updated_at' => now()->subDays(15),
            'deleted_at' => now()->subDays(15),
        ]);

        // Attach related records: histories, events, submissions, approvals, cycles
        TaskHistory::query()->create([
            'task_id' => $activeId,
            'project_id' => $project->id,
            'task_title' => 'Legacy active task',
            'user_id' => $manager->id,
            'action' => 'created',
            'changes' => ['title' => 'Legacy active task'],
        ]);

        $event = new TaskEvent;
        $event->forceFill([
            'event_uid' => $ulids->generate(),
            'task_id' => $activeId,
            'sequence' => 1,
            'event_type' => TaskEventRecorder::CREATED,
            'actor_id' => $manager->id,
            'source' => 'test',
            'correlation_id' => 'legacy-event-1',
            'changed_fields' => ['title' => ['before' => null, 'after' => 'Legacy active task']],
            'occurred_at' => now()->subDays(5),
        ])->save();

        $cycle = new TaskRevisionCycle;
        $cycle->forceFill([
            'task_id' => $completedId,
            'cycle_number' => 1,
            'requested_by' => $manager->id,
            'formal_feedback' => 'Initial revision cycle feedback',
            'started_at' => now()->subDays(4),
            'resolved_at' => now()->subDays(2),
        ])->save();

        $submission = new TaskSubmission;
        $submission->forceFill([
            'task_id' => $completedId,
            'revision_cycle_id' => $cycle->id,
            'submitted_by' => $assignee->id,
            'submission_note' => 'Final submission note for legacy task',
            'submitted_at' => now()->subDays(3),
        ])->save();

        $approval = new TaskApproval;
        $approval->forceFill([
            'task_id' => $completedId,
            'submission_id' => $submission->id,
            'revision_cycle_id' => $cycle->id,
            'approved_by' => $manager->id,
            'approval_comment' => 'Approved on legacy verification',
            'approved_at' => now()->subDays(2),
        ])->save();

        // Simulate legacy baseline by resetting lock_version to null or running migration backfill
        $migration = require database_path('migrations/2026_09_19_000003_add_lock_version_to_tasks_table.php');

        // Rollback migration
        $migration->down();
        $this->assertFalse(Schema::hasColumn('tasks', 'lock_version'));

        // Verify task IDs and UIDs still intact
        $this->assertSame($activeUid, DB::table('tasks')->where('id', $activeId)->value('task_uid'));
        $this->assertSame($completedUid, DB::table('tasks')->where('id', $completedId)->value('task_uid'));
        $this->assertSame($cancelledUid, DB::table('tasks')->where('id', $cancelledId)->value('task_uid'));
        $this->assertSame($softDeletedUid, DB::table('tasks')->where('id', $softDeletedId)->value('task_uid'));

        // Reapply migration
        $migration->up();
        $this->assertTrue(Schema::hasColumn('tasks', 'lock_version'));

        // Verify all tasks receive baseline version 1
        $activeTask = Task::query()->findOrFail($activeId);
        $this->assertSame(1, $activeTask->lock_version);
        $this->assertSame($activeUid, $activeTask->task_uid);
        $this->assertSame('Legacy active task', $activeTask->title);

        $completedTask = Task::query()->findOrFail($completedId);
        $this->assertSame(1, $completedTask->lock_version);
        $this->assertSame($completedUid, $completedTask->task_uid);
        $this->assertSame(TaskState::Completed, $completedTask->machineState());

        $cancelledTask = Task::query()->findOrFail($cancelledId);
        $this->assertSame(1, $cancelledTask->lock_version);
        $this->assertSame($cancelledUid, $cancelledTask->task_uid);
        $this->assertSame(TaskState::Cancelled, $cancelledTask->machineState());

        $softDeletedTask = Task::withTrashed()->findOrFail($softDeletedId);
        $this->assertSame(1, $softDeletedTask->lock_version);
        $this->assertSame($softDeletedUid, $softDeletedTask->task_uid);
        $this->assertTrue($softDeletedTask->trashed());

        // Verify related entities were completely preserved
        $this->assertSame(1, TaskHistory::query()->where('task_id', $activeId)->count());
        $this->assertSame(1, TaskEvent::query()->where('task_id', $activeId)->count());
        $this->assertSame(1, TaskRevisionCycle::query()->where('task_id', $completedId)->count());
        $this->assertSame(1, TaskSubmission::query()->where('task_id', $completedId)->count());
        $this->assertSame(1, TaskApproval::query()->where('task_id', $completedId)->count());
    }
}
