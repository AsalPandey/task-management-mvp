<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskRevisionCycle;
use App\Models\TaskSubmission;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkflowSchemaExpansionTest extends TestCase
{
    use RefreshDatabase;

    public function test_workflow_fields_expand_tasks_without_becoming_mass_assignable(): void
    {
        $task = Task::query()->create([
            'title' => 'Expansion task',
            'priority' => 'High',
            'status' => 'In Progress',
            'progress' => 40,
            'reviewer_id' => User::factory()->create()->id,
            'revision_count' => 9,
            'priority_tier' => 'tier_1',
        ]);

        $fresh = $task->fresh();

        $this->assertNull($fresh->reviewer_id);
        $this->assertSame(0, $fresh->revision_count);
        $this->assertNull($fresh->priority_tier);
        $this->assertSame('In Progress', $fresh->status);

        foreach ([
            'reviewer_id',
            'started_at',
            'submitted_at',
            'review_started_at',
            'approved_at',
            'approved_by',
            'execution_due_date',
            'review_due_date',
            'revision_due_date',
            'held_at',
            'held_by',
            'hold_reason',
            'cancelled_at',
            'cancelled_by',
            'cancellation_reason',
            'revision_count',
            'active_revision_cycle_id',
            'importance_level',
            'manual_urgency_level',
            'calculated_urgency_score',
            'effective_urgency_level',
            'priority_tier',
            'priority_calculated_at',
            'priority_override_reason',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('tasks', $column), "Missing tasks.{$column}");
        }

        $this->assertTrue(Schema::hasColumn('tasks', 'due_date'));
    }

    public function test_task_workflow_relationships_and_date_casts_work(): void
    {
        $reviewer = User::factory()->create();
        $approver = User::factory()->create();
        $holder = User::factory()->create();
        $canceller = User::factory()->create();
        $task = $this->task();

        $task->forceFill([
            'reviewer_id' => $reviewer->id,
            'approved_by' => $approver->id,
            'held_by' => $holder->id,
            'cancelled_by' => $canceller->id,
            'execution_due_date' => '2026-08-01',
            'review_due_date' => '2026-08-02',
            'revision_due_date' => '2026-08-03',
            'started_at' => '2026-07-23 08:00:00',
            'submitted_at' => '2026-07-23 09:00:00',
            'review_started_at' => '2026-07-23 10:00:00',
            'approved_at' => '2026-07-23 11:00:00',
            'held_at' => '2026-07-23 12:00:00',
            'cancelled_at' => '2026-07-23 13:00:00',
            'revision_count' => 2,
            'calculated_urgency_score' => 75,
            'priority_calculated_at' => '2026-07-23 14:00:00',
        ])->save();

        $fresh = $task->fresh();

        $this->assertTrue($fresh->reviewer->is($reviewer));
        $this->assertTrue($fresh->approvedBy->is($approver));
        $this->assertTrue($fresh->heldBy->is($holder));
        $this->assertTrue($fresh->cancelledBy->is($canceller));
        $this->assertSame('2026-08-01', $fresh->execution_due_date->toDateString());
        $this->assertSame('2026-08-02', $fresh->review_due_date->toDateString());
        $this->assertSame('2026-08-03', $fresh->revision_due_date->toDateString());
        $this->assertInstanceOf(\DateTimeInterface::class, $fresh->started_at);
        $this->assertInstanceOf(\DateTimeInterface::class, $fresh->submitted_at);
        $this->assertInstanceOf(\DateTimeInterface::class, $fresh->review_started_at);
        $this->assertInstanceOf(\DateTimeInterface::class, $fresh->approved_at);
        $this->assertInstanceOf(\DateTimeInterface::class, $fresh->held_at);
        $this->assertInstanceOf(\DateTimeInterface::class, $fresh->cancelled_at);
        $this->assertInstanceOf(\DateTimeInterface::class, $fresh->priority_calculated_at);
        $this->assertSame(2, $fresh->revision_count);
        $this->assertSame(75, $fresh->calculated_urgency_score);
    }

    public function test_workflow_actor_foreign_keys_set_null_without_deleting_the_task(): void
    {
        $reviewer = User::factory()->create();
        $approver = User::factory()->create();
        $holder = User::factory()->create();
        $canceller = User::factory()->create();
        $task = $this->task();

        $task->forceFill([
            'reviewer_id' => $reviewer->id,
            'approved_by' => $approver->id,
            'held_by' => $holder->id,
            'cancelled_by' => $canceller->id,
        ])->save();

        collect([$reviewer, $approver, $holder, $canceller])->each->forceDelete();

        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'reviewer_id' => null,
            'approved_by' => null,
            'held_by' => null,
            'cancelled_by' => null,
        ]);
    }

    public function test_revision_cycles_are_unique_related_and_protected_from_silent_deletion(): void
    {
        $requester = User::factory()->create();
        $task = $this->task();
        $cycle = TaskRevisionCycle::query()->create([
            'task_id' => $task->id,
            'cycle_number' => 1,
            'requested_by' => $requester->id,
            'requested_at' => now(),
            'formal_feedback' => 'Please revise the supporting evidence.',
            'revision_due_date' => '2026-08-05',
            'origin' => 'review_revision',
        ]);

        $task->forceFill([
            'revision_count' => 1,
            'active_revision_cycle_id' => $cycle->id,
        ])->save();

        $this->assertTrue($cycle->fresh()->task->is($task));
        $this->assertTrue($cycle->fresh()->requestedBy->is($requester));
        $this->assertTrue($task->fresh()->revisionCycles->contains($cycle));
        $this->assertTrue($task->fresh()->activeRevisionCycle->is($cycle));
        $this->assertSame('2026-08-05', $cycle->fresh()->revision_due_date->toDateString());

        $requester->forceDelete();
        $this->assertDatabaseHas('task_revision_cycles', [
            'id' => $cycle->id,
            'requested_by' => null,
        ]);

        try {
            TaskRevisionCycle::query()->create([
                'task_id' => $task->id,
                'cycle_number' => 1,
            ]);
            $this->fail('Duplicate task revision-cycle numbers must be rejected.');
        } catch (QueryException) {
            $this->assertDatabaseCount('task_revision_cycles', 1);
        }

        try {
            $cycle->delete();
            $this->fail('An active revision cycle must not be silently deleted.');
        } catch (QueryException) {
            $this->assertDatabaseHas('task_revision_cycles', ['id' => $cycle->id]);
            $this->assertSame($cycle->id, $task->fresh()->active_revision_cycle_id);
        }
    }

    public function test_submissions_support_initial_and_revision_history_and_preserve_links(): void
    {
        $submitter = User::factory()->create();
        $task = $this->task();
        $cycle = TaskRevisionCycle::query()->create([
            'task_id' => $task->id,
            'cycle_number' => 1,
        ]);
        $initial = TaskSubmission::query()->create([
            'task_id' => $task->id,
            'revision_cycle_id' => null,
            'submitted_by' => $submitter->id,
            'submitted_at' => now()->subHour(),
            'submission_note' => 'Initial submission',
        ]);
        $revision = TaskSubmission::query()->create([
            'task_id' => $task->id,
            'revision_cycle_id' => $cycle->id,
            'submitted_by' => $submitter->id,
            'submitted_at' => now(),
            'submission_note' => 'Revision submission',
        ]);

        $this->assertNull($initial->fresh()->revisionCycle);
        $this->assertTrue($revision->fresh()->revisionCycle->is($cycle));
        $this->assertTrue($revision->fresh()->submittedBy->is($submitter));
        $this->assertEqualsCanonicalizing(
            [$initial->id, $revision->id],
            $task->fresh()->submissions->pluck('id')->all(),
        );
        $this->assertTrue($cycle->fresh()->submissions->contains($revision));

        $submitter->forceDelete();
        $this->assertDatabaseHas('task_submissions', [
            'id' => $initial->id,
            'submitted_by' => null,
        ]);
        $this->assertDatabaseHas('task_submissions', [
            'id' => $revision->id,
            'submitted_by' => null,
        ]);

        try {
            $task->forceDelete();
            $this->fail('A task with workflow history must not be hard deleted.');
        } catch (QueryException) {
            $this->assertDatabaseHas('tasks', ['id' => $task->id]);
            $this->assertDatabaseHas('task_submissions', ['id' => $initial->id]);
            $this->assertDatabaseHas('task_revision_cycles', ['id' => $cycle->id]);
        }
    }

    public function test_upgrade_backfills_execution_deadlines_without_changing_legacy_identity_or_state(): void
    {
        $migrationPaths = [
            database_path('migrations/2026_07_23_000001_add_workflow_foundation_to_tasks_table.php'),
            database_path('migrations/2026_07_23_000002_create_task_revision_cycles_table.php'),
            database_path('migrations/2026_07_23_000003_create_task_submissions_table.php'),
            database_path('migrations/2026_07_23_000004_add_active_revision_cycle_foreign_key_to_tasks_table.php'),
        ];
        $migrations = array_map(static fn (string $path) => require $path, $migrationPaths);

        foreach (array_reverse($migrations) as $migration) {
            $migration->down();
        }

        $uid = (string) Str::ulid();
        $updatedAt = '2026-07-20 12:34:56';
        $datedId = DB::table('tasks')->insertGetId([
            'task_uid' => $uid,
            'title' => 'Legacy dated task',
            'priority' => 'Medium',
            'status' => 'On Hold',
            'progress' => 55,
            'due_date' => '2026-08-17',
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
            'deleted_at' => '2026-07-21 00:00:00',
        ]);
        $nullId = DB::table('tasks')->insertGetId([
            'task_uid' => (string) Str::ulid(),
            'title' => 'Legacy task without deadline',
            'priority' => 'Low',
            'status' => 'Not Started',
            'progress' => 0,
            'due_date' => null,
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);

        foreach ($migrations as $migration) {
            $migration->up();
        }

        $dated = DB::table('tasks')->where('id', $datedId)->first();
        $withoutDeadline = DB::table('tasks')->where('id', $nullId)->first();

        $this->assertSame($datedId, $dated->id);
        $this->assertSame($uid, $dated->task_uid);
        $this->assertSame('On Hold', $dated->status);
        $this->assertSame('2026-08-17', $dated->due_date);
        $this->assertSame('2026-08-17', $dated->execution_due_date);
        $this->assertSame($updatedAt, $dated->updated_at);
        $this->assertNotNull($dated->deleted_at);
        $this->assertNull($withoutDeadline->due_date);
        $this->assertNull($withoutDeadline->execution_due_date);
        $this->assertSame(0, $dated->revision_count);

    }

    private function task(): Task
    {
        return Task::query()->create([
            'title' => 'Workflow schema task',
            'priority' => 'Medium',
            'status' => 'Not Started',
            'progress' => 0,
        ]);
    }
}
