<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
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
