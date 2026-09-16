<?php

namespace Tests\Feature;

use App\Enums\TaskState;
use App\Exceptions\AccountLifecycleException;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\AccountLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountLifecycleIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_last_active_manager_cannot_self_delete_via_profile(): void
    {
        $manager = $this->userWithRole('manager');

        $response = $this->actingAs($manager)->delete('/profile', [
            'password' => 'password',
        ]);

        $response->assertStatus(409);
        $this->assertNotSoftDeleted($manager);
        $this->assertSame(1, User::query()->whereHas('role', fn ($q) => $q->where('name', 'manager'))->count());
    }

    public function test_manager_cannot_delete_last_active_manager_via_team_management(): void
    {
        $manager1 = $this->userWithRole('manager');
        $manager2 = $this->userWithRole('manager');

        // Deleting one of two managers succeeds
        $this->actingAs($manager1)
            ->deleteJson("/team-management/{$manager2->id}")
            ->assertOk();
        $this->assertSoftDeleted($manager2);

        // Attempting to delete the only remaining active manager fails
        $otherUser = $this->userWithRole('team_member');
        $this->expectException(AccountLifecycleException::class);
        $this->expectExceptionMessage('Cannot delete the last active manager.');
        app(AccountLifecycleService::class)->assertCanDelete($manager1, $otherUser);
    }

    public function test_manager_cannot_deactivate_last_active_manager(): void
    {
        $manager1 = $this->userWithRole('manager');
        $otherUser = $this->userWithRole('team_member');

        $this->expectException(AccountLifecycleException::class);
        $this->expectExceptionMessage('Cannot deactivate the last active manager.');
        app(AccountLifecycleService::class)->assertCanDeactivate($manager1, $otherUser);
    }

    public function test_cannot_change_role_of_last_active_manager_to_non_manager(): void
    {
        $manager = $this->userWithRole('manager');
        $teamMemberRole = Role::query()->where('name', 'team_member')->firstOrFail();

        $this->expectException(AccountLifecycleException::class);
        $this->expectExceptionMessage('Cannot change the role of the last active manager.');

        app(AccountLifecycleService::class)->assertCanChangeRole($manager, $teamMemberRole->id, $manager);
    }

    public function test_user_cannot_self_manage_via_team_management(): void
    {
        $manager = $this->userWithRole('manager');

        $this->actingAs($manager)
            ->postJson("/team-management/{$manager->id}/deactivate")
            ->assertStatus(403);

        $this->actingAs($manager)
            ->deleteJson("/team-management/{$manager->id}")
            ->assertStatus(403);
    }

    #[DataProvider('activeTaskStatesProvider')]
    public function test_user_with_active_assigned_tasks_cannot_be_deleted_or_deactivated(TaskState $state): void
    {
        $manager = $this->userWithRole('manager');
        $member = $this->userWithRole('team_member');
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);

        $task = Task::query()->create([
            'title' => 'Active state task',
            'project_id' => $project->id,
            'assignee_id' => $member->id,
            'reviewer_id' => $manager->id,
            'status' => $state,
            'priority' => 'Medium',
        ]);

        // Attempt deactivation
        $deactivateResponse = $this->actingAs($manager)
            ->postJson("/team-management/{$member->id}/deactivate");

        $deactivateResponse->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Cannot deactivate a user with active task assignments. Reassign their tasks first.',
            ]);
        $this->assertTrue($member->fresh()->isActive());

        // Attempt deletion
        $deleteResponse = $this->actingAs($manager)
            ->deleteJson("/team-management/{$member->id}");

        $deleteResponse->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Cannot delete a user with assigned tasks. Deactivate the user or reassign their tasks first.',
            ]);
        $this->assertNotSoftDeleted($member);
    }

    public static function activeTaskStatesProvider(): array
    {
        return [
            'Not Started' => [TaskState::NotStarted],
            'In Progress' => [TaskState::InProgress],
            'On Hold' => [TaskState::OnHold],
            'Submitted' => [TaskState::Submitted],
            'In Review' => [TaskState::InReview],
            'Revision Requested' => [TaskState::RevisionRequested],
        ];
    }

    public function test_user_with_active_reviewer_duties_cannot_be_deleted_or_deactivated(): void
    {
        $manager = $this->userWithRole('manager');
        $reviewer = $this->userWithRole('manager');
        $otherManager = $this->userWithRole('manager');
        $pm = $this->userWithRole('project_manager');
        $member = $this->userWithRole('team_member');
        $project = Project::factory()->create(['project_manager_id' => $pm->id]);

        $task = new Task([
            'title' => 'Reviewer task',
            'project_id' => $project->id,
            'assignee_id' => $member->id,
            'status' => TaskState::InReview,
            'priority' => 'Medium',
        ]);
        $task->forceFill(['reviewer_id' => $reviewer->id])->save();

        // Deactivation blocked
        $this->actingAs($manager)
            ->postJson("/team-management/{$reviewer->id}/deactivate")
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Cannot deactivate a user who is the reviewer for active tasks. Reassign their reviewer duties first.',
            ]);
        $this->assertTrue($reviewer->fresh()->isActive());

        // Deletion blocked
        $this->actingAs($manager)
            ->deleteJson("/team-management/{$reviewer->id}")
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Cannot delete a user who is the reviewer for active tasks. Reassign their reviewer duties first.',
            ]);
        $this->assertNotSoftDeleted($reviewer);
    }

    public function test_user_with_only_completed_or_cancelled_tasks_can_be_deactivated_while_deletion_is_blocked(): void
    {
        $manager = $this->userWithRole('manager');
        $member = $this->userWithRole('team_member');
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);

        $completedTask = Task::query()->create([
            'title' => 'Done task',
            'project_id' => $project->id,
            'assignee_id' => $member->id,
            'reviewer_id' => $manager->id,
            'status' => TaskState::Completed,
            'priority' => 'Low',
        ]);

        $cancelledTask = Task::query()->create([
            'title' => 'Cancelled task',
            'project_id' => $project->id,
            'assignee_id' => $member->id,
            'reviewer_id' => $manager->id,
            'status' => TaskState::Cancelled,
            'priority' => 'Low',
        ]);

        // Deactivation succeeds: preserves historical attribution
        $this->actingAs($manager)
            ->postJson("/team-management/{$member->id}/deactivate")
            ->assertOk();
        $this->assertFalse($member->fresh()->isActive());

        // Deletion is blocked because completed assignments exist (preserves canonical attribution)
        $this->actingAs($manager)
            ->deleteJson("/team-management/{$member->id}")
            ->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Cannot delete a user with assigned tasks. Deactivate the user or reassign their tasks first.',
            ]);
        $this->assertNotSoftDeleted($member);

        // Historical tasks remain attributed to user
        $this->assertSame($member->id, $completedTask->fresh()->assignee_id);
        $this->assertSame($member->id, $cancelledTask->fresh()->assignee_id);

        // User with NO assigned tasks can be deleted
        $unassignedMember = $this->userWithRole('team_member');
        $this->actingAs($manager)
            ->deleteJson("/team-management/{$unassignedMember->id}")
            ->assertOk();
        $this->assertSoftDeleted($unassignedMember);
    }

    private function userWithRole(string $role): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('name', $role)->value('id'),
            'password' => bcrypt('password'),
        ]);
    }
}
