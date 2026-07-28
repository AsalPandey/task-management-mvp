<?php

namespace Tests\Feature;

use App\Jobs\SendBrowserPushNotification;
use App\Models\BrowserPushSubscription;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BrowserPushSubscriptionSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://push.example.test/subscription/device-one';

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

    public function test_subscription_routes_require_authentication_and_csrf_protection(): void
    {
        $this->getJson(route('push.status'))->assertUnauthorized();
        $this->postJson(route('push.subscriptions.store'), $this->subscriptionPayload())
            ->assertUnauthorized();

        $middleware = app('router')->getMiddlewareGroups()['web'];

        $this->assertContains(ValidateCsrfToken::class, $middleware);
    }

    public function test_registration_is_idempotent_and_status_never_exposes_private_configuration(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson(route('push.subscriptions.store'), $this->subscriptionPayload())
            ->assertCreated()
            ->assertJson([
                'enabled' => true,
                'created' => true,
                'enabled_devices' => 1,
            ])
            ->assertJsonMissing(['endpoint', 'public_key', 'private_key', 'auth_secret']);

        $this->actingAs($user)
            ->postJson(route('push.subscriptions.store'), $this->subscriptionPayload('Updated device'))
            ->assertOk()
            ->assertJson([
                'enabled' => true,
                'created' => false,
                'enabled_devices' => 1,
            ]);

        $this->assertDatabaseCount('browser_push_subscriptions', 1);
        $this->assertDatabaseHas('browser_push_subscriptions', [
            'user_id' => $user->id,
            'endpoint_hash' => BrowserPushSubscription::endpointHash(self::ENDPOINT),
            'device_label' => 'Updated device',
        ]);

        $this->actingAs($user)
            ->getJson(route('push.status'))
            ->assertOk()
            ->assertJson([
                'configured' => true,
                'public_key' => 'public-vapid-key',
                'enabled_devices' => 1,
            ])
            ->assertJsonMissing(['private_key', 'auth_secret', 'endpoint']);
    }

    public function test_duplicate_endpoint_cannot_be_claimed_and_unsubscribe_is_user_scoped(): void
    {
        $owner = $this->user();
        $other = $this->user();

        $this->actingAs($owner)
            ->postJson(route('push.subscriptions.store'), $this->subscriptionPayload())
            ->assertCreated();

        $this->actingAs($other)
            ->postJson(route('push.subscriptions.store'), $this->subscriptionPayload('Other account'))
            ->assertConflict();

        $this->actingAs($other)
            ->deleteJson(route('push.subscriptions.destroy'), ['endpoint' => self::ENDPOINT])
            ->assertOk()
            ->assertJson(['disabled' => false]);

        $this->assertDatabaseHas('browser_push_subscriptions', [
            'user_id' => $owner->id,
            'endpoint_hash' => BrowserPushSubscription::endpointHash(self::ENDPOINT),
            'disabled_at' => null,
        ]);

        $this->actingAs($owner)
            ->deleteJson(route('push.subscriptions.destroy'), ['endpoint' => self::ENDPOINT])
            ->assertOk()
            ->assertJson(['disabled' => true]);

        $this->assertNotNull(BrowserPushSubscription::query()->firstOrFail()->disabled_at);
    }

    public function test_subscription_secrets_are_hidden_and_deactivation_disables_devices(): void
    {
        $user = $this->user();
        $this->actingAs($user)
            ->postJson(route('push.subscriptions.store'), $this->subscriptionPayload())
            ->assertCreated();

        $subscription = BrowserPushSubscription::query()->firstOrFail();
        $serialized = $subscription->toArray();

        $this->assertArrayNotHasKey('endpoint', $serialized);
        $this->assertArrayNotHasKey('endpoint_hash', $serialized);
        $this->assertArrayNotHasKey('public_key', $serialized);
        $this->assertArrayNotHasKey('auth_secret', $serialized);

        $user->update(['active' => false]);
        $subscription->refresh();

        $this->assertNotNull($subscription->disabled_at);
        $this->assertNotNull($subscription->revoked_at);
    }

    public function test_self_test_is_scoped_to_the_requesting_users_current_subscription(): void
    {
        Queue::fake();
        $owner = $this->user();
        $other = $this->user();

        $this->actingAs($owner)
            ->postJson(route('push.subscriptions.store'), $this->subscriptionPayload())
            ->assertCreated();

        $this->actingAs($other)
            ->postJson(route('push.test'), [
                'endpoint' => self::ENDPOINT,
                'title' => 'Crafted title',
                'target' => 'https://attacker.example/',
            ])
            ->assertNotFound();
        Queue::assertNothingPushed();

        $this->actingAs($owner)
            ->postJson(route('push.test'), [
                'endpoint' => self::ENDPOINT,
                'title' => 'Crafted title',
                'target' => 'https://attacker.example/',
            ])
            ->assertOk()
            ->assertJson(['queued' => true]);

        Queue::assertPushed(
            SendBrowserPushNotification::class,
            fn (SendBrowserPushNotification $job): bool => $job->userId === $owner->id
                && $job->subscriptionId === BrowserPushSubscription::query()->value('id')
                && $job->payload['title'] === 'Task Management notifications are enabled'
                && $job->payload['data']['target'] === '/notifications/all'
        );
    }

    private function user(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('name', 'team_member')->value('id'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionPayload(string $deviceLabel = 'Test browser'): array
    {
        return [
            'endpoint' => self::ENDPOINT,
            'keys' => [
                'p256dh' => 'BOr0-valid_public-key_0123456789_abcdefghijklmnopqrstuvwxyz',
                'auth' => 'valid_auth-secret_012345',
            ],
            'content_encoding' => 'aes128gcm',
            'device_label' => $deviceLabel,
        ];
    }
}
