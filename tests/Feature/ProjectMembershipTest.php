<?php

namespace Tests\Feature;

use App\Models\CompletedTask;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskHistory;
use App\Models\User;
use App\Notifications\ProjectMemberAdded;
use App\Notifications\ProjectMemberRemoved;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProjectMembershipTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->manager = User::factory()->create([
            'role_id' => Role::query()->where('name', 'manager')->value('id'),
        ]);
    }

    public function test_manager_can_add_and_remove_member()
    {
        Notification::fake();
        $manager = $this->manager;
        $member = User::factory()->create(['role_id' => Role::where('name', 'team_member')->first()->id]);
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $this->actingAs($manager);
        $resp = $this->postJson("/projects/{$project->id}/add-member", ['user_id' => $member->id]);
        $resp->assertJson(['success' => true]);
        $this->assertTrue($project->fresh()->members->contains($member->id));
        Notification::assertSentTo($member, ProjectMemberAdded::class);
        $resp = $this->deleteJson("/projects/{$project->id}/remove-member", ['user_id' => $member->id]);
        $resp->assertJson(['success' => true]);
        $this->assertFalse($project->fresh()->members->contains($member->id));
        Notification::assertSentTo($member, ProjectMemberRemoved::class);
    }

    public function test_cannot_remove_member_with_active_tasks()
    {
        $manager = $this->manager;
        $member = User::factory()->create(['role_id' => Role::where('name', 'team_member')->first()->id]);
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $project->members()->attach($member->id);
        $project->tasks()->create([
            'title' => 'Active Task',
            'assignee_id' => $member->id,
            'priority' => 'Medium',
            'status' => 'In Progress',
            'progress' => 50,
        ]);
        $this->actingAs($manager);
        $resp = $this->deleteJson("/projects/{$project->id}/remove-member", ['user_id' => $member->id]);
        $resp->assertStatus(422);
        $this->assertTrue($project->fresh()->members->contains($member->id));
    }

    public function test_cannot_assign_task_to_non_member()
    {
        $manager = $this->manager;
        $member = User::factory()->create(['role_id' => Role::where('name', 'team_member')->first()->id]);
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $this->actingAs($manager);
        $resp = $this->postJson('/tasks', [
            'title' => 'Test Task',
            'project_id' => $project->id,
            'assignee_id' => $member->id,
            'priority' => 'Medium',
            'status' => 'Not Started',
            'progress' => 0,
        ]);
        $resp->assertStatus(422);
        $this->assertFalse($project->tasks()->where('assignee_id', $member->id)->exists());
    }

    public function test_cannot_add_or_assign_inactive_project_member()
    {
        $manager = $this->manager;
        $member = User::factory()->create([
            'role_id' => Role::where('name', 'team_member')->first()->id,
            'active' => false,
        ]);
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);

        $this->actingAs($manager)
            ->postJson("/projects/{$project->id}/add-member", ['user_id' => $member->id])
            ->assertStatus(422);

        $project->members()->attach($member->id);

        $this->actingAs($manager)
            ->postJson('/tasks', [
                'title' => 'Inactive assignee task',
                'project_id' => $project->id,
                'assignee_id' => $member->id,
                'priority' => 'Medium',
                'status' => 'Not Started',
                'progress' => 0,
            ])
            ->assertStatus(422);
    }

    public function test_reverted_completed_task_stays_in_history_with_snapshot()
    {
        Notification::fake();
        $manager = $this->manager;
        $member = User::factory()->create(['role_id' => Role::where('name', 'team_member')->first()->id]);
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $project->members()->attach($member->id);

        $this->actingAs($manager)
            ->postJson('/tasks', [
                'title' => 'Revertable Task',
                'project_id' => $project->id,
                'assignee_id' => $member->id,
                'priority' => 'High',
                'status' => 'Not Started',
                'progress' => 0,
            ])
            ->assertOk();

        $task = Task::where('title', 'Revertable Task')->firstOrFail();

        $this->putJson("/tasks/{$task->id}", [
            'status' => 'Completed',
            'progress' => 100,
        ])->assertOk();

        $completed = CompletedTask::where('title', 'Revertable Task')->firstOrFail();

        $this->postJson("/history/revert/{$completed->id}")
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('completed_tasks', [
            'id' => $completed->id,
            'reverted' => true,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('tasks', [
            'title' => 'Revertable Task',
            'project_id' => $project->id,
            'assignee_id' => $member->id,
            'status' => 'In Progress',
            'progress' => 99,
        ]);
        $this->assertTrue(TaskHistory::where('completed_task_id', $completed->id)
            ->where('project_id', $project->id)
            ->where('task_title', 'Revertable Task')
            ->exists());
    }
}
