<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TeamManagementTest extends TestCase
{
    use RefreshDatabase;

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
} 