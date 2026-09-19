<?php

namespace Tests\Feature;

use App\Enums\TaskState;
use App\Http\Middleware\EnsureTaskCorrelationId;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use App\Services\TaskEventRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SingleCompanyReleaseBlockerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Notification::fake();
    }

    public function test_task_create_replay_rechecks_current_task_authorization(): void
    {
        $pm = $this->user('project_manager');
        $member = $this->user('team_member');
        $reviewer = $this->user('manager');
        $project = Project::factory()->create(['project_manager_id' => $pm->id]);
        $project->members()->attach($member->id);
        $payload = [
            'title' => 'Replay authorization boundary',
            'project_id' => $project->id,
            'assignee_id' => $member->id,
            'reviewer_id' => $reviewer->id,
            'priority' => 'High',
        ];

        $this->actingAs($pm)
            ->withHeader(EnsureTaskCorrelationId::HEADER, 'r3a2-current-authorization')
            ->postJson(route('tasks.store'), $payload)
            ->assertOk();

        $project->forceFill(['project_manager_id' => $reviewer->id])->save();

        $this->actingAs($pm)
            ->withHeader(EnsureTaskCorrelationId::HEADER, 'r3a2-current-authorization')
            ->postJson(route('tasks.store'), $payload)
            ->assertForbidden();

        $this->assertDatabaseCount('tasks', 1);
        $this->assertSame(1, TaskEvent::query()->where('event_type', TaskEventRecorder::CREATED)->count());
    }

    public function test_task_create_replay_is_denied_after_role_demotion_or_deactivation(): void
    {
        foreach (['demoted', 'deactivated'] as $loss) {
            $pm = $this->user('project_manager');
            $member = $this->user('team_member');
            $manager = $this->user('manager');
            $project = Project::factory()->create(['project_manager_id' => $pm->id]);
            $project->members()->attach($member->id);
            $payload = [
                'title' => "Replay {$loss}",
                'project_id' => $project->id,
                'assignee_id' => $member->id,
                'reviewer_id' => $manager->id,
                'priority' => 'Medium',
            ];

            $this->actingAs($pm)->withHeader(EnsureTaskCorrelationId::HEADER, "r3a2-{$loss}")
                ->postJson(route('tasks.store'), $payload)->assertOk();

            if ($loss === 'demoted') {
                $pm->forceFill(['role_id' => Role::query()->where('name', 'team_member')->value('id')])->save();
            } else {
                $pm->forceFill(['active' => false])->save();
            }
            $pm->refresh()->unsetRelation('role');

            $this->actingAs($pm)->withHeader(EnsureTaskCorrelationId::HEADER, "r3a2-{$loss}")
                ->postJson(route('tasks.store'), $payload)->assertForbidden();
        }
    }

    public function test_role_change_that_would_invalidate_active_reviewer_is_blocked(): void
    {
        $actor = $this->user('manager');
        $reviewer = $this->user('manager');
        $assignee = $this->user('team_member');
        $project = Project::factory()->create(['project_manager_id' => $actor->id]);
        $project->members()->attach($assignee->id);
        $task = Task::query()->create([
            'title' => 'Active review duty',
            'project_id' => $project->id,
            'assignee_id' => $assignee->id,
            'status' => TaskState::Submitted,
            'priority' => 'Medium',
        ]);
        $task->forceFill(['reviewer_id' => $reviewer->id])->save();

        $this->actingAs($actor)->putJson(route('team-management.update', $reviewer), [
            'name' => $reviewer->name,
            'email' => $reviewer->email,
            'role_id' => Role::query()->where('name', 'team_member')->value('id'),
        ])->assertConflict()->assertJsonPath(
            'message',
            'Cannot change the role of a user who is the reviewer for active tasks. Reassign their reviewer duties first.',
        );

        $this->assertTrue($reviewer->fresh()->hasRole('manager'));
    }

    public function test_completed_task_can_atomically_replace_an_inactive_reviewer_while_reopening(): void
    {
        $manager = $this->user('manager');
        $oldReviewer = $this->user('manager');
        $newReviewer = $this->user('manager');
        $assignee = $this->user('team_member');
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $project->members()->attach($assignee->id);
        $task = Task::query()->create([
            'title' => 'Recoverable completed task',
            'project_id' => $project->id,
            'assignee_id' => $assignee->id,
            'status' => TaskState::InReview,
            'priority' => 'High',
        ]);
        $task->forceFill(['reviewer_id' => $oldReviewer->id])->save();
        $completed = $this->approveTask($task, $oldReviewer);
        $oldApprovalReviewer = $completed->approval->assigned_reviewer_id;
        $oldReviewer->forceFill(['active' => false])->save();

        $this->actingAs($manager)->postJson(route('tasks.reopen', $completed), [
            'reopen_reason' => 'The approved result needs correction.',
            'rework_instructions' => 'Correct the totals and resubmit the evidence.',
            'revision_due_date' => now(config('app.timezone'))->addDays(3)->toDateString(),
            'reviewer_id' => $newReviewer->id,
        ])->assertOk();

        $fresh = $completed->fresh();
        $this->assertSame($newReviewer->id, $fresh->reviewer_id);
        $this->assertSame(TaskState::RevisionRequested, $fresh->machineState());
        $this->assertSame($oldReviewer->id, $oldApprovalReviewer);
        $this->assertSame($oldReviewer->id, $fresh->approval->assigned_reviewer_id);
        $this->assertDatabaseHas('task_events', [
            'task_id' => $fresh->id,
            'event_type' => TaskEventRecorder::REVIEWER_REASSIGNED,
        ]);
    }

    public function test_reopen_reviewer_reconciliation_rejects_self_review_and_unauthorized_callers(): void
    {
        $manager = $this->user('manager');
        $reviewer = $this->user('manager');
        $assignee = $this->user('team_member');
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $project->members()->attach($assignee->id);
        $task = Task::query()->create([
            'title' => 'Protected reconciliation',
            'project_id' => $project->id,
            'assignee_id' => $assignee->id,
            'status' => TaskState::InReview,
            'priority' => 'High',
        ]);
        $task->forceFill(['reviewer_id' => $reviewer->id])->save();
        $completed = $this->approveTask($task, $reviewer);
        $reviewer->forceFill(['active' => false])->save();
        $payload = [
            'reopen_reason' => 'Correction required.',
            'revision_due_date' => now(config('app.timezone'))->addDays(2)->toDateString(),
            'reviewer_id' => $assignee->id,
        ];

        $this->actingAs($manager)->postJson(route('tasks.reopen', $completed), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('reviewer_id');
        $this->actingAs($assignee)->postJson(route('tasks.reopen', $completed), $payload)
            ->assertForbidden();
        $this->assertSame(TaskState::Completed, $completed->fresh()->machineState());
    }

    public function test_task_uid_schema_is_non_nullable_and_legacy_rows_are_backfilled(): void
    {
        $this->assertFalse($this->taskUidIsNullable());
        $this->expectException(QueryException::class);
        DB::table('tasks')->insert([
            'title' => 'Null UID must be impossible',
            'priority' => 'Low',
            'status' => TaskState::NotStarted->value,
            'progress' => 0,
            'task_uid' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_uid_migration_backfills_active_and_soft_deleted_legacy_rows_and_reapplies(): void
    {
        $migration = require database_path('migrations/2026_09_19_000002_require_task_uids.php');
        $migration->down();

        $activeId = DB::table('tasks')->insertGetId([
            'title' => 'Legacy active', 'priority' => 'Low', 'status' => TaskState::NotStarted->value,
            'progress' => 0, 'task_uid' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $deletedId = DB::table('tasks')->insertGetId([
            'title' => 'Legacy deleted', 'priority' => 'Low', 'status' => TaskState::NotStarted->value,
            'progress' => 0, 'task_uid' => null, 'deleted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration->up();
        $uids = DB::table('tasks')->whereIn('id', [$activeId, $deletedId])->pluck('task_uid', 'id');
        $this->assertCount(2, $uids->unique());
        $this->assertTrue($uids->every(fn ($uid) => preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $uid) === 1));
        $this->assertFalse($this->taskUidIsNullable());

        $migration->down();
        $this->assertTrue($this->taskUidIsNullable());
        $migration->up();
        $this->assertSame($uids->all(), DB::table('tasks')->whereIn('id', [$activeId, $deletedId])->pluck('task_uid', 'id')->all());
        $this->assertTrue(Schema::hasColumn('tasks', 'task_uid'));
    }

    public function test_profile_page_renders_supported_account_and_settings_controls(): void
    {
        $user = $this->user('team_member');

        $this->actingAs($user)->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('Profile Information')
            ->assertSee('Update Password')
            ->assertSee(route('settings'), false)
            ->assertDontSee('name="role_id"', false);
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('name', $role)->value('id'),
            'active' => true,
        ]);
    }

    private function taskUidIsNullable(): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            return (int) collect(DB::select('PRAGMA table_info(tasks)'))->keyBy('name')->get('task_uid')->notnull === 0;
        }

        return DB::table('information_schema.columns')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'tasks')
            ->where('column_name', 'task_uid')
            ->value('is_nullable') === 'YES';
    }
}
