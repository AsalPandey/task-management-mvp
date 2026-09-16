<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class TaskAssignmentCandidateDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_manager_receives_only_active_members_of_visible_projects(): void
    {
        $manager = $this->userWithRole('manager');
        $activeMember = $this->userWithRole('team_member', ['name' => 'Active Candidate']);
        $inactiveMember = $this->userWithRole('team_member', [
            'name' => 'Inactive Candidate',
            'active' => false,
        ]);
        $unattachedMember = $this->userWithRole('team_member', ['name' => 'Unattached Candidate']);
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $project->members()->attach([$activeMember->id, $inactiveMember->id]);

        $response = $this->actingAs($manager)->get(route('tasks'));

        $response->assertOk();
        $candidates = $this->assignmentCandidates($response->viewData('assignmentCandidates'));

        $this->assertSame([$activeMember->id], $candidates->pluck('id')->all());
        $this->assertSame(['id', 'name'], array_keys($candidates->first()->getAttributes()));
        $response
            ->assertSee('Active Candidate')
            ->assertDontSee('Inactive Candidate')
            ->assertDontSee('Unattached Candidate');
    }

    public function test_project_manager_candidates_are_scoped_to_managed_projects(): void
    {
        $projectManager = $this->userWithRole('project_manager');
        $otherProjectManager = $this->userWithRole('project_manager');
        $managedMember = $this->userWithRole('team_member', ['name' => 'Managed Candidate']);
        $outsideMember = $this->userWithRole('team_member', ['name' => 'Outside Candidate']);
        $managedProject = Project::factory()->create(['project_manager_id' => $projectManager->id]);
        $outsideProject = Project::factory()->create(['project_manager_id' => $otherProjectManager->id]);
        $managedProject->members()->attach($managedMember);
        $outsideProject->members()->attach($outsideMember);

        $response = $this->actingAs($projectManager)->get(route('tasks'));

        $response->assertOk();
        $candidates = $this->assignmentCandidates($response->viewData('assignmentCandidates'));

        $this->assertSame([$managedMember->id], $candidates->pluck('id')->all());
        $response
            ->assertSee('Managed Candidate')
            ->assertDontSee('Outside Candidate');
    }

    public function test_team_member_receives_an_empty_assignment_candidate_collection(): void
    {
        $teamMember = $this->userWithRole('team_member');
        $projectManager = $this->userWithRole('project_manager');
        $project = Project::factory()->create(['project_manager_id' => $projectManager->id]);
        $project->members()->attach($teamMember);

        $response = $this->actingAs($teamMember)->get(route('tasks'));

        $response->assertOk();
        $this->assertTrue(
            $this->assignmentCandidates($response->viewData('assignmentCandidates'))->isEmpty(),
        );
    }

    public function test_direct_crafted_task_creation_with_outside_candidate_is_rejected(): void
    {
        $projectManager = $this->userWithRole('project_manager');
        $managedProject = Project::factory()->create(['project_manager_id' => $projectManager->id]);
        $outsideMember = $this->userWithRole('team_member', ['name' => 'Outside Member']);

        $response = $this->actingAs($projectManager)->postJson(route('tasks.store'), [
            'title' => 'Crafted Assignment Task',
            'project_id' => $managedProject->id,
            'assignee_id' => $outsideMember->id,
            'reviewer_id' => $projectManager->id,
            'priority' => 'Medium',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['assignee_id']);
        $this->assertDatabaseMissing('tasks', ['title' => 'Crafted Assignment Task']);
    }

    public function test_direct_crafted_task_update_with_outside_candidate_is_rejected(): void
    {
        $projectManager = $this->userWithRole('project_manager');
        $managedMember = $this->userWithRole('team_member', ['name' => 'Managed Member']);
        $outsideMember = $this->userWithRole('team_member', ['name' => 'Outside Member']);
        $managedProject = Project::factory()->create(['project_manager_id' => $projectManager->id]);
        $managedProject->members()->attach($managedMember);

        $task = Task::query()->create([
            'title' => 'Existing Task',
            'project_id' => $managedProject->id,
            'assignee_id' => $managedMember->id,
            'reviewer_id' => $projectManager->id,
            'status' => 'Not Started',
            'priority' => 'Medium',
        ]);

        $response = $this->actingAs($projectManager)->putJson(route('tasks.update', $task), [
            'assignee_id' => $outsideMember->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['assignee_id']);
        $this->assertSame($managedMember->id, $task->fresh()->assignee_id);
    }

    private function assignmentCandidates(mixed $value): Collection
    {
        $this->assertInstanceOf(Collection::class, $value);

        return $value;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function userWithRole(string $role, array $attributes = []): User
    {
        return User::factory()->create([
            ...$attributes,
            'role_id' => Role::query()->where('name', $role)->value('id'),
        ]);
    }
}
