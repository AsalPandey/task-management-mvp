<?php

namespace Tests\Feature;

use App\Contracts\BrowserPushTransport;
use App\Jobs\SendBrowserPushNotification;
use App\Models\BrowserPushSubscription;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskReviewWorkflowNotification;
use App\Services\AccountSessionSecurity;
use App\Services\TaskNotificationDispatcher;
use App\Services\WebPushDestinationValidator;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\TestCase;

class R2aSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'manager'): User
    {
        return User::factory()->create(['role_id' => Role::firstOrCreate(['name' => $role])->id]);
    }

    private function browser(?string $id = null): void
    {
        Auth::forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app->forgetInstance(StartSession::class);
        $this->defaultCookies = [];
        $this->withCredentials();
        if ($id) {
            $this->withCookie(config('session.cookie'), $id);
        }
    }

    private function loginBrowser(User $user): string
    {
        config(['session.driver' => 'database']);
        $this->browser();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();

        return session()->getId();
    }

    public function test_password_change_preserves_current_browser_and_revokes_other_database_session(): void
    {
        $user = $this->user();
        $a = $this->loginBrowser($user);
        $b = $this->loginBrowser($user);
        $this->assertNotSame($a, $b);
        $this->browser($a);
        $this->get('/tasks')->assertOk();
        $token = session()->token();
        $this->put('/password', ['current_password' => 'password', 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertSessionHasNoErrors();
        $newA = session()->getId();
        $this->assertNotSame($a, $newA);
        $this->assertNotSame($token, session()->token());
        $this->browser($newA);
        $this->get('/tasks')->assertOk();
        $this->browser($b);
        $this->get('/tasks')->assertRedirect('/login');
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    public function test_dormant_session_cannot_revive_after_deactivate_reactivate(): void
    {
        $manager = $this->user();
        $user = $this->user('team_member');
        $b = $this->loginBrowser($user);
        $a = $this->loginBrowser($manager);
        $this->browser($a);
        $this->postJson('/team-management/'.$user->id.'/deactivate')->assertOk();
        $this->postJson('/team-management/'.$user->id.'/activate')->assertOk();
        $this->browser($b);
        $this->get('/tasks')->assertRedirect('/login');
        $fresh = $this->loginBrowser($user);
        $this->browser($fresh);
        $this->get('/tasks')->assertOk();
    }

    public function test_role_change_revokes_existing_browser(): void
    {
        $admin = $this->user();
        $user = $this->user();
        $role = Role::firstOrCreate(['name' => 'team_member']);
        $b = $this->loginBrowser($user);
        $a = $this->loginBrowser($admin);
        $this->browser($a);
        $this->putJson('/team-management/'.$user->id, ['name' => $user->name, 'email' => $user->email, 'role_id' => $role->id])->assertOk();
        $this->browser($b);
        $this->get('/tasks')->assertRedirect('/login');
    }

    public function test_stored_notification_content_is_redacted_after_authorization_loss(): void
    {
        $old = $this->user('project_manager');
        $manager = $this->user();
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $task = $this->task($project, $old, $manager);
        $id = (string) Str::uuid();
        $payload = ['task_id' => $task->id, 'task_title' => 'PRIVATE-TASK-SECRET', 'message' => 'PRIVATE-TASK-SECRET', 'type' => 'task_submitted'];
        DB::table('notifications')->insert(['id' => $id, 'type' => 'test', 'notifiable_type' => User::class, 'notifiable_id' => $old->id, 'data' => json_encode($payload), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($old)->get('/notifications/all')->assertOk()->assertDontSee('PRIVATE-TASK-SECRET');
        $this->get('/tasks/'.$task->id.'/edit')->assertForbidden();
        $this->assertStringNotContainsString('PRIVATE-TASK-SECRET', $old->notifications()->first()->toJson());
        $this->assertStringContainsString('PRIVATE-TASK-SECRET', DB::table('notifications')->where('id', $id)->value('data'));
    }

    public function test_new_notification_does_not_reach_unauthorized_former_creator(): void
    {
        Notification::fake();
        $old = $this->user('project_manager');
        $manager = $this->user();
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $task = $this->task($project, $old, $manager);
        app(TaskNotificationDispatcher::class)->dispatchTaskSubmitted($task, $task->assignee);
        Notification::assertNothingSentTo($old);
        Notification::assertSentTo($manager, TaskReviewWorkflowNotification::class);
    }

    public function test_roster_does_not_serialize_account_settings(): void
    {
        $member = $this->user('team_member');
        $other = $this->user('team_member');
        $project = Project::factory()->create();
        $project->members()->attach([$member->id, $other->id]);
        $response = $this->actingAs($member)->getJson('/projects/'.$project->id.'/members')->assertOk();
        foreach ($response->json('members') as $row) {
            $this->assertEqualsCanonicalizing(['id', 'name', 'active', 'role'], array_keys($row));
            $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($row['role']));
        }
    }

    private function task(Project $project, User $creator, User $reviewer): Task
    {
        $task = new Task;
        $task->forceFill(['title' => 'Security test task', 'project_id' => $project->id, 'created_by' => $creator->id, 'assignee_id' => $this->user('team_member')->id, 'reviewer_id' => $reviewer->id, 'priority' => 'Medium', 'status' => 'not_started', 'due_date' => now()->addDays(3)])->save();

        return $task;
    }

    public function test_application_security_headers(): void
    {
        $this->get('/login')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_password_confirmation_is_rate_limited(): void
    {
        $this->actingAs($this->user());
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/confirm-password', ['password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/confirm-password', ['password' => 'wrong'])->assertTooManyRequests();
    }

    public function test_admin_password_reset_revokes_both_existing_sessions(): void
    {
        $admin = $this->user();
        $user = $this->user('team_member');
        $a = $this->loginBrowser($user);
        $b = $this->loginBrowser($user);
        $manager = $this->loginBrowser($admin);
        $this->browser($manager);
        $this->putJson('/team-management/'.$user->id, ['name' => $user->name, 'email' => $user->email, 'password' => 'reset-password'])->assertOk();
        foreach ([$a, $b] as $id) {
            $this->browser($id);
            $this->getJson('/tasks')->assertUnauthorized();
        }
    }

    public function test_password_broker_reset_revokes_existing_session(): void
    {
        $user = $this->user();
        $b = $this->loginBrowser($user);
        $token = Password::createToken($user);
        $this->browser();
        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'reset-password', 'password_confirmation' => 'reset-password'])->assertRedirect('/login')->assertSessionHasNoErrors();
        $this->browser($b);
        $this->get('/tasks')->assertRedirect('/login');
    }

    public function test_json_password_change_retains_current_session_and_revokes_other_session(): void
    {
        $user = $this->user();
        $a = $this->loginBrowser($user);
        $b = $this->loginBrowser($user);
        $this->browser($a);
        $this->postJson('/settings/profile', ['name' => $user->name, 'email' => $user->email, 'current_password' => 'password', 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertOk();
        $newA = session()->getId();
        $this->assertNotSame($a, $newA);
        $this->browser($newA);
        $this->get('/tasks')->assertOk();
        $this->browser($b);
        $this->get('/tasks')->assertRedirect('/login');
    }

    public function test_deactivated_session_stays_revoked_after_reactivation_even_after_attempting_access(): void
    {
        $admin = $this->user();
        $user = $this->user('team_member');
        $b = $this->loginBrowser($user);
        $a = $this->loginBrowser($admin);
        $this->browser($a);
        $this->postJson('/team-management/'.$user->id.'/deactivate')->assertOk();
        $this->browser($b);
        $this->get('/tasks')->assertRedirect('/login');
        $this->browser($a);
        $this->postJson('/team-management/'.$user->id.'/activate')->assertOk();
        $this->browser($b);
        $this->get('/tasks')->assertRedirect('/login');
    }

    public function test_permission_reduction_revokes_existing_session(): void
    {
        $user = $this->user();
        $permission = Permission::create(['name' => 'security-test-permission']);
        $user->role->permissions()->attach($permission);
        $b = $this->loginBrowser($user);
        $user->role->permissions()->detach($permission);
        $this->browser($b);
        $this->get('/tasks')->assertRedirect('/login');
    }

    public function test_pre_hardening_session_without_fingerprint_must_reauthenticate(): void
    {
        $user = $this->user();
        $id = $this->loginBrowser($user);
        $session = DB::table('sessions')->where('id', $id)->first();
        $payload = unserialize(base64_decode($session->payload));
        Arr::forget($payload, AccountSessionSecurity::SESSION_KEY);
        DB::table('sessions')->where('id', $id)->update(['payload' => base64_encode(serialize($payload))]);
        $this->assertFalse(Arr::has(unserialize(base64_decode(DB::table('sessions')->where('id', $id)->value('payload'))), AccountSessionSecurity::SESSION_KEY));
        $this->browser($id);
        $this->get('/tasks')->assertRedirect('/login');
    }

    public function test_old_remember_cookie_cannot_restore_access_after_deactivation_cycle(): void
    {
        $user = $this->user();
        config(['session.driver' => 'database']);
        $this->browser();
        $response = $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => true]);
        $name = Auth::guard('web')->getRecallerName();
        $cookie = $response->getCookie($name)->getValue();
        $user->refresh()->update(['active' => false]);
        $user->update(['active' => true]);
        $this->browser();
        $this->withCookie($name, $cookie)->get('/tasks')->assertRedirect('/login');
    }

    public function test_provisioned_unverified_accounts_have_access_without_verification_gate(): void
    {
        $user = $this->user();
        $user->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($user)->get('/tasks')->assertOk();
        $this->get('/verify-email')->assertOk()->assertSee('Email verification is optional');
        $this->assertNotContains('verified', app('router')->getRoutes()->getByName('tasks')->gatherMiddleware());
        $this->browser();
        $this->post('/register', ['name' => 'Unauthorized', 'email' => 'new@example.test', 'password' => 'password'])->assertNotFound();
    }

    public function test_notification_owner_boundary_and_project_content_revocation(): void
    {
        $member = $this->user('team_member');
        $other = $this->user('team_member');
        $project = Project::factory()->create();
        $project->members()->attach($member);
        $note = $member->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'test', 'data' => ['project_id' => $project->id, 'message' => 'PRIVATE-PROJECT']]);
        $this->actingAs($member)->get('/notifications/all')->assertSee('PRIVATE-PROJECT');
        $project->members()->detach($member);
        $this->get('/notifications/all')->assertDontSee('PRIVATE-PROJECT');
        $this->actingAs($other)->postJson('/notifications/read/'.$note->id)->assertOk();
        $this->assertNull($note->fresh()->read_at);
        $this->get('/notifications/all')->assertDontSee('PRIVATE-PROJECT');
    }

    public function test_queued_push_rechecks_current_authorization_before_transport(): void
    {
        $old = $this->user('project_manager');
        $manager = $this->user();
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $task = $this->task($project, $old, $manager);
        $subscription = new BrowserPushSubscription;
        $subscription->forceFill([
            'user_id' => $old->id,
            'endpoint' => 'https://push.example.test/subscription',
            'endpoint_hash' => hash('sha256', 'https://push.example.test/subscription'),
            'public_key' => 'test-public-key',
            'auth_secret' => 'test-auth-secret',
            'content_encoding' => 'aes128gcm',
        ])->save();
        $this->mock(WebPushDestinationValidator::class)->shouldNotReceive('validateUrl');
        $transport = \Mockery::mock(BrowserPushTransport::class);
        $transport->shouldNotReceive('send');
        (new SendBrowserPushNotification($old->id, (string) Str::uuid(), ['data' => ['task_id' => $task->id], 'body' => 'PRIVATE']))->handle($transport);
        $this->assertDatabaseCount('browser_push_deliveries', 0);
    }

    public function test_notification_management_excerpts_are_redacted_after_downgrade_with_task_access(): void
    {
        $user = $this->user();
        $task = $this->task(Project::factory()->create(), $user, $user);
        $task->update(['assignee_id' => $user->id]);
        $note = $user->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'test', 'data' => [
            'task_id' => $task->id, 'message' => 'Public task update', 'reopen_reason_excerpt' => 'PRIVATE-MANAGEMENT',
        ]]);
        $user->update(['role_id' => Role::firstOrCreate(['name' => 'team_member'])->id]);
        $this->actingAs($user->fresh())->get('/notifications/all')->assertOk()->assertSee('Public task update')->assertDontSee('PRIVATE-MANAGEMENT');
        $this->assertStringContainsString('PRIVATE-MANAGEMENT', DB::table('notifications')->where('id', $note->id)->value('data'));
    }

    public function test_real_notification_channel_suppresses_unauthorized_task_content(): void
    {
        $old = $this->user('project_manager');
        $manager = $this->user();
        $task = $this->task(Project::factory()->create(['project_manager_id' => $manager->id]), $old, $manager);
        $old->notify(new TaskReviewWorkflowNotification($task, $manager, 'submitted', 'Review task'));
        $this->assertDatabaseCount('notifications', 0);
        $manager->notify(new TaskReviewWorkflowNotification($task, $manager, 'submitted', 'Review task'));
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_account_json_is_allow_listed_and_members_cannot_call_management_mutations(): void
    {
        $admin = $this->user();
        $member = $this->user('team_member');
        $response = $this->actingAs($admin)->putJson('/team-management/'.$member->id, ['name' => $member->name, 'email' => $member->email])->assertOk();
        $this->assertEqualsCanonicalizing(['id', 'name', 'active', 'role', 'email', 'role_id'], array_keys($response->json()));
        $this->actingAs($member)->putJson('/team-management/'.$admin->id, ['name' => 'stolen', 'email' => $admin->email])->assertForbidden();
    }

    public function test_export_endpoint_is_rate_limited(): void
    {
        $this->actingAs($this->user());
        for ($i = 0; $i < 10; $i++) {
            $this->get('/analytics/export/csv')->assertOk();
        }
        $this->get('/analytics/export/csv')->assertTooManyRequests();
    }

    public function test_json_password_change_returns_rotated_csrf_token_for_next_request(): void
    {
        $user = $this->user();
        $a = $this->loginBrowser($user);
        $b = $this->loginBrowser($user);
        $this->browser($a);
        $this->get('/settings')->assertOk();
        $oldToken = session()->token();
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $response = $this->withHeader('X-CSRF-TOKEN', $oldToken)->postJson('/settings/profile', [
            'name' => $user->name, 'email' => $user->email, 'current_password' => 'password',
            'password' => 'new-password', 'password_confirmation' => 'new-password',
        ])->assertOk();
        $newToken = $response->json('csrf_token');
        $this->assertSame(session()->token(), $newToken);
        $this->assertNotSame($oldToken, $newToken);
        $newId = session()->getId();
        $this->assertNotSame($a, $newId);
        $this->browser($newId);
        $this->withHeader('X-CSRF-TOKEN', $oldToken)->postJson('/settings/preferences', [])->assertStatus(419);
        $this->browser($newId);
        $this->withHeader('X-CSRF-TOKEN', $newToken)->postJson('/settings/preferences', [
            'task_assigned' => true, 'task_completed' => true, 'deadline_reminder' => true, 'team_updates' => true,
        ])->assertOk();
        $this->browser($newId);
        $this->withHeader('X-CSRF-TOKEN', $newToken)->postJson('/settings/profile', [
            'name' => 'Updated after password change', 'email' => $user->email,
        ])->assertOk();
        $this->browser($b);
        $this->get('/tasks')->assertRedirect('/login');
    }
}
