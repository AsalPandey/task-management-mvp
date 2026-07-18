<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProjectManagerAccountBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGINAL_PASSWORD = 'OriginalPassword123!';

    private const REPLACEMENT_PASSWORD = 'ReplacementPassword123!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Notification::fake();
    }

    public function test_project_manager_cannot_change_another_members_email(): void
    {
        [$projectManager, $member] = $this->projectManagerAndMember();

        $this->actingAs($projectManager)
            ->putJson("/team-management/{$member->id}", [
                'name' => $member->name,
                'email' => 'attacker-controlled@example.com',
            ])
            ->assertForbidden();

        $this->assertSame($member->email, $member->fresh()->email);
    }

    public function test_project_manager_cannot_change_another_members_password(): void
    {
        [$projectManager, $member] = $this->projectManagerAndMember();

        $this->actingAs($projectManager)
            ->putJson("/team-management/{$member->id}", [
                'name' => $member->name,
                'email' => $member->email,
                'password' => self::REPLACEMENT_PASSWORD,
            ])
            ->assertForbidden();

        $member->refresh();
        $this->assertTrue(Hash::check(self::ORIGINAL_PASSWORD, $member->password));
        $this->assertFalse(Hash::check(self::REPLACEMENT_PASSWORD, $member->password));
    }

    public function test_project_manager_cannot_change_another_members_global_role(): void
    {
        [$projectManager, $member] = $this->projectManagerAndMember();
        $originalRoleId = $member->role_id;

        $this->actingAs($projectManager)
            ->putJson("/team-management/{$member->id}", [
                'name' => $member->name,
                'email' => $member->email,
                'role_id' => Role::query()->where('name', 'manager')->value('id'),
            ])
            ->assertForbidden();

        $this->assertSame($originalRoleId, $member->fresh()->role_id);
    }

    public function test_project_manager_cannot_activate_or_deactivate_global_accounts(): void
    {
        [$projectManager, $member, $project] = $this->projectManagerAndMember();
        $inactiveMember = User::factory()->create([
            'active' => false,
            'role_id' => Role::query()->where('name', 'team_member')->value('id'),
        ]);
        $project->members()->attach($inactiveMember->id);

        $this->actingAs($projectManager)
            ->postJson("/team-management/{$member->id}/deactivate")
            ->assertForbidden();
        $this->actingAs($projectManager)
            ->postJson("/team-management/{$inactiveMember->id}/activate")
            ->assertForbidden();

        $this->assertTrue($member->fresh()->active);
        $this->assertFalse($inactiveMember->fresh()->active);
    }

    public function test_manually_crafted_project_manager_request_cannot_partially_mutate_global_account(): void
    {
        [$projectManager, $member, $project] = $this->projectManagerAndMember();
        $original = $member->only(['name', 'email', 'role_id', 'active']);
        $originalPassword = $member->password;

        $this->actingAs($projectManager)
            ->putJson("/team-management/{$member->id}", [
                'name' => 'Taken Over Account',
                'email' => 'taken-over@example.com',
                'password' => self::REPLACEMENT_PASSWORD,
                'role_id' => Role::query()->where('name', 'manager')->value('id'),
                'active' => false,
                'permissions' => ['manage.users'],
                'project_ids' => [$project->id],
            ])
            ->assertForbidden();

        $member->refresh();
        $this->assertSame($original, $member->only(['name', 'email', 'role_id', 'active']));
        $this->assertSame($originalPassword, $member->password);
    }

    public function test_original_credentials_remain_valid_and_replacement_password_cannot_authenticate(): void
    {
        [$projectManager, $member] = $this->projectManagerAndMember();

        $this->actingAs($projectManager)
            ->putJson("/team-management/{$member->id}", [
                'name' => $member->name,
                'email' => $member->email,
                'password' => self::REPLACEMENT_PASSWORD,
            ])
            ->assertForbidden();

        Auth::logout();
        $this->post('/login', [
            'email' => $member->email,
            'password' => self::ORIGINAL_PASSWORD,
        ])->assertRedirect(route('team-dashboard', absolute: false));
        $this->assertAuthenticatedAs($member);

        Auth::logout();
        $this->post('/login', [
            'email' => $member->email,
            'password' => self::REPLACEMENT_PASSWORD,
        ])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_project_manager_uses_dedicated_membership_operation_without_changing_global_account(): void
    {
        [$projectManager, $member, $project] = $this->projectManagerAndMember(false);
        $original = $member->only(['name', 'email', 'password', 'role_id', 'active']);

        $this->actingAs($projectManager)
            ->putJson("/team-management/{$member->id}", ['project_ids' => [$project->id]])
            ->assertForbidden();

        $this->postJson("/projects/{$project->id}/add-member", ['user_id' => $member->id])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('project_user', [
            'project_id' => $project->id,
            'user_id' => $member->id,
            'added_by' => $projectManager->id,
        ]);
        $this->assertSame($original, $member->fresh()->only(['name', 'email', 'password', 'role_id', 'active']));
    }

    public function test_company_manager_can_still_update_global_account_fields(): void
    {
        $manager = User::factory()->create([
            'role_id' => Role::query()->where('name', 'manager')->value('id'),
        ]);
        $member = User::factory()->create([
            'password' => Hash::make(self::ORIGINAL_PASSWORD),
            'role_id' => Role::query()->where('name', 'team_member')->value('id'),
        ]);
        $projectManagerRoleId = Role::query()->where('name', 'project_manager')->value('id');

        $this->actingAs($manager)
            ->putJson("/team-management/{$member->id}", [
                'name' => 'Updated Employee',
                'email' => 'updated-employee@example.com',
                'password' => self::REPLACEMENT_PASSWORD,
                'role_id' => $projectManagerRoleId,
            ])
            ->assertOk()
            ->assertJsonPath('email', 'updated-employee@example.com');

        $member->refresh();
        $this->assertSame('Updated Employee', $member->name);
        $this->assertSame('updated-employee@example.com', $member->email);
        $this->assertSame($projectManagerRoleId, $member->role_id);
        $this->assertTrue(Hash::check(self::REPLACEMENT_PASSWORD, $member->password));
    }

    public function test_account_owner_can_still_update_profile_and_credentials_through_self_service(): void
    {
        $member = User::factory()->create([
            'password' => Hash::make(self::ORIGINAL_PASSWORD),
            'role_id' => Role::query()->where('name', 'team_member')->value('id'),
        ]);

        $this->actingAs($member)
            ->postJson('/settings/profile', [
                'name' => 'Self Updated Member',
                'email' => 'self-updated@example.com',
                'timezone' => 'Asia/Kathmandu',
                'current_password' => self::ORIGINAL_PASSWORD,
                'password' => self::REPLACEMENT_PASSWORD,
                'password_confirmation' => self::REPLACEMENT_PASSWORD,
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $member->refresh();
        $this->assertSame('Self Updated Member', $member->name);
        $this->assertSame('self-updated@example.com', $member->email);
        $this->assertTrue(Hash::check(self::REPLACEMENT_PASSWORD, $member->password));
    }

    public function test_project_manager_cannot_create_a_global_user_account(): void
    {
        [$projectManager] = $this->projectManagerAndMember();

        $this->actingAs($projectManager)
            ->postJson('/team-management', [
                'name' => 'Unauthorized Account',
                'email' => 'unauthorized-account@example.com',
                'password' => self::REPLACEMENT_PASSWORD,
                'role_id' => Role::query()->where('name', 'team_member')->value('id'),
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'unauthorized-account@example.com']);
    }

    public function test_global_account_endpoint_rejects_membership_and_status_fields_without_partial_update(): void
    {
        $manager = User::factory()->create([
            'role_id' => Role::query()->where('name', 'manager')->value('id'),
        ]);
        $member = User::factory()->create([
            'role_id' => Role::query()->where('name', 'team_member')->value('id'),
        ]);
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);

        $this->actingAs($manager)
            ->putJson("/team-management/{$member->id}", [
                'name' => 'Must Not Be Applied',
                'email' => 'must-not-apply@example.com',
                'project_ids' => [$project->id],
                'active' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fields');

        $member->refresh();
        $this->assertNotSame('Must Not Be Applied', $member->name);
        $this->assertNotSame('must-not-apply@example.com', $member->email);
        $this->assertTrue($member->active);
        $this->assertDatabaseMissing('project_user', [
            'project_id' => $project->id,
            'user_id' => $member->id,
        ]);
    }

    public function test_project_manager_ui_does_not_render_global_account_controls(): void
    {
        [$projectManager, $member] = $this->projectManagerAndMember();

        $this->actingAs($projectManager)
            ->get('/team-management')
            ->assertOk()
            ->assertSee($member->name)
            ->assertDontSee('Add Team Member')
            ->assertDontSee('Create Team Member Account')
            ->assertDontSee('id="memberEmail"', false)
            ->assertDontSee('id="editMemberEmail"', false)
            ->assertDontSee('class="action-btn edit-btn"', false)
            ->assertDontSee('class="action-btn delete-btn"', false);
    }

    /** @return array{User, User, Project} */
    private function projectManagerAndMember(bool $attach = true): array
    {
        $projectManager = User::factory()->create([
            'role_id' => Role::query()->where('name', 'project_manager')->value('id'),
        ]);
        $member = User::factory()->create([
            'password' => Hash::make(self::ORIGINAL_PASSWORD),
            'role_id' => Role::query()->where('name', 'team_member')->value('id'),
        ]);
        $project = Project::factory()->create(['project_manager_id' => $projectManager->id]);

        if ($attach) {
            $project->members()->attach($member->id, ['added_by' => $projectManager->id]);
        }

        return [$projectManager, $member, $project];
    }
}
