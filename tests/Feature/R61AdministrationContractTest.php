<?php

namespace Tests\Feature;

use App\Enums\TaskState;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class R61AdministrationContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_unfinished_state_blocks_eligibility_loss_and_final_states_allow_it(): void
    {
        $this->seed();
        foreach (TaskState::cases() as $state) {
            foreach (['role', 'pm'] as $change) {
                $manager = User::factory()->create(['role_id' => Role::where('name', 'manager')->value('id')]);
                $pm = User::factory()->create(['role_id' => Role::where('name', 'project_manager')->value('id')]);
                $owner = $change === 'pm' ? $pm : User::factory()->create(['role_id' => Role::where('name', 'team_member')->value('id')]);
                $project = Project::factory()->create(['project_manager_id' => $pm->id]);
                $project->members()->attach($owner->id);
                $task = Task::create(['title' => 'R61 contract fixture', 'priority' => 'High', 'status' => 'not_started', 'project_id' => $project->id, 'assignee_id' => $owner->id,
                    'reviewer_id' => $manager->id, 'status' => $state]);
                $version = $task->lock_version;
                $response = $change === 'role' ? $this->actingAs($manager)->putJson('/team-management/'.$owner->id,
                    ['name' => $owner->name, 'email' => $owner->email, 'role_id' => $pm->role_id])
                    : $this->actingAs($manager)->putJson('/projects/'.$project->id,
                        ['name' => $project->name, 'status' => 'active', 'project_manager_id' => $manager->id]);
                $final = in_array($state, [TaskState::Completed, TaskState::Cancelled]);
                $response->assertStatus($final ? 200 : 409);
                if (! $final) {
                    $response->assertJsonPath('message', $change === 'role'
                        ? 'This employee has unfinished task assignments that would become inaccessible after this role change. Reassign or complete them first.'
                        : 'The outgoing project manager has unfinished task assignments that would become inaccessible. Reassign or complete them before replacing the project manager.');
                }
                $this->assertSame($owner->id, $task->fresh()->assignee_id);
                $this->assertSame($version, $task->fresh()->lock_version);
                $this->assertSame(0, $task->histories()->count());
            }
        }
    }

    public function test_dependency_checks_allow_a_role_change_that_preserves_owned_project_execution(): void
    {
        $this->seed();
        $manager = User::factory()->create(['role_id' => Role::where('name', 'manager')->value('id')]);
        $owner = User::factory()->create(['role_id' => Role::where('name', 'team_member')->value('id')]);
        $project = Project::factory()->create(['project_manager_id' => $owner->id]);
        $project->members()->attach($owner->id);
        Task::create(['title' => 'R61 contract fixture', 'priority' => 'High', 'status' => 'not_started', 'project_id' => $project->id, 'assignee_id' => $owner->id, 'reviewer_id' => $manager->id, 'status' => TaskState::InProgress]);
        $this->actingAs($manager)->putJson('/team-management/'.$owner->id,
            ['name' => $owner->name, 'email' => $owner->email, 'role_id' => Role::where('name', 'project_manager')->value('id')])->assertOk();
    }
}
