<?php

namespace Tests\Feature;

use App\Contracts\BrowserPushTransportResult;
use App\Jobs\SendBrowserPushNotification;
use App\Models\BrowserPushSubscription;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskUpdatedNotification;
use App\ValueObjects\BrowserPushMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\FakeBrowserPushTransport;
use Tests\TestCase;

class BrowserPushDeliveryTest extends TestCase
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
            'webpush.stale_after_failures' => 3,
        ]);
    }

    public function test_existing_database_notification_is_preserved_and_push_is_queued_after_commit(): void
    {
        Queue::fake();
        $user = $this->user();
        $this->subscription($user, 'one');
        $task = $this->task($user);

        $user->notify(new TaskUpdatedNotification($task, ['title' => 'Updated'], $user));

        $this->assertDatabaseCount('notifications', 1);
        Queue::assertPushed(
            SendBrowserPushNotification::class,
            fn (SendBrowserPushNotification $job): bool => $job->userId === $user->id
                && $job->payload['data']['task_id'] === $task->id
                && $job->payload['data']['task_uid'] === $task->task_uid
        );
    }

    public function test_transaction_rollback_dispatches_no_push_and_leaves_no_database_notification(): void
    {
        Queue::fake();
        $user = $this->user();
        $this->subscription($user, 'one');
        $task = $this->task($user);

        DB::beginTransaction();
        $user->notify(new TaskUpdatedNotification($task, ['title' => 'Rolled back'], $user));
        DB::rollBack();

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_one_delivery_per_enabled_device_and_duplicate_logical_delivery_is_suppressed(): void
    {
        $user = $this->user();
        $first = $this->subscription($user, 'one');
        $second = $this->subscription($user, 'two');
        $this->subscription($user, 'disabled', disabled: true);
        $transport = new FakeBrowserPushTransport;
        $notificationId = (string) Str::uuid();
        $payload = $this->safePayload($notificationId);
        $job = new SendBrowserPushNotification($user->id, $notificationId, $payload);

        $job->handle($transport);
        $job->handle($transport);

        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            array_column($transport->sent, 'subscription_id'),
        );
        $this->assertDatabaseCount('browser_push_deliveries', 2);
        $this->assertDatabaseHas('browser_push_deliveries', [
            'browser_push_subscription_id' => $first->id,
            'notification_id' => $notificationId,
            'status' => 'sent',
        ]);
    }

    public function test_permanent_failures_disable_immediately_and_temporary_failures_use_threshold(): void
    {
        $user = $this->user();
        $permanent = $this->subscription($user, 'permanent');
        $temporary = $this->subscription($user, 'temporary', failureCount: 1);
        $transport = new FakeBrowserPushTransport;
        $transport->results = [
            new BrowserPushTransportResult(false, true, 410, 'http_410'),
            new BrowserPushTransportResult(false, false, 503, 'http_503'),
        ];

        (new SendBrowserPushNotification(
            $user->id,
            (string) Str::uuid(),
            $this->safePayload((string) Str::uuid()),
        ))->handle($transport);

        $this->assertNotNull($permanent->refresh()->disabled_at);
        $this->assertNotNull($permanent->revoked_at);
        $this->assertSame(1, $permanent->failure_count);
        $this->assertNull($temporary->refresh()->disabled_at);
        $this->assertSame(2, $temporary->failure_count);
    }

    public function test_push_payload_is_plain_minimal_and_restricts_target_to_a_same_origin_path(): void
    {
        $message = new BrowserPushMessage(
            title: '<b>Task title</b>',
            body: '<img src=x onerror=alert(1)>'.str_repeat('x', 300),
            type: 'task_updated',
            targetPath: 'https://attacker.example/steal',
            taskId: 42,
            taskUid: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        );
        $payload = $message->payload((string) Str::uuid());
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertSame('Task title', $payload['title']);
        $this->assertStringNotContainsString('<', $payload['body']);
        $this->assertLessThanOrEqual(180, mb_strlen($payload['body']));
        $this->assertSame('/notifications/all', $payload['data']['target']);
        $this->assertStringNotContainsString('description', $encoded);
        $this->assertStringNotContainsString('feedback', $encoded);
        $this->assertStringNotContainsString('reason', $encoded);
        $this->assertStringNotContainsString('endpoint', $encoded);
    }

    private function user(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('name', 'team_member')->value('id'),
        ]);
    }

    private function task(User $user): Task
    {
        return Task::query()->create([
            'title' => 'Push delivery task',
            'description' => 'Detailed description must not enter push payloads.',
            'status' => 'not_started',
            'priority' => 'Medium',
            'progress' => 0,
            'assignee_id' => $user->id,
            'assigned_by' => $user->id,
            'created_by' => $user->id,
            'start_date' => now()->toDateString(),
            'due_date' => now()->addDay()->toDateString(),
        ]);
    }

    private function subscription(
        User $user,
        string $suffix,
        bool $disabled = false,
        int $failureCount = 0,
    ): BrowserPushSubscription {
        $endpoint = "https://push.example.test/subscription/{$suffix}";

        $subscription = new BrowserPushSubscription;
        $subscription->forceFill([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => BrowserPushSubscription::endpointHash($endpoint),
            'public_key' => 'public-key-'.$suffix,
            'auth_secret' => 'auth-secret-'.$suffix,
            'content_encoding' => 'aes128gcm',
            'device_label' => $suffix,
            'failure_count' => $failureCount,
            'disabled_at' => $disabled ? now() : null,
        ])->save();

        return $subscription;
    }

    /**
     * @return array<string, mixed>
     */
    private function safePayload(string $notificationId): array
    {
        return [
            'title' => 'Task updated',
            'body' => 'A task has an update.',
            'data' => [
                'notification_id' => $notificationId,
                'target' => '/tasks#task-1',
            ],
        ];
    }
}
