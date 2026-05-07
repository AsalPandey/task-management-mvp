<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Role;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;
use App\Notifications\ProjectMemberAdded;
use App\Notifications\ProjectMemberRemoved;

class ProjectMembershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_manager_can_add_and_remove_member()
    {
        Notification::fake();
        $manager = User::whereHas('role', fn($q) => $q->where('name', 'manager'))->first();
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
        $manager = User::whereHas('role', fn($q) => $q->where('name', 'manager'))->first();
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
        $manager = User::whereHas('role', fn($q) => $q->where('name', 'manager'))->first();
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
} 