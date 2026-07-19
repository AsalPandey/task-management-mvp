<?php

namespace Tests\Feature;

use App\Models\CompletedTask;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskHistory;
use App\Models\User;
use App\Notifications\TaskCompletedNotification;
use App\Notifications\TaskRevertedNotification;
use App\Policies\CompletedTaskPolicy;
use App\Services\TaskLifecycleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CompletedTaskRevertAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_completed_task_policy_is_explicitly_registered(): void
    {
        $this->assertInstanceOf(CompletedTaskPolicy::class, Gate::getPolicyFor(CompletedTask::class));
    }

    public function test_unrelated_team_member_cannot_revert_coworker_completed_task(): void
    {
        [$project, $assignee, $coworker] = $this->projectWithTwoMembers();
        $completedTask = $this->completedTask($project, $assignee);

        $this->actingAs($coworker)
            ->postJson(route('completed-tasks.revert', $completedTask))
            ->assertForbidden();

        $this->assertCompletedTaskWasNotReverted($completedTask);
    }

    public function test_team_member_cannot_exploit_a_guessed_completed_task_id(): void
    {
        [$project, $assignee, $attacker] = $this->projectWithTwoMembers();
        $completedTask = $this->completedTask($project, $assignee);

        $this->actingAs($attacker)
            ->postJson('/history/revert/'.$completedTask->getKey())
            ->assertForbidden();

        $this->assertCompletedTaskWasNotReverted($completedTask);
    }

    public function test_same_project_creator_or_assigner_cannot_revert_without_controlling_task(): void
    {
        [$project, $assignee, $creator] = $this->projectWithTwoMembers();
        $completedTask = $this->completedTask($project, $assignee, [
            'created_by' => $creator->id,
            'assigned_by' => $creator->id,
        ]);

        $this->actingAs($creator)
            ->postJson(route('completed-tasks.revert', $completedTask))
            ->assertForbidden();

        $this->assertCompletedTaskWasNotReverted($completedTask);
    }

    public function test_user_outside_project_cannot_revert_completed_task(): void
    {
        [$project, $assignee] = $this->projectWithTwoMembers();
        $outsider = $this->userWithRole('team_member');
        $completedTask = $this->completedTask($project, $assignee);

        $this->actingAs($outsider)
            ->postJson(route('completed-tasks.revert', $completedTask))
            ->assertForbidden();

        $this->assertCompletedTaskWasNotReverted($completedTask);
    }

    public function test_inactive_assignee_cannot_revert_completed_task(): void
    {
        $projectManager = $this->userWithRole('project_manager');
        $project = Project::factory()->create(['project_manager_id' => $projectManager->id]);
        $assignee = $this->userWithRole('team_member', ['active' => false]);
        $project->members()->attach($assignee->id);
        $completedTask = $this->completedTask($project, $assignee);

        $this->actingAs($assignee)
            ->postJson(route('completed-tasks.revert', $completedTask))
            ->assertForbidden();

        $this->assertCompletedTaskWasNotReverted($completedTask);
    }

    public function test_company_manager_can_revert_completed_task(): void
    {
        [$project, $assignee] = $this->projectWithTwoMembers();
        $manager = $this->userWithRole('manager');
        $completedTask = $this->completedTask($project, $assignee);

        $this->actingAs($manager)
            ->postJson(route('completed-tasks.revert', $completedTask))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertTrue($completedTask->fresh()->reverted);
    }

    public function test_project_manager_can_revert_task_in_managed_project(): void
    {
        $projectManager = $this->userWithRole('project_manager');
        $project = Project::factory()->create(['project_manager_id' => $projectManager->id]);
        $assignee = $this->userWithRole('team_member');
        $project->members()->attach($assignee->id);
        $completedTask = $this->completedTask($project, $assignee);

        $this->actingAs($projectManager)
            ->postJson(route('completed-tasks.revert', $completedTask))
            ->assertOk();

        $this->assertTrue($completedTask->fresh()->reverted);
    }

    public function test_project_manager_cannot_revert_task_in_another_project(): void
    {
        [$project, $assignee] = $this->projectWithTwoMembers();
        $otherProjectManager = $this->userWithRole('project_manager');
        $completedTask = $this->completedTask($project, $assignee);

        $this->actingAs($otherProjectManager)
            ->postJson(route('completed-tasks.revert', $completedTask))
            ->assertForbidden();

        $this->assertCompletedTaskWasNotReverted($completedTask);
    }

    public function test_active_assignee_can_revert_own_completed_task(): void
    {
        [$project, $assignee] = $this->projectWithTwoMembers();
        $completedTask = $this->completedTask($project, $assignee);

        $this->actingAs($assignee)
            ->postJson(route('completed-tasks.revert', $completedTask))
            ->assertOk();

        $this->assertTrue($completedTask->fresh()->reverted);
    }

    public function test_assignee_without_current_project_access_cannot_revert_completed_task(): void
    {
        [$project, $assignee] = $this->projectWithTwoMembers();
        $completedTask = $this->completedTask($project, $assignee);
        $project->members()->detach($assignee->id);

        $this->actingAs($assignee)
            ->postJson(route('completed-tasks.revert', $completedTask))
            ->assertForbidden();

        $this->assertCompletedTaskWasNotReverted($completedTask);
    }

    public function test_direct_service_call_rejects_unauthorized_actor_without_side_effects(): void
    {
        Notification::fake();
        [$project, $assignee, $coworker] = $this->projectWithTwoMembers();
        $completedTask = $this->completedTask($project, $assignee);
        $completedState = DB::table('completed_tasks')->where('id', $completedTask->id)->first();
        $taskCount = Task::withTrashed()->count();
        $historyCount = TaskHistory::query()->count();

        try {
            app(TaskLifecycleService::class)->revert($completedTask, $coworker);
            $this->fail('Expected the lifecycle service to reject the unauthorized actor.');
        } catch (AuthorizationException) {
            $this->assertSame($taskCount, Task::withTrashed()->count());
            $this->assertSame($historyCount, TaskHistory::query()->count());
            $this->assertEquals(
                $completedState,
                DB::table('completed_tasks')->where('id', $completedTask->id)->first(),
            );
            Notification::assertNothingSent();
        }
    }

    public function test_authorized_revert_creates_exact_lifecycle_state_and_notification(): void
    {
        Notification::fake();
        [$project, $assignee] = $this->projectWithTwoMembers();
        $manager = $this->userWithRole('manager');
        $completedTask = $this->completedTask($project, $assignee);
        $taskCount = Task::withTrashed()->count();
        $historyCount = TaskHistory::query()->count();
        $originalTask = Task::withTrashed()->findOrFail($completedTask->original_task_id);
        $originalUid = $originalTask->task_uid;

        $response = $this->actingAs($manager)
            ->postJson(route('completed-tasks.revert', $completedTask))
            ->assertOk()
            ->assertJson(['success' => true]);

        $activeTask = Task::query()->findOrFail($response->json('task.id'));
        $this->assertSame($taskCount, Task::withTrashed()->count());
        $this->assertSame($historyCount + 1, TaskHistory::query()->count());
        $this->assertSame($completedTask->original_task_id, $activeTask->id);
        $this->assertSame($originalUid, $activeTask->task_uid);
        $this->assertSame($completedTask->title, $activeTask->title);
        $this->assertSame('In Progress', $activeTask->status);
        $this->assertSame(99, $activeTask->progress);
        $this->assertDatabaseHas('task_histories', [
            'task_id' => $activeTask->id,
            'completed_task_id' => $completedTask->id,
            'user_id' => $manager->id,
            'action' => 'reverted',
        ]);
        $this->assertDatabaseHas('completed_tasks', [
            'id' => $completedTask->id,
            'reverted' => true,
            'reverted_by' => $manager->id,
            'deleted_at' => null,
        ]);
        $this->assertNotNull($completedTask->fresh()->reverted_at);
        Notification::assertSentTo($assignee, TaskRevertedNotification::class);
    }

    public function test_repeated_unauthorized_requests_remain_harmless(): void
    {
        Notification::fake();
        [$project, $assignee, $coworker] = $this->projectWithTwoMembers();
        $completedTask = $this->completedTask($project, $assignee);
        $taskCount = Task::withTrashed()->count();
        $historyCount = TaskHistory::query()->count();

        $this->actingAs($coworker);
        $this->postJson(route('completed-tasks.revert', $completedTask))->assertForbidden();
        $this->postJson(route('completed-tasks.revert', $completedTask))->assertForbidden();

        $this->assertSame($taskCount, Task::withTrashed()->count());
        $this->assertSame($historyCount, TaskHistory::query()->count());
        $this->assertCompletedTaskWasNotReverted($completedTask);
        Notification::assertNothingSent();
    }

    public function test_unrelated_member_cannot_complete_coworker_task_through_service(): void
    {
        Notification::fake();
        [$project, $assignee, $coworker] = $this->projectWithTwoMembers();
        $task = Task::query()->create([
            'project_id' => $project->id,
            'title' => 'Coworker task',
            'assignee_id' => $assignee->id,
            'priority' => 'High',
            'status' => 'Completed',
            'progress' => 100,
        ]);
        $historyCount = TaskHistory::query()->count();

        $this->expectException(AuthorizationException::class);

        try {
            app(TaskLifecycleService::class)->complete($task, $coworker);
        } finally {
            $this->assertDatabaseHas('tasks', ['id' => $task->id, 'deleted_at' => null]);
            $this->assertDatabaseMissing('completed_tasks', ['original_task_id' => $task->id]);
            $this->assertSame($historyCount, TaskHistory::query()->count());
            Notification::assertNothingSent();
        }
    }

    public function test_legitimate_task_completion_updates_the_canonical_row_and_history(): void
    {
        Notification::fake();
        [$project, $assignee] = $this->projectWithTwoMembers();
        $manager = $this->userWithRole('manager');
        $task = Task::query()->create([
            'project_id' => $project->id,
            'title' => 'Legitimate completion',
            'assignee_id' => $assignee->id,
            'priority' => 'Medium',
            'status' => 'In Progress',
            'progress' => 60,
        ]);
        $taskUid = $task->task_uid;

        $completedTask = app(TaskLifecycleService::class)->complete($task, $manager);

        $this->assertSame($task->id, $completedTask->id);
        $this->assertSame($taskUid, $completedTask->task_uid);
        $this->assertDatabaseHas('tasks', [
            'id' => $completedTask->id,
            'status' => 'Completed',
            'progress' => 100,
            'completed_by' => $manager->id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseCount('completed_tasks', 0);
        $this->assertDatabaseHas('task_histories', [
            'task_id' => $task->id,
            'user_id' => $manager->id,
            'action' => 'completed',
        ]);
        Notification::assertSentTo($assignee, TaskCompletedNotification::class);
    }

    public function test_completed_task_page_does_not_expose_coworker_revert_control(): void
    {
        [$project, $assignee, $coworker] = $this->projectWithTwoMembers();
        $completedTask = $this->completedTask($project, $assignee);

        $this->actingAs($coworker)
            ->get(route('completed-tasks'))
            ->assertOk()
            ->assertDontSee($completedTask->title)
            ->assertDontSee('/history/revert/'.$completedTask->id, false);
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role_id' => Role::query()->where('name', $role)->value('id'),
        ], $attributes));
    }

    private function projectWithTwoMembers(): array
    {
        $projectManager = $this->userWithRole('project_manager');
        $project = Project::factory()->create(['project_manager_id' => $projectManager->id]);
        $assignee = $this->userWithRole('team_member');
        $coworker = $this->userWithRole('team_member');
        $project->members()->attach([$assignee->id, $coworker->id]);

        return [$project, $assignee, $coworker, $projectManager];
    }

    private function completedTask(Project $project, User $assignee, array $attributes = []): CompletedTask
    {
        $snapshot = array_merge([
            'project_id' => $project->id,
            'title' => 'Completed task '.uniqid(),
            'description' => 'Completed task snapshot',
            'assignee_id' => $assignee->id,
            'priority' => 'High',
            'status' => 'Completed',
            'progress' => 100,
            'completed_at' => now(),
        ], $attributes);
        $originalTask = Task::query()->create([
            'project_id' => $snapshot['project_id'],
            'title' => $snapshot['title'],
            'description' => $snapshot['description'],
            'assignee_id' => $snapshot['assignee_id'],
            'created_by' => $snapshot['created_by'] ?? null,
            'assigned_by' => $snapshot['assigned_by'] ?? null,
            'priority' => $snapshot['priority'],
            'status' => 'In Progress',
            'progress' => min((int) $snapshot['progress'], 99),
        ]);
        $originalTask->delete();
        $snapshot['original_task_id'] = $originalTask->id;

        return CompletedTask::query()->create($snapshot);
    }

    private function assertCompletedTaskWasNotReverted(CompletedTask $completedTask): void
    {
        $completedTask->refresh();

        $this->assertFalse($completedTask->reverted);
        $this->assertNull($completedTask->reverted_by);
        $this->assertNull($completedTask->reverted_at);
        $this->assertNull($completedTask->deleted_at);
        $this->assertSoftDeleted('tasks', ['id' => $completedTask->original_task_id]);
    }
}
