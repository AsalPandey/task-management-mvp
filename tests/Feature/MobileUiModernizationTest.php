<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileUiModernizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_guest_layout_exposes_the_mobile_first_login_shell(): void
    {
        $response = $this->get(route('login'));

        $response
            ->assertOk()
            ->assertSee('data-ui-version="phase-3a"', false)
            ->assertSee('class="auth-brand"', false)
            ->assertSee('auth-card', false)
            ->assertSee('css/phase3.css', false)
            ->assertSee('Skip to sign in');
    }

    public function test_team_members_receive_the_five_item_mobile_navigation(): void
    {
        $response = $this->actingAs($this->userWithRole('team_member'))->get(route('team-dashboard'));

        $response
            ->assertOk()
            ->assertSee('aria-label="Mobile primary navigation"', false)
            ->assertSeeInOrder(['Home', 'My Tasks', 'Notifications', 'Search', 'Profile'])
            ->assertSee('aria-current="page"', false)
            ->assertSee('js/phase3.js', false)
            ->assertSee('href="#main-content"', false)
            ->assertSee('id="main-content"', false);
    }

    public function test_management_roles_keep_the_desktop_navigation_without_team_member_bottom_navigation(): void
    {
        foreach (['manager', 'project_manager'] as $role) {
            $response = $this->actingAs($this->userWithRole($role))->get(route('manager.dashboard'));

            $response
                ->assertOk()
                ->assertSee('aria-label="Primary navigation"', false)
                ->assertDontSee('aria-label="Mobile primary navigation"', false);
        }
    }

    public function test_notification_center_groups_recent_items_and_links_task_notifications(): void
    {
        $user = $this->userWithRole('team_member');
        // The notification links to an existing task the recipient may currently view.
        $task = new Task;
        $task->forceFill([
            'id' => 42,
            'title' => 'Accessible mobile task',
            'assignee_id' => $user->id,
            'priority' => 'Medium',
            'status' => 'in_progress',
            'due_date' => now()->addDay(),
        ])->save();
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'ui-test',
            'data' => [
                'type' => 'task_updated',
                'task_id' => 42,
                'task_title' => 'Accessible mobile task',
                'current_state' => 'in_progress',
                'message' => 'The task is ready for your next action.',
            ],
            'read_at' => null,
        ]);

        $response = $this->actingAs($user)->get(route('notifications.all'));

        $response
            ->assertOk()
            ->assertSee('Today')
            ->assertSee('The task is ready for your next action.')
            ->assertSee('Open task')
            ->assertSee(route('tasks', ['search' => 'Accessible mobile task']).'#task-42', false)
            ->assertSee('aria-label="Mark notification as read"', false);
    }

    public function test_timeline_renderer_uses_dom_text_nodes_for_untrusted_event_content(): void
    {
        $source = file_get_contents(public_path('js/phase3.js'));

        $this->assertIsString($source);
        $this->assertStringContainsString('element.textContent = text', $source);
        $this->assertStringContainsString('html: timeline', $source);
        $this->assertStringNotContainsString('innerHTML = entry', $source);
    }

    private function userWithRole(string $role): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('name', $role)->value('id'),
        ]);
    }
}
