<?php

namespace Tests\Feature;

use App\Jobs\SendBrowserPushNotification;
use App\Models\BrowserPushSubscription;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Notifications\ProjectMemberAdded;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskDeadlineReminderNotification;
use App\Notifications\TaskReviewWorkflowNotification;
use App\Notifications\TaskUpdatedNotification;
use App\Services\NotificationPreferencePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeBrowserPushTransport;
use Tests\TestCase;

class NotificationPreferencePolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config()->set([
            'webpush.vapid.subject' => 'mailto:push@example.test',
            'webpush.vapid.public_key' => 'public-vapid-key',
            'webpush.vapid.private_key' => 'private-vapid-key',
        ]);
    }

    public static function mandatoryTypes(): array
    {
        return collect([
            'task_assigned',
            'task_deadline_reminder',
            'task_overdue',
            'task_held',
            'task_resumed',
            'task_submitted',
            'task_revision_requested',
            'task_resubmitted',
            'task_reopened_revision_required',
            'task_cancelled',
            'task_reviewer_reassigned',
            'task_deadline_changed',
        ])->mapWithKeys(fn (string $type) => [$type => [$type]])->all();
    }

    #[DataProvider('mandatoryTypes')]
    public function test_mandatory_accountability_types_ignore_legacy_disabled_preferences(string $type): void
    {
        $user = $this->user(['task_assigned' => false, 'deadline_reminder' => false, 'team_updates' => false]);
        $decision = app(NotificationPreferencePolicy::class)->decideForType($user, $type, 'database');

        $this->assertTrue($decision->allowed);
        $this->assertNull($decision->preferenceKey);
    }

    public static function optionalTypes(): array
    {
        return [
            'completion' => ['task_approved_completed', 'task_completed'],
            'task update' => ['task_updated', 'team_updates'],
            'task started' => ['task_started', 'team_updates'],
            'review started' => ['task_review_started', 'team_updates'],
            'revision started' => ['task_revision_started', 'team_updates'],
            'project member added' => ['project_member_added', 'team_updates'],
            'project member removed' => ['project_member_removed', 'team_updates'],
        ];
    }

    #[DataProvider('optionalTypes')]
    public function test_optional_types_follow_their_single_central_preference(string $type, string $key): void
    {
        $disabled = $this->user([$key => false]);
        $enabled = $this->user([$key => true]);
        $policy = app(NotificationPreferencePolicy::class);

        $denied = $policy->decideForType($disabled, $type, 'database');
        $allowed = $policy->decideForType($enabled, $type, 'database');

        $this->assertSame($key, $denied->preferenceKey);
        $this->assertFalse($denied->allowed);
        $this->assertTrue($allowed->allowed);
    }

    public function test_mandatory_assignment_and_deadline_database_notifications_cannot_be_disabled(): void
    {
        Queue::fake();
        [$user, $task] = $this->taskFixture([
            'task_assigned' => false,
            'deadline_reminder' => false,
            'task_completed' => false,
            'team_updates' => false,
        ]);
        $this->subscription($user);

        $user->notify(new TaskAssignedNotification($task, $task->creator));
        $user->notify(new TaskDeadlineReminderNotification($task));

        $this->assertDatabaseCount('notifications', 2);
        Queue::assertPushed(SendBrowserPushNotification::class, 2);
    }

    public function test_mandatory_review_revision_cancel_reopen_and_responsibility_changes_cannot_be_disabled(): void
    {
        [$user, $task] = $this->taskFixture(['task_completed' => false, 'team_updates' => false]);
        $actor = $task->creator;

        foreach ([
            'submitted',
            'revision_requested',
            'cancelled',
            'reopened_revision_required',
            'reviewer_reassigned',
            'deadline_changed',
        ] as $transition) {
            $user->notify(new TaskReviewWorkflowNotification($task, $actor, $transition, 'Required action'));
        }

        $this->assertDatabaseCount('notifications', 6);
    }

    public function test_optional_completion_and_general_update_suppress_database_and_push_when_disabled(): void
    {
        Queue::fake();
        [$user, $task] = $this->taskFixture(['task_completed' => false, 'team_updates' => false]);
        $this->subscription($user);

        $user->notify(new TaskReviewWorkflowNotification($task, $task->creator, 'approved_completed', 'None'));
        $user->notify(new TaskUpdatedNotification($task, ['title' => 'Changed'], $task->creator));

        $this->assertDatabaseCount('notifications', 0);
        Queue::assertNothingPushed();
    }

    public function test_optional_completion_and_general_update_preserve_database_and_push_when_enabled(): void
    {
        Queue::fake();
        [$user, $task] = $this->taskFixture(['task_completed' => true, 'team_updates' => true]);
        $this->subscription($user);

        $user->notify(new TaskReviewWorkflowNotification($task, $task->creator, 'approved_completed', 'None'));
        $user->notify(new TaskUpdatedNotification($task, ['title' => 'Changed'], $task->creator));

        $this->assertDatabaseCount('notifications', 2);
        Queue::assertPushed(SendBrowserPushNotification::class, 2);
    }

    public function test_project_information_follows_team_updates_preference(): void
    {
        $disabled = $this->projectMemberFixture(['team_updates' => false]);
        $disabled['member']->notify(new ProjectMemberAdded($disabled['project'], $disabled['manager']));
        $this->assertDatabaseCount('notifications', 0);

        $enabled = $this->projectMemberFixture(['team_updates' => true]);
        $enabled['member']->notify(new ProjectMemberAdded($enabled['project'], $enabled['manager']));
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_disabled_device_suppresses_push_but_not_mandatory_database_notification(): void
    {
        Queue::fake();
        [$user, $task] = $this->taskFixture(['task_assigned' => false]);
        $this->subscription($user, disabled: true);

        $user->notify(new TaskAssignedNotification($task, $task->creator));

        $this->assertDatabaseCount('notifications', 1);
        Queue::assertNothingPushed();
    }

    public function test_authorization_denial_wins_over_enabled_preferences(): void
    {
        Queue::fake();
        [$authorized, $task] = $this->taskFixture(['team_updates' => true]);
        $unauthorized = $this->user(['team_updates' => true], 'team_member');
        $this->subscription($unauthorized);

        $unauthorized->notify(new TaskUpdatedNotification($task, ['title' => 'Protected'], $task->creator));

        $this->assertDatabaseCount('notifications', 0);
        Queue::assertNothingPushed();
        $this->assertNotSame($authorized->id, $unauthorized->id);
    }

    public function test_inactive_user_and_removed_task_visibility_remain_safely_suppressed(): void
    {
        [$user, $task] = $this->taskFixture(['team_updates' => true]);
        $user->forceFill(['active' => false])->save();
        $user->notify(new TaskUpdatedNotification($task, ['title' => 'Inactive'], $task->creator));
        $this->assertDatabaseCount('notifications', 0);

        $user->forceFill(['active' => true])->save();
        $task->forceFill(['assignee_id' => null])->save();
        $user->notify(new TaskUpdatedNotification($task->fresh(), ['title' => 'Removed'], $task->creator));
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_missing_malformed_and_old_partial_preferences_use_enabled_compatibility_defaults(): void
    {
        $policy = app(NotificationPreferencePolicy::class);
        $missing = $this->user(null);
        $partial = $this->user(['task_assigned' => false]);
        $malformed = $this->user(['team_updates' => true]);
        DB::table('users')->where('id', $malformed->id)->update(['notification_preferences' => '"invalid-shape"']);
        $malformed->refresh();

        foreach ([$missing, $partial, $malformed] as $user) {
            $this->assertTrue($policy->decideForType($user, 'task_updated', 'database')->allowed);
            $this->assertTrue($policy->decideForType($user, 'task_approved_completed', 'database')->allowed);
        }
    }

    public function test_queued_optional_push_rechecks_a_later_preference_change(): void
    {
        [$user, $task] = $this->taskFixture(['team_updates' => true]);
        $this->subscription($user);
        $notification = new TaskUpdatedNotification($task, ['title' => 'Queued'], $task->creator);
        $message = $notification->toBrowserPush($user);
        $job = new SendBrowserPushNotification($user->id, (string) Str::uuid(), $message->payload((string) Str::uuid()));

        $user->forceFill(['notification_preferences' => ['team_updates' => false]])->save();
        $transport = new FakeBrowserPushTransport;
        $job->handle($transport);

        $this->assertSame([], $transport->sent);
        $this->assertDatabaseCount('browser_push_deliveries', 0);
    }

    public function test_browser_push_self_test_is_explicit_and_ignores_ordinary_preferences(): void
    {
        $user = $this->user(['task_completed' => false, 'team_updates' => false]);
        $this->subscription($user);
        $notificationId = (string) Str::uuid();
        $job = new SendBrowserPushNotification($user->id, $notificationId, [
            'title' => 'Test',
            'body' => 'Explicit test',
            'data' => [
                'notification_id' => $notificationId,
                'type' => 'browser_push_test',
                'target' => '/notifications/all',
            ],
        ]);
        $transport = new FakeBrowserPushTransport;

        $job->handle($transport);

        $this->assertCount(1, $transport->sent);
    }

    public function test_preference_update_preserves_legacy_mandatory_fields_and_ui_marks_them_required(): void
    {
        $user = $this->user([
            'task_assigned' => false,
            'deadline_reminder' => false,
            'task_completed' => true,
            'team_updates' => true,
        ]);

        $this->actingAs($user)->postJson(route('settings.preferences'), [
            'task_completed' => false,
            'team_updates' => false,
        ])->assertOk();

        $this->assertSame([
            'task_assigned' => false,
            'deadline_reminder' => false,
            'task_completed' => false,
            'team_updates' => false,
        ], $user->fresh()->notification_preferences);

        $this->get(route('settings'))
            ->assertOk()
            ->assertSee('Required in-app notification')
            ->assertDontSee('id="taskAssigned"', false)
            ->assertDontSee('id="deadlineReminder"', false);
    }

    public function test_optional_delivery_keeps_after_commit_and_rollback_semantics(): void
    {
        Queue::fake();
        [$user, $task] = $this->taskFixture(['team_updates' => true]);
        $this->subscription($user);

        DB::beginTransaction();
        $user->notify(new TaskUpdatedNotification($task, ['title' => 'Rollback'], $task->creator));
        DB::rollBack();
        $this->assertDatabaseCount('notifications', 0);
        Queue::assertNothingPushed();

        DB::transaction(function () use ($user, $task): void {
            $user->notify(new TaskUpdatedNotification($task, ['title' => 'Commit'], $task->creator));
        });
        $this->assertDatabaseCount('notifications', 1);
        Queue::assertPushed(SendBrowserPushNotification::class, 1);
    }

    private function taskFixture(?array $preferences): array
    {
        $manager = $this->user();
        $assignee = $this->user($preferences, 'team_member');
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $project->members()->attach($assignee->id);
        $task = Task::query()->create([
            'project_id' => $project->id,
            'title' => 'Preference contract task',
            'assignee_id' => $assignee->id,
            'created_by' => $manager->id,
            'assigned_by' => $manager->id,
            'priority' => 'Medium',
            'status' => 'not_started',
            'due_date' => now(config('app.timezone'))->addDay()->toDateString(),
        ]);
        $task->forceFill(['execution_due_date' => $task->due_date])->save();

        return [$assignee, $task->fresh(['creator'])];
    }

    private function projectMemberFixture(array $preferences): array
    {
        $manager = $this->user();
        $member = $this->user($preferences, 'team_member');
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $project->members()->attach($member->id);

        return compact('manager', 'member', 'project');
    }

    private function user(?array $preferences = null, string $role = 'manager'): User
    {
        $attributes = [
            'role_id' => Role::query()->where('name', $role)->value('id'),
        ];
        if (func_num_args() > 0) {
            $attributes['notification_preferences'] = $preferences;
        }

        return User::factory()->create($attributes);
    }

    private function subscription(User $user, bool $disabled = false): BrowserPushSubscription
    {
        $endpoint = 'https://push.example.test/'.Str::uuid();
        $subscription = new BrowserPushSubscription;
        $subscription->forceFill([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => BrowserPushSubscription::endpointHash($endpoint),
            'public_key' => 'public-key',
            'auth_secret' => 'auth-secret',
            'content_encoding' => 'aes128gcm',
            'disabled_at' => $disabled ? now() : null,
        ])->save();

        return $subscription;
    }
}
