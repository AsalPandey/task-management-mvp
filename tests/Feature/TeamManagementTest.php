<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_manager_can_add_team_member()
    {
        $manager = User::factory()->create(['role_id' => Role::where('name', 'manager')->first()->id]);
        $teamMemberRole = Role::where('name', 'team_member')->first();

        $this->actingAs($manager)
            ->postJson('/team-management', [
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'password' => 'password123',
                'role_id' => $teamMemberRole->id,
            ])
            ->assertJson(['role' => ['name' => 'team_member']]);
    }

    public function test_project_manager_cannot_create_global_member_account()
    {
        $projectManager = User::factory()->create(['role_id' => Role::where('name', 'project_manager')->first()->id]);
        $teamMemberRole = Role::where('name', 'team_member')->first();
        $project = Project::factory()->create(['project_manager_id' => $projectManager->id]);

        $this->actingAs($projectManager)
            ->postJson('/team-management', [
                'name' => 'Project Member',
                'email' => 'project-member@example.com',
                'password' => 'password123',
                'role_id' => $teamMemberRole->id,
                'project_ids' => [$project->id],
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'project-member@example.com']);
    }

    public function test_project_manager_can_attach_existing_member_to_managed_project()
    {
        $projectManager = User::factory()->create(['role_id' => Role::where('name', 'project_manager')->first()->id]);
        $teamMember = User::factory()->create(['role_id' => Role::where('name', 'team_member')->first()->id]);
        $project = Project::factory()->create(['project_manager_id' => $projectManager->id]);

        $this->actingAs($projectManager)
            ->postJson("/projects/{$project->id}/add-member", ['user_id' => $teamMember->id])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('project_user', [
            'project_id' => $project->id,
            'user_id' => $teamMember->id,
            'added_by' => $projectManager->id,
        ]);
    }

    public function test_manager_can_assign_manager_role()
    {
        $manager = User::factory()->create(['role_id' => Role::where('name', 'manager')->first()->id]);
        $managerRole = Role::where('name', 'manager')->first();

        $this->actingAs($manager)
            ->postJson('/team-management', [
                'name' => 'New Manager',
                'email' => 'newmanager@example.com',
                'password' => 'password123',
                'role_id' => $managerRole->id,
            ])
            ->assertJson(['role' => ['name' => 'manager']]);
    }

    public function test_team_member_cannot_assign_manager_role()
    {
        $teamMember = User::factory()->create(['role_id' => Role::where('name', 'team_member')->first()->id]);
        $managerRole = Role::where('name', 'manager')->first();

        $this->actingAs($teamMember)
            ->postJson('/team-management', [
                'name' => 'New Manager',
                'email' => 'newmanager@example.com',
                'password' => 'password123',
                'role_id' => $managerRole->id,
            ])
            ->assertStatus(403);
    }

    public function test_manager_can_edit_team_member()
    {
        $manager = User::factory()->create(['role_id' => Role::where('name', 'manager')->first()->id]);
        $teamMember = User::factory()->create(['role_id' => Role::where('name', 'team_member')->first()->id]);

        $this->actingAs($manager)
            ->putJson("/team-management/{$teamMember->id}", [
                'name' => 'Updated Name',
                'email' => 'updated@example.com',
            ])
            ->assertJson(['name' => 'Updated Name']);
    }

    public function test_global_account_update_rejects_project_membership_fields()
    {
        $manager = User::factory()->create(['role_id' => Role::where('name', 'manager')->first()->id]);
        $teamMember = User::factory()->create(['role_id' => Role::where('name', 'team_member')->first()->id]);
        $firstProject = Project::factory()->create(['project_manager_id' => $manager->id]);
        $secondProject = Project::factory()->create(['project_manager_id' => $manager->id]);
        $firstProject->members()->attach($teamMember->id);

        $this->actingAs($manager)
            ->putJson("/team-management/{$teamMember->id}", [
                'name' => $teamMember->name,
                'email' => $teamMember->email,
                'role_id' => $teamMember->role_id,
                'project_ids' => [$secondProject->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fields');

        $this->assertTrue($teamMember->fresh()->projects()->whereKey($firstProject->id)->exists());
        $this->assertFalse($teamMember->fresh()->projects()->whereKey($secondProject->id)->exists());
    }

    public function test_team_member_cannot_edit_manager()
    {
        $manager = User::factory()->create(['role_id' => Role::where('name', 'manager')->first()->id]);
        $teamMember = User::factory()->create(['role_id' => Role::where('name', 'team_member')->first()->id]);

        // Team member cannot edit manager
        $this->actingAs($teamMember)
            ->putJson("/team-management/{$manager->id}", [
                'name' => 'Updated Name',
                'email' => 'updated@example.com',
            ])
            ->assertStatus(403);
    }

    public function test_manager_can_delete_team_member()
    {
        $manager = User::factory()->create(['role_id' => Role::where('name', 'manager')->first()->id]);
        $teamMember = User::factory()->create(['role_id' => Role::where('name', 'team_member')->first()->id]);

        $this->actingAs($manager)
            ->deleteJson("/team-management/{$teamMember->id}")
            ->assertStatus(200);
    }

    public function test_manager_cannot_delete_the_owner_of_an_active_project()
    {
        $manager = User::factory()->create(['role_id' => Role::where('name', 'manager')->first()->id]);
        $projectManager = User::factory()->create(['role_id' => Role::where('name', 'project_manager')->first()->id]);
        Project::factory()->create([
            'project_manager_id' => $projectManager->id,
            'status' => 'active',
        ]);

        $this->actingAs($manager)
            ->deleteJson("/team-management/{$projectManager->id}")
            ->assertConflict()
            ->assertJson([
                'success' => false,
                'message' => 'Cannot delete a user who manages an active project. Assign another project manager or close the project first.',
            ]);

        $this->assertNotSoftDeleted($projectManager);
    }

    public function test_team_management_records_the_original_role_for_change_confirmation()
    {
        $manager = User::factory()->create(['role_id' => Role::where('name', 'manager')->first()->id]);
        User::factory()->create(['role_id' => Role::where('name', 'team_member')->first()->id]);

        $this->actingAs($manager)
            ->get('/team-management')
            ->assertOk()
            ->assertSee("setAttribute('data-current-role'", false)
            ->assertSee("getAttribute('data-current-role')", false);
    }

    public function test_only_self_or_manager_can_view_analytics()
    {
        $manager = User::factory()->create(['role_id' => Role::where('name', 'manager')->first()->id]);
        $teamMember = User::factory()->create(['role_id' => Role::where('name', 'team_member')->first()->id]);

        // Manager can view any analytics
        $this->actingAs($manager)
            ->get("/team-management/{$teamMember->id}/analytics")
            ->assertStatus(200);

        // Team member can view own analytics
        $this->actingAs($teamMember)
            ->get("/team-management/{$teamMember->id}/analytics")
            ->assertStatus(200);

        // Team member cannot view manager analytics
        $this->actingAs($teamMember)
            ->get("/team-management/{$manager->id}/analytics")
            ->assertStatus(403);
    }

    public function test_only_manager_can_activate_deactivate()
    {
        $manager = User::factory()->create(['role_id' => Role::where('name', 'manager')->first()->id]);
        $teamMember = User::factory()->create(['role_id' => Role::where('name', 'team_member')->first()->id]);

        // Manager can activate/deactivate
        $this->actingAs($manager)
            ->postJson("/team-management/{$teamMember->id}/activate")
            ->assertStatus(200);

        $this->actingAs($manager)
            ->postJson("/team-management/{$teamMember->id}/deactivate")
            ->assertStatus(200);

        // Team member cannot activate/deactivate
        $this->actingAs($teamMember)
            ->postJson("/team-management/{$manager->id}/activate")
            ->assertStatus(403);
    }

    public function test_project_manager_cannot_delete_or_deactivate_member_globally()
    {
        $projectManager = User::factory()->create(['role_id' => Role::where('name', 'project_manager')->first()->id]);
        $teamMember = User::factory()->create(['role_id' => Role::where('name', 'team_member')->first()->id]);
        $project = Project::factory()->create(['project_manager_id' => $projectManager->id]);
        $project->members()->attach($teamMember->id);

        $this->actingAs($projectManager)
            ->deleteJson("/team-management/{$teamMember->id}")
            ->assertStatus(403);

        $this->actingAs($projectManager)
            ->postJson("/team-management/{$teamMember->id}/deactivate")
            ->assertStatus(403);
    }

    public function test_project_manager_analytics_are_allowed_for_project_member_and_scoped()
    {
        $projectManager = User::factory()->create(['role_id' => Role::where('name', 'project_manager')->first()->id]);
        $otherProjectManager = User::factory()->create(['role_id' => Role::where('name', 'project_manager')->first()->id]);
        $teamMember = User::factory()->create(['role_id' => Role::where('name', 'team_member')->first()->id]);
        $project = Project::factory()->create(['project_manager_id' => $projectManager->id]);
        $otherProject = Project::factory()->create(['project_manager_id' => $otherProjectManager->id]);
        $project->members()->attach($teamMember->id);
        $otherProject->members()->attach($teamMember->id);

        Task::query()->create([
            'title' => 'Scoped Task',
            'project_id' => $project->id,
            'assignee_id' => $teamMember->id,
            'priority' => 'Medium',
            'status' => 'In Progress',
            'progress' => 20,
        ]);
        Task::query()->create([
            'title' => 'Other Project Task',
            'project_id' => $otherProject->id,
            'assignee_id' => $teamMember->id,
            'priority' => 'Medium',
            'status' => 'In Progress',
            'progress' => 80,
        ]);

        $this->actingAs($projectManager)
            ->get("/team-management/{$teamMember->id}/analytics")
            ->assertOk()
            ->assertViewHas('allTasks', fn ($tasks) => $tasks->pluck('title')->all() === ['Scoped Task'])
            ->assertViewHas('totalTasks', 1);
    }

    public function test_settings_page_is_reachable()
    {
        $manager = User::factory()->create(['role_id' => Role::where('name', 'manager')->first()->id]);

        $this->actingAs($manager)
            ->get('/settings')
            ->assertOk()
            ->assertSee('Settings');
    }
}
