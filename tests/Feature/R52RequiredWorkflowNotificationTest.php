<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkflowNotificationIntent;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskReviewWorkflowNotification;
use App\Services\RequiredWorkflowNotifications;
use App\Services\TaskLifecycleService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class R52RequiredWorkflowNotificationTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        if (! Schema::hasTable('roles')) {
            $this->artisan('migrate:fresh')->run();
        }
    }

    protected function tearDown(): void
    {
        try {
            if (Schema::hasTable('migrations')) {
                $this->truncateTablesForAllConnections();
            }
        } finally {
            parent::tearDown();
        }
    }

    private function fixture(): array
    {
        $this->seed();
        $manager = User::factory()->create(['role_id' => Role::where('name', 'manager')->value('id')]);
        $member = User::factory()->create(['role_id' => Role::where('name', 'team_member')->value('id')]);
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $project->members()->attach($member->id);

        return [$manager, $member, $project];
    }

    private function failedCreate(bool &$fail, string $correlation): array
    {
        [$manager, $member, $project] = $this->fixture();
        Event::listen(NotificationSending::class, function ($event) use (&$fail) {
            if ($fail && $event->notification instanceof TaskAssignedNotification) {
                throw new RuntimeException('Controlled provider failure');
            }
        });
        $payload = ['title' => 'Recover duty', 'project_id' => $project->id, 'assignee_id' => $member->id,
            'reviewer_id' => $manager->id, 'priority' => 'High'];
        $result = $this->actingAs($manager)->withHeader('X-Correlation-ID', $correlation)->postJson('/tasks', $payload);
        $result->assertOk()->assertJsonPath('notification_status', 'delivery_failed');
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('workflow_notification_intents', 1);
        $intent = WorkflowNotificationIntent::firstOrFail();
        $this->assertSame('pending', $intent->status);
        $this->assertSame(1, $intent->attempts);

        return [$manager, $member, $project, $payload, $intent];
    }

    public function test_failed_delivery_replay_and_twice_retry_create_exactly_one_notice(): void
    {
        $fail = true;
        [$manager, $member, $project, $payload, $intent] = $this->failedCreate($fail, 'r52-recovery');
        $fail = false;
        $this->withHeader('X-Correlation-ID', 'r52-recovery')->postJson('/tasks', $payload)->assertOk()->assertJsonPath('idempotent_replay', true);
        $this->assertDatabaseCount('workflow_notification_intents', 1);
        $this->assertDatabaseCount('notifications', 0);
        app(RequiredWorkflowNotifications::class)->deliver($intent->id);
        app(RequiredWorkflowNotifications::class)->deliver($intent->id);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame($intent->id, DB::table('notifications')->value('id'));
        $this->assertSame('delivered', $intent->fresh()->status);
    }

    public function test_revoked_destination_is_discarded_without_protected_payload(): void
    {
        $fail = true;
        [$manager, $member, $project, $payload, $intent] = $this->failedCreate($fail, 'r52-revoked');
        $fail = false;
        $project->members()->detach($member->id);
        app(RequiredWorkflowNotifications::class)->deliver($intent->id);
        $this->assertSame('discarded', $intent->fresh()->status);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_failure_after_database_send_rolls_back_notice_and_retry_is_atomic(): void
    {
        $fail = true;
        Event::listen(NotificationSent::class, function ($event) use (&$fail) {
            if ($fail && $event->channel === 'database' && $event->notification instanceof TaskAssignedNotification) {
                throw new RuntimeException('Controlled failure during delivery');
            }
        });
        [$manager, $member, $project] = $this->fixture();
        $this->actingAs($manager)->postJson('/tasks', ['title' => 'Atomic send', 'project_id' => $project->id,
            'assignee_id' => $member->id, 'reviewer_id' => $manager->id, 'priority' => 'High'])->assertOk()->assertJsonPath('notification_status', 'delivery_failed');
        $this->assertDatabaseCount('notifications', 0);
        $intent = WorkflowNotificationIntent::firstOrFail();
        $fail = false;
        $intent->update(['available_at' => now()->subMinute()]);
        $this->artisan('app:deliver-required-workflow-notifications')->assertSuccessful();
        $this->artisan('app:deliver-required-workflow-notifications')->assertSuccessful();
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_intent_and_task_rollback_together(): void
    {
        [$manager, $member, $project] = $this->fixture();
        try {
            DB::transaction(function () use ($manager, $member, $project) {
                app(TaskLifecycleService::class)->create(['title' => 'Rollback', 'project_id' => $project->id,
                    'assignee_id' => $member->id, 'reviewer_id' => $manager->id, 'priority' => 'High'], $manager);
                $this->assertDatabaseCount('workflow_notification_intents', 1);
                $this->assertDatabaseCount('notifications', 0);
                throw new RuntimeException('Controlled rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Controlled rollback', $exception->getMessage());
        }
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('workflow_notification_intents', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_review_duty_intent_recovers_and_former_reviewer_intent_terminates(): void
    {
        [$manager, $member, $project] = $this->fixture();
        $task = app(TaskLifecycleService::class)->create(['title' => 'Review recovery', 'project_id' => $project->id,
            'assignee_id' => $member->id, 'reviewer_id' => $manager->id, 'priority' => 'High'], $manager);
        $this->actingAs($member)->postTaskTransitionJson("/tasks/{$task->id}/start")->assertOk();
        $fail = true;
        Event::listen(NotificationSending::class, function ($event) use (&$fail) {
            if ($fail && $event->notification instanceof TaskReviewWorkflowNotification
                && ($event->notification->toArray($event->notifiable)['type'] ?? '') === 'task_submitted') {
                throw new RuntimeException('Controlled review-duty failure');
            }
        });
        $this->postTaskTransitionJson("/tasks/{$task->id}/submit", ['submission_note' => 'Ready'])->assertOk()->assertJsonPath('notification_status', 'delivery_failed');
        $intent = WorkflowNotificationIntent::where('task_id', $task->id)->where('transition', 'submitted')->firstOrFail();
        $this->assertSame('pending', $intent->status);
        $this->assertSame('Submitted', $task->fresh()->status);
        $fail = false;
        app(RequiredWorkflowNotifications::class)->deliver($intent->id);
        app(RequiredWorkflowNotifications::class)->deliver($intent->id);
        $this->assertSame(1, DB::table('notifications')->where('id', $intent->id)->count());
        $replacement = User::factory()->create(['role_id' => Role::where('name', 'manager')->value('id')]);
        $task = app(TaskLifecycleService::class)->create(['title' => 'Relinquished review duty', 'project_id' => $project->id,
            'assignee_id' => $member->id, 'reviewer_id' => $manager->id, 'priority' => 'High'], $manager);
        $this->actingAs($member)->postTaskTransitionJson("/tasks/{$task->id}/start")->assertOk();
        $fail = true;
        $this->postTaskTransitionJson("/tasks/{$task->id}/submit", ['submission_note' => 'Ready'])->assertOk()->assertJsonPath('notification_status', 'delivery_failed');
        $intent = WorkflowNotificationIntent::where('task_id', $task->id)->where('transition', 'submitted')->firstOrFail();
        $this->actingAs($replacement)->postTaskTransitionJson("/tasks/{$task->id}/reviewer/reassign", ['reviewer_id' => $replacement->id, 'reason' => 'Duty transferred'])->assertOk();
        $fail = false;
        app(RequiredWorkflowNotifications::class)->deliver($intent->id);
        $this->assertSame('discarded', $intent->fresh()->status);
        $this->assertFalse(DB::table('notifications')->where('id', $intent->id)->exists());
    }

    public function test_retry_is_bounded_cleanup_retains_pending_and_rollback_refuses_to_lose_intents(): void
    {
        $fail = true;
        [$manager, $member, $project, $payload, $intent] = $this->failedCreate($fail, 'r52-bounded-retry');
        $fail = false;
        $migration = require database_path('migrations/2026_10_04_000002_create_workflow_notification_intents.php');
        try {
            $migration->down();
            $this->fail('Pending recovery state must survive rollback.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Drain pending', $exception->getMessage());
        }
        $this->artisan('app:deliver-required-workflow-notifications', ['--limit' => 1])->assertSuccessful();
        $this->assertSame('pending', $intent->fresh()->status);
        $intent->update(['available_at' => now()->subMinute()]);
        $this->artisan('app:deliver-required-workflow-notifications', ['--limit' => 1])->assertSuccessful();
        $this->assertSame('delivered', $intent->fresh()->status);
        $intent->update(['finished_at' => now()->subDays(31)]);
        $this->artisan('app:deliver-required-workflow-notifications', ['--limit' => 1])->assertSuccessful();
        $this->assertDatabaseCount('workflow_notification_intents', 0);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_project_manager_replacement_intents_are_atomic_and_recoverable(): void
    {
        [$manager, $member, $project] = $this->fixture();
        $oldPm = User::factory()->create(['role_id' => Role::where('name', 'project_manager')->value('id')]);
        $newPm = User::factory()->create(['role_id' => $oldPm->role_id]);
        $project->update(['project_manager_id' => $oldPm->id]);
        $task = app(TaskLifecycleService::class)->create(['title' => 'Replacement duty', 'project_id' => $project->id,
            'assignee_id' => $member->id, 'reviewer_id' => $oldPm->id, 'priority' => 'High'], $manager);
        $payload = ['name' => $project->name, 'status' => 'active', 'project_manager_id' => $newPm->id];
        try {
            DB::transaction(function () use ($manager, $project, $payload) {
                $this->actingAs($manager)->putJson("/projects/{$project->id}", $payload)->assertOk();
                $this->assertGreaterThan(0, WorkflowNotificationIntent::where('transition', 'reviewer_reassigned')->count());
                throw new RuntimeException('Controlled replacement rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Controlled replacement rollback', $exception->getMessage());
        }
        $this->assertSame($oldPm->id, $task->fresh()->reviewer_id);
        $this->assertSame(0, WorkflowNotificationIntent::where('transition', 'reviewer_reassigned')->count());
        $fail = true;
        Event::listen(NotificationSending::class, function ($event) use (&$fail) {
            if ($fail && $event->notification instanceof TaskReviewWorkflowNotification) {
                throw new RuntimeException('Controlled replacement send failure');
            }
        });
        $this->putJson("/projects/{$project->id}", $payload)->assertOk()->assertJsonPath('notification_status', 'delivery_failed');
        $this->assertSame($newPm->id, $project->fresh()->project_manager_id);
        $this->assertSame($newPm->id, $task->fresh()->reviewer_id);
        $intent = WorkflowNotificationIntent::where('transition', 'reviewer_reassigned')->where('recipient_id', $newPm->id)->firstOrFail();
        $this->assertSame('pending', $intent->status);
        $fail = false;
        app(RequiredWorkflowNotifications::class)->deliver($intent->id);
        app(RequiredWorkflowNotifications::class)->deliver($intent->id);
        $this->assertSame(1, DB::table('notifications')->where('id', $intent->id)->count());
    }
}
