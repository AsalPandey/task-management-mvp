<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\NotificationPreferencePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationSettingsUxContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function memberUser(array $preferences = []): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('name', 'team_member')->value('id'),
            'notification_preferences' => $preferences,
        ]);
    }

    private function assertCheckboxChecked(string $html, string $id, bool $expectedChecked): void
    {
        $hasChecked = preg_match('/id="'.$id.'"[^>]*\schecked[\s>\/]/', $html) === 1;
        $this->assertSame(
            $expectedChecked,
            $hasChecked,
            "Expected checkbox #{$id} to ".($expectedChecked ? 'be checked' : 'NOT be checked').'.'
        );

        $expectedAria = $expectedChecked ? 'aria-checked="true"' : 'aria-checked="false"';
        $this->assertStringContainsString($expectedAria, $html);
    }

    // -------------------------------------------------------------------------
    // 1. Required Notifications Tests
    // -------------------------------------------------------------------------

    public function test_settings_renders_required_categories_as_non_disableable(): void
    {
        $user = $this->memberUser();

        $response = $this->actingAs($user)->get(route('settings'));

        $response->assertOk();
        $response->assertSee('Required Notifications');
        $response->assertSee('These notifications are required for tasks you are responsible for');
        $response->assertSee('Required in-app notification');

        // Required categories must be enumerated
        $response->assertSee('Task Assignments');
        $response->assertSee('Deadlines &amp; Overdue Work', false);
        $response->assertSee('Review &amp; Revision Actions', false);
        $response->assertSee('Workflow Status Changes');

        // Must display required status badge
        $response->assertSee('notification-required', false);
        $response->assertSee('Required');

        // Must NOT render disableable interactive checkboxes for mandatory categories
        $response->assertDontSee('id="taskAssigned"', false);
        $response->assertDontSee('name="task_assigned"', false);
        $response->assertDontSee('id="deadlineReminder"', false);
        $response->assertDontSee('name="deadline_reminder"', false);
    }

    public function test_settings_preferences_endpoint_does_not_disable_mandatory_notifications(): void
    {
        $user = $this->memberUser([
            'task_assigned' => true,
            'deadline_reminder' => true,
            'task_completed' => true,
            'team_updates' => true,
        ]);

        // Attempting to send mandatory fields to the preferences update endpoint
        $this->actingAs($user)->postJson(route('settings.preferences'), [
            'task_assigned' => false,
            'deadline_reminder' => false,
            'task_completed' => false,
            'team_updates' => false,
        ])->assertOk();

        $policy = app(NotificationPreferencePolicy::class);
        $fresh = $user->fresh();

        // Optional preferences are modified
        $this->assertFalse($policy->decideForType($fresh, 'task_approved_completed', 'database')->allowed);
        $this->assertFalse($policy->decideForType($fresh, 'task_updated', 'database')->allowed);

        // Mandatory categories remain strictly allowed
        $this->assertTrue($policy->decideForType($fresh, 'task_assigned', 'database')->allowed);
        $this->assertTrue($policy->decideForType($fresh, 'task_deadline_reminder', 'database')->allowed);
        $this->assertTrue($policy->decideForType($fresh, 'task_overdue', 'database')->allowed);
    }

    public function test_legacy_false_values_in_database_cannot_make_ui_claim_mandatory_is_disabled(): void
    {
        $user = $this->memberUser([
            'task_assigned' => false,
            'deadline_reminder' => false,
            'task_completed' => true,
            'team_updates' => true,
        ]);

        $response = $this->actingAs($user)->get(route('settings'));

        $response->assertOk();

        // UI still renders them as Required with non-disableable badge
        $response->assertSee('Required in-app notification');
        $response->assertDontSee('id="taskAssigned"', false);
        $response->assertDontSee('id="deadlineReminder"', false);
        $response->assertDontSee('name="task_assigned"', false);
        $response->assertDontSee('name="deadline_reminder"', false);
    }

    // -------------------------------------------------------------------------
    // 2. Optional Preferences Tests
    // -------------------------------------------------------------------------

    public function test_optional_preferences_render_enabled_when_stored_true(): void
    {
        $user = $this->memberUser([
            'task_completed' => true,
            'team_updates' => true,
        ]);

        $response = $this->actingAs($user)->get(route('settings'));

        $response->assertOk();
        $this->assertCheckboxChecked($response->getContent(), 'taskCompleted', true);
        $this->assertCheckboxChecked($response->getContent(), 'teamUpdates', true);
    }

    public function test_optional_preferences_render_disabled_when_stored_false(): void
    {
        $user = $this->memberUser([
            'task_completed' => false,
            'team_updates' => false,
        ]);

        $response = $this->actingAs($user)->get(route('settings'));

        $response->assertOk();
        $this->assertCheckboxChecked($response->getContent(), 'taskCompleted', false);
        $this->assertCheckboxChecked($response->getContent(), 'teamUpdates', false);
    }

    public function test_optional_preferences_render_mixed_states(): void
    {
        // Completed false, Updates true
        $user1 = $this->memberUser([
            'task_completed' => false,
            'team_updates' => true,
        ]);

        $res1 = $this->actingAs($user1)->get(route('settings'));
        $res1->assertOk();
        $this->assertCheckboxChecked($res1->getContent(), 'taskCompleted', false);
        $this->assertCheckboxChecked($res1->getContent(), 'teamUpdates', true);

        // Completed true, Updates false
        $user2 = $this->memberUser([
            'task_completed' => true,
            'team_updates' => false,
        ]);

        $res2 = $this->actingAs($user2)->get(route('settings'));
        $res2->assertOk();
        $this->assertCheckboxChecked($res2->getContent(), 'taskCompleted', true);
        $this->assertCheckboxChecked($res2->getContent(), 'teamUpdates', false);
    }

    public function test_optional_preferences_default_to_enabled_when_missing_or_null(): void
    {
        // Null preferences
        $userNull = User::factory()->create([
            'role_id' => Role::query()->where('name', 'team_member')->value('id'),
            'notification_preferences' => null,
        ]);

        $resNull = $this->actingAs($userNull)->get(route('settings'));
        $resNull->assertOk();
        $this->assertCheckboxChecked($resNull->getContent(), 'taskCompleted', true);
        $this->assertCheckboxChecked($resNull->getContent(), 'teamUpdates', true);

        // Empty array preferences
        $userEmpty = $this->memberUser([]);
        $resEmpty = $this->actingAs($userEmpty)->get(route('settings'));
        $resEmpty->assertOk();
        $this->assertCheckboxChecked($resEmpty->getContent(), 'taskCompleted', true);
        $this->assertCheckboxChecked($resEmpty->getContent(), 'teamUpdates', true);
    }

    public function test_optional_preferences_default_to_enabled_when_malformed(): void
    {
        $userMalformed = $this->memberUser([
            'task_completed' => 'invalid_string',
            'team_updates' => 12345,
        ]);

        $response = $this->actingAs($userMalformed)->get(route('settings'));

        $response->assertOk();
        // As per accepted R2B.3 compatibility policy, malformed non-bool values resolve as enabled
        $this->assertCheckboxChecked($response->getContent(), 'taskCompleted', true);
        $this->assertCheckboxChecked($response->getContent(), 'teamUpdates', true);
    }

    public function test_saving_optional_preferences_persists_and_reloads_correctly(): void
    {
        $user = $this->memberUser([
            'task_completed' => true,
            'team_updates' => true,
        ]);

        // Save new preferences: disable completed, keep updates enabled
        $postRes = $this->actingAs($user)->postJson(route('settings.preferences'), [
            'task_completed' => false,
            'team_updates' => true,
        ]);

        $postRes->assertOk();
        $postRes->assertJson([
            'success' => true,
            'preferences' => [
                'task_completed' => false,
                'team_updates' => true,
            ],
        ]);

        $this->assertSame(false, $user->fresh()->notification_preferences['task_completed']);
        $this->assertSame(true, $user->fresh()->notification_preferences['team_updates']);

        // Reload the settings view and assert persisted state is reproduced
        $reloadRes = $this->actingAs($user)->get(route('settings'));
        $reloadRes->assertOk();
        $this->assertCheckboxChecked($reloadRes->getContent(), 'taskCompleted', false);
        $this->assertCheckboxChecked($reloadRes->getContent(), 'teamUpdates', true);
    }

    public function test_updating_preferences_preserves_unrelated_stored_keys(): void
    {
        $user = $this->memberUser([
            'legacy_key' => 'custom_data',
            'task_assigned' => false,
            'task_completed' => true,
            'team_updates' => true,
        ]);

        $this->actingAs($user)->postJson(route('settings.preferences'), [
            'task_completed' => false,
            'team_updates' => false,
        ])->assertOk();

        $fresh = $user->fresh()->notification_preferences;

        $this->assertSame('custom_data', $fresh['legacy_key']);
        $this->assertSame(false, $fresh['task_assigned']);
        $this->assertSame(false, $fresh['task_completed']);
        $this->assertSame(false, $fresh['team_updates']);
    }

    // -------------------------------------------------------------------------
    // 3. Browser Push UI & Device Clarity Tests
    // -------------------------------------------------------------------------

    public function test_browser_push_ui_renders_device_controls_and_multi_device_clarity(): void
    {
        $user = $this->memberUser();

        $response = $this->actingAs($user)->get(route('settings'));

        $response->assertOk();

        // Browser delivery heading and multi-device clarity
        $response->assertSee('Browser notifications on this device');
        $response->assertSee('Browser notifications are enabled separately on each device');

        // Controls
        $response->assertSee('data-push-status', false);
        $response->assertSee('Enable Notifications');
        $response->assertSee('Send Test Notification');
        $response->assertSee('Disable on This Device');
        $response->assertSee('Disable This Device'); // Phase 3B compatibility

        // Blocked permission guidance
        $response->assertSee('data-push-blocked-help', false);
        $response->assertSee('Permission is blocked by your browser or operating system');

        // PWA install card
        $response->assertSee('data-pwa-card', false);
    }

    public function test_system_and_security_notification_notice_renders(): void
    {
        $user = $this->memberUser();

        $response = $this->actingAs($user)->get(route('settings'));

        $response->assertOk();
        $response->assertSee('Account security and critical administrative notices are always sent');
    }

    // -------------------------------------------------------------------------
    // 4. Feedback & Validation Tests
    // -------------------------------------------------------------------------

    public function test_local_notifications_feedback_container_exists(): void
    {
        $user = $this->memberUser();

        $response = $this->actingAs($user)->get(route('settings'));

        $response->assertOk();
        $response->assertSee('id="notificationsMessage"', false);
        $response->assertSee('role="status"', false);
        $response->assertSee('aria-live="polite"', false);
    }

    public function test_preferences_validation_requires_booleans(): void
    {
        $user = $this->memberUser();

        $response = $this->actingAs($user)->postJson(route('settings.preferences'), [
            'task_completed' => 'not-a-bool',
            'team_updates' => 999,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['task_completed', 'team_updates']);
    }

    public function test_preferences_validation_requires_both_fields(): void
    {
        $user = $this->memberUser();

        $response = $this->actingAs($user)->postJson(route('settings.preferences'), []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['task_completed', 'team_updates']);
    }

    // -------------------------------------------------------------------------
    // 5. Authorization & CSRF Tests
    // -------------------------------------------------------------------------

    public function test_guest_cannot_access_settings_or_update_preferences(): void
    {
        $this->get(route('settings'))->assertRedirect(route('login'));

        $this->postJson(route('settings.preferences'), [
            'task_completed' => true,
            'team_updates' => true,
        ])->assertUnauthorized();
    }
}
