<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CoreRequestContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Notification::fake();
    }

    public function test_forged_creation_metadata_is_rejected_including_null_and_versions(): void
    {
        [$manager, $pm, $member, $project, $payload] = $this->fixtures();
        $this->actingAs($manager);
        foreach ([
            ['review_due_date', null], ['review_due_date', today()->addDays(3)->toDateString()],
            ['status', 'completed'], ['progress', 100], ['revision_due_date', null],
            ['execution_due_date', null], ['lock_version', 10], ['expected_version', 10],
            ['created_by', $member->id], ['assigned_by', $member->id],
            ['event_type', 'task.approved'], ['sequence', 99], ['approved_by', $manager->id],
        ] as [$field, $value]) {
            $this->postJson('/tasks', $payload + [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->assertDatabaseCount('tasks', 0);
            $this->assertDatabaseCount('task_events', 0);
        }
    }

    public function test_canonical_creation_and_reviewer_membership_authorization_contract(): void
    {
        [$manager, $pm, $member, $project, $payload] = $this->fixtures();
        $otherPm = $this->user('project_manager');
        $outsider = $this->user('team_member');
        $inactive = $this->user('manager');
        $inactive->forceFill(['active' => false])->save();
        $this->actingAs($manager)->postJson('/tasks', array_replace($payload, ['reviewer_id' => $member->id]))->assertUnprocessable();
        $this->postJson('/tasks', array_replace($payload, ['reviewer_id' => $inactive->id]))->assertUnprocessable();
        $this->postJson('/tasks', array_replace($payload, ['reviewer_id' => $otherPm->id]))->assertUnprocessable();
        $this->postJson('/tasks', array_replace($payload, ['assignee_id' => $outsider->id]))->assertUnprocessable();
        $this->actingAs($otherPm)->postJson('/tasks', $payload)->assertForbidden();
        $this->actingAs($member)->postJson('/tasks', $payload)->assertForbidden();
        $this->actingAs($pm)->postJson('/tasks', $payload)->assertOk();
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseCount('task_events', 1);
        $this->assertDatabaseCount('task_histories', 1);
        $task = Task::query()->sole();
        $this->assertSame('not_started', $task->machineState()->value);
        $this->assertSame(0, $task->progress);
        $this->assertSame(1, $task->lock_version);
        $this->assertNull($task->review_due_date);
    }

    public function test_team_json_contract_preserves_password_identity_and_permission_boundaries(): void
    {
        [$manager, $pm, $member] = $this->fixtures();
        $passwordHash = $member->password;
        $payload = ['name' => 'Edited member', 'email' => $member->email, 'role_id' => $member->role_id, 'password' => ''];
        $this->actingAs($manager)->putJson('/team-management/'.$member->id, $payload)->assertOk()->assertJsonPath('id', $member->id);
        $this->assertSame($passwordHash, $member->fresh()->password);
        $this->putJson('/team-management/'.$member->id, array_replace($payload, ['password' => 'R41-Replacement-123!']))->assertOk();
        $this->assertTrue(Hash::check('R41-Replacement-123!', $member->fresh()->password));
        $this->putJson('/team-management/'.$member->id, array_replace($payload, ['role_id' => 99999]))->assertUnprocessable();
        $this->putJson('/team-management/999999', $payload)->assertNotFound();
        $this->putJson('/team-management/'.$manager->id, ['name' => $manager->name, 'email' => $manager->email, 'role_id' => $member->role_id])->assertForbidden();
        $this->actingAs($pm)->putJson('/team-management/'.$member->id, array_replace($payload, ['role_id' => $manager->role_id]))->assertForbidden();
        $this->actingAs($member)->putJson('/team-management/'.$pm->id, $payload)->assertForbidden();
        $this->assertSame($member->role_id, $member->fresh()->role_id);
        $this->assertSame($member->email, $member->fresh()->email);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->value('id')]);
    }

    private function fixtures(): array
    {
        $manager = $this->user('manager');
        $pm = $this->user('project_manager');
        $member = $this->user('team_member');
        $project = Project::factory()->create(['project_manager_id' => $pm->id]);
        $project->members()->attach([$pm->id, $member->id]);

        return [$manager, $pm, $member, $project, [
            'title' => 'Canonical task', 'project_id' => $project->id, 'assignee_id' => $member->id,
            'reviewer_id' => $pm->id, 'priority' => 'High', 'due_date' => today()->addDays(4)->toDateString(),
        ]];
    }
}
