<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectHistory;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

class CoreWorkflowIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Notification::fake();
    }

    public function test_project_failure_immediately_after_insert_rolls_back_and_retry_is_complete(): void
    {
        $this->assertProjectFailureRollback('project');
    }

    public function test_project_failure_after_membership_rolls_back_and_retry_is_complete(): void
    {
        $this->assertProjectFailureRollback('history');
    }

    private function assertProjectFailureRollback(string $boundary): void
    {
        $manager = $this->user('manager');
        $pm = $this->user('project_manager');
        $enabled = true;
        $inject = function () use (&$enabled) {
            if ($enabled) {
                throw new RuntimeException('R41 required persistence failure');
            }
        };
        if ($boundary === 'project') {
            Project::created($inject);
        } else {
            ProjectHistory::creating($inject);
        }
        $payload = ['name' => 'Atomic project', 'project_manager_id' => $pm->id, 'status' => 'active'];
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($manager)->postJson('/projects', $payload);
            $this->fail('Required persistence failure was not raised.');
        } catch (RuntimeException $e) {
            $this->assertSame('R41 required persistence failure', $e->getMessage());
        } finally {
            $enabled = false;
        }
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('project_user', 0);
        $this->assertDatabaseCount('project_histories', 0);
        Notification::assertNothingSent();

        $this->postJson('/projects', $payload)->assertOk();
        $project = Project::query()->sole();
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('project_user', 1);
        $this->assertDatabaseCount('project_histories', 1);
        $this->assertDatabaseHas('project_user', ['project_id' => $project->id, 'user_id' => $pm->id, 'added_by' => $manager->id]);
        $this->assertDatabaseHas('project_histories', ['project_id' => $project->id, 'action' => 'created', 'user_id' => $manager->id]);
        Notification::assertNothingSent();
        $this->assertSame(1, DB::transactionLevel());
    }

    public function test_rendered_deadline_action_tracks_review_after_a_full_revision_cycle(): void
    {
        [$manager, $pm, $member, $task] = $this->task();
        $this->actingAs($manager)->get('/tasks')->assertSee('Change Execution Deadline');
        $this->actingAs($member)->postTaskTransitionJson(route('tasks.start', $task))->assertOk();
        $this->postTaskTransitionJson(route('tasks.submit', $task))->assertOk();
        $this->actingAs($pm)->postTaskTransitionJson(route('tasks.review.start', $task))->assertOk();
        $this->get('/tasks')->assertSee('Change Review Deadline');
        $this->postTaskTransitionJson(route('tasks.revision.request', $task), [
            'formal_feedback' => 'Please revise.', 'revision_due_date' => today()->addDays(5)->toDateString(),
        ])->assertOk();
        $this->get('/tasks')->assertSee('Change Revision Deadline');
        $this->actingAs($member)->postTaskTransitionJson(route('tasks.revision.start', $task))->assertOk();
        $this->postTaskTransitionJson(route('tasks.resubmit', $task))->assertOk();
        $this->actingAs($pm)->postTaskTransitionJson(route('tasks.review.start', $task))->assertOk();
        $this->assertNotNull($task->fresh()->active_revision_cycle_id);
        $this->get('/tasks')->assertSee('Change Review Deadline')->assertDontSee('Change Revision Deadline');
        $due = today()->addDays(8)->toDateString();
        $this->postTaskTransitionJson(route('tasks.deadline.change', [$task, 'review']), ['due_date' => $due, 'reason' => 'Review needs time'])->assertOk();
        $this->assertSame($due, $task->fresh()->review_due_date->toDateString());
        $this->assertSame('review', $task->fresh()->activeDeadlineGeneration()->kind);
        $this->postTaskTransitionJson(route('tasks.deadline.change', [$task, 'revision']), ['due_date' => $due, 'reason' => 'Invalid stage'])->assertStatus(422);
        $this->actingAs($member)->postTaskTransitionJson(route('tasks.deadline.change', [$task, 'review']), ['due_date' => $due, 'reason' => 'Unauthorized'])->assertForbidden();
        $this->assertDatabaseHas('task_events', ['task_id' => $task->id, 'event_type' => 'task.deadline_changed']);
    }

    public function test_supported_account_lifecycle_preserves_reviewer_and_override_remains_available(): void
    {
        [$manager, $pm, $member, $task] = $this->task();
        $this->actingAs($member)->postTaskTransitionJson(route('tasks.start', $task))->assertOk();
        $this->postTaskTransitionJson(route('tasks.submit', $task))->assertOk();
        $this->actingAs($pm)->postTaskTransitionJson(route('tasks.review.start', $task))->assertOk();
        $this->actingAs($manager)->postJson('/team-management/'.$pm->id.'/deactivate')->assertStatus(409);
        $this->putJson('/team-management/'.$pm->id, ['name' => $pm->name, 'email' => $pm->email, 'role_id' => Role::where('name', 'team_member')->value('id')])->assertStatus(409);
        $this->deleteJson('/team-management/'.$pm->id)->assertStatus(409);
        $this->assertTrue($pm->fresh()->isActive());
        $this->postTaskTransitionJson(route('tasks.approve.override', $task), ['override_reason' => 'Manager reconciliation'])->assertOk();
    }

    public function test_historical_invalid_reviewer_can_be_replaced_without_weakening_approval(): void
    {
        [$manager, $pm, $member, $task] = $this->task();
        $this->actingAs($member)->postTaskTransitionJson(route('tasks.start', $task))->assertOk();
        $this->postTaskTransitionJson(route('tasks.submit', $task))->assertOk();
        $this->actingAs($pm)->postTaskTransitionJson(route('tasks.review.start', $task))->assertOk();
        // Model an imported/historical inconsistency, explicitly NOT a supported lifecycle mutation.
        $pm->forceFill(['active' => false])->save();
        $this->actingAs($manager)->postTaskTransitionJson(route('tasks.approve.override', $task), ['override_reason' => 'Historical data'])->assertUnprocessable();
        $this->postTaskTransitionJson(route('tasks.reviewer.reassign', $task), ['reviewer_id' => $manager->id, 'reason' => 'Replace historical invalid reviewer'])->assertOk();
        $this->postTaskTransitionJson(route('tasks.approve.override', $task), ['override_reason' => 'Recovered with an eligible reviewer'])->assertOk();
        $this->assertDatabaseHas('task_approvals', ['task_id' => $task->id, 'approved_by' => $manager->id, 'is_override' => true]);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->value('id')]);
    }

    private function task(): array
    {
        $manager = $this->user('manager');
        $pm = $this->user('project_manager');
        $member = $this->user('team_member');
        $project = Project::factory()->create(['project_manager_id' => $pm->id]);
        $project->members()->attach([$pm->id, $member->id]);
        $response = $this->actingAs($manager)->postJson('/tasks', [
            'title' => 'Workflow contract', 'project_id' => $project->id, 'assignee_id' => $member->id,
            'reviewer_id' => $pm->id, 'priority' => 'Medium', 'due_date' => today()->addDays(4)->toDateString(),
        ])->assertOk();

        return [$manager, $pm, $member, Task::findOrFail($response->json('task.id'))];
    }
}
