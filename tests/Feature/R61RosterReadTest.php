<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class R61RosterReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_members_candidates_and_selected_identity_are_bounded_searchable_and_scoped(): void
    {
        $this->seed();
        $manager = User::factory()->create(['role_id' => Role::where('name', 'manager')->value('id')]);
        $pm = User::factory()->create(['role_id' => Role::where('name', 'project_manager')->value('id')]);
        $outsider = User::factory()->create(['role_id' => $pm->role_id]);
        $project = Project::factory()->create(['project_manager_id' => $pm->id]);
        $members = User::factory()->count(60)->create(['role_id' => Role::where('name', 'team_member')->value('id')]);
        $project->members()->attach($members->modelKeys());
        $last = $members->last();
        $last->update(['name' => 'Zebra Last Employee']);
        $this->actingAs($manager)->get('/projects')->assertOk()->assertViewHas('projects', function ($projects) {
            return $projects->first()->members_count === 60 && $projects->first()->members->count() === 5;
        });
        $this->getJson('/projects/'.$project->id.'/members')->assertOk()->assertJsonCount(20, 'members')->assertJsonPath('total', 60);
        $this->getJson('/projects/'.$project->id.'/members?page=3')->assertOk()->assertJsonCount(20, 'members')->assertJsonPath('current_page', 3);
        $this->getJson('/projects/'.$project->id.'/candidates')->assertOk()->assertJsonCount(25, 'candidates')->assertDontSee('password');
        $selected = $this->getJson('/projects/'.$project->id.'/candidates?selected='.$last->id)->assertOk();
        $this->assertLessThanOrEqual(26, count($selected->json('candidates')));
        $this->assertContains($last->id, array_column($selected->json('candidates'), 'id'));
        $this->getJson('/projects/'.$project->id.'/candidates?search=Zebra')->assertOk()->assertJsonCount(1, 'candidates')->assertJsonPath('candidates.0.id', $last->id);
        $this->actingAs($outsider)->getJson('/projects/'.$project->id.'/candidates')->assertForbidden();
        $this->getJson('/projects/'.$project->id.'/members')->assertForbidden();
        $this->actingAs($members->first())->getJson('/projects/'.$project->id.'/candidates')->assertForbidden();
        $outside = Project::factory()->create(['project_manager_id' => $outsider->id]);
        $outsideMember = User::factory()->create(['name' => 'Hidden outside owner', 'role_id' => $last->role_id]);
        Task::create(['title' => 'R61 contract fixture', 'priority' => 'High', 'status' => 'not_started', 'project_id' => $outside->id, 'assignee_id' => $outsideMember->id]);
        Task::create(['title' => 'R61 contract fixture', 'priority' => 'High', 'status' => 'not_started', 'project_id' => $project->id, 'assignee_id' => $last->id]);
        $this->actingAs($pm)->getJson('/roster/task-filters?search=Hidden')->assertOk()->assertJsonCount(0, 'candidates');
        $this->getJson('/roster/task-filters?search=Zebra')->assertOk()->assertJsonPath('candidates.0.id', $last->id);
        $this->getJson('/projects/'.$project->id.'/candidates?kind=reviewer')->assertOk();
        $this->getJson('/roster/project-managers')->assertOk()->assertJsonCount(1, 'candidates')->assertJsonPath('candidates.0.id', $pm->id);
    }
}
