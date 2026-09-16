<?php

namespace Tests\Feature;

use App\Jobs\SendBrowserPushNotification;
use App\Models\BrowserPushSubscription;
use App\Models\Role;
use App\Models\User;
use App\Services\MinishlinkBrowserPushTransport;
use App\Services\WebPushDestinationValidator;
use GuzzleHttp\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeBrowserPushTransport;
use Tests\TestCase;

class WebPushDestinationSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config()->set([
            'webpush.vapid.subject' => 'mailto:push@example.test',
            'webpush.vapid.public_key' => 'BEFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUE',
            'webpush.vapid.private_key' => 'QkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkJCQkI',
            'webpush.allowed_test_hosts' => ['push.example.test', 'valid.push-service.example.test'],
        ]);
    }

    public function test_valid_https_endpoint_is_accepted(): void
    {
        $user = $this->user();
        $response = $this->actingAs($user)->postJson(route('push.subscriptions.store'), [
            'endpoint' => 'https://push.example.test/subscription/device-safe',
            'keys' => [
                'p256dh' => 'BOr0-valid_public-key_0123456789_abcdefghijklmnopqrstuvwxyz',
                'auth' => 'valid_auth-secret_012345',
            ],
            'content_encoding' => 'aes128gcm',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('browser_push_subscriptions', [
            'endpoint' => 'https://push.example.test/subscription/device-safe',
        ]);
    }

    #[DataProvider('dangerousEndpointsProvider')]
    public function test_unsafe_endpoints_are_rejected_at_registration(string $dangerousEndpoint): void
    {
        $user = $this->user();
        $response = $this->actingAs($user)->postJson(route('push.subscriptions.store'), [
            'endpoint' => $dangerousEndpoint,
            'keys' => [
                'p256dh' => 'BOr0-valid_public-key_0123456789_abcdefghijklmnopqrstuvwxyz',
                'auth' => 'valid_auth-secret_012345',
            ],
            'content_encoding' => 'aes128gcm',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['endpoint']);
        $this->assertDatabaseMissing('browser_push_subscriptions', [
            'endpoint' => $dangerousEndpoint,
        ]);
    }

    public static function dangerousEndpointsProvider(): array
    {
        return [
            'localhost' => ['https://localhost/push'],
            'localhost sub' => ['https://sub.localhost/push'],
            '127.0.0.1' => ['https://127.0.0.1/internal'],
            '127.0.0.1 with port' => ['https://127.0.0.1:8443/internal'],
            'IPv6 loopback' => ['https://[::1]/internal'],
            'private 10.x' => ['https://10.0.0.1/push'],
            'private 192.168.x' => ['https://192.168.1.1/push'],
            'private 172.16.x' => ['https://172.16.0.1/push'],
            'link-local 169.254' => ['https://169.254.169.254/latest/meta-data'],
            'carrier-grade NAT' => ['https://100.64.0.1/push'],
            'multicast' => ['https://224.0.0.1/push'],
            'broadcast' => ['https://255.255.255.255/push'],
            'zero network' => ['https://0.0.0.0/push'],
            'internal suffix' => ['https://service.internal/push'],
            'local suffix' => ['https://printer.local/push'],
            'credentials in URL' => ['https://user:password@push.example.test/push'],
            'unusual port 8080' => ['https://push.example.test:8080/push'],
            'unusual port 22' => ['https://push.example.test:22/push'],
            'plain http' => ['http://push.example.test/push'],
            'file scheme' => ['file:///etc/passwd'],
            'gopher scheme' => ['gopher://127.0.0.1:70/'],
            'malformed url' => ['not-a-valid-url'],
            'IPv4-mapped IPv6' => ['https://[::ffff:127.0.0.1]/push'],
            'IPv4-mapped private' => ['https://[::ffff:10.0.0.1]/push'],
        ];
    }

    public function test_validator_detects_unsafe_ip_ranges(): void
    {
        $validator = app(WebPushDestinationValidator::class);

        $this->assertFalse($validator->isSafeIp('127.0.0.1'));
        $this->assertFalse($validator->isSafeIp('10.0.0.1'));
        $this->assertFalse($validator->isSafeIp('192.168.1.1'));
        $this->assertFalse($validator->isSafeIp('172.16.0.1'));
        $this->assertFalse($validator->isSafeIp('169.254.169.254'));
        $this->assertFalse($validator->isSafeIp('0.0.0.0'));
        $this->assertFalse($validator->isSafeIp('100.64.0.1'));
        $this->assertFalse($validator->isSafeIp('224.0.0.1'));
        $this->assertFalse($validator->isSafeIp('255.255.255.255'));
        $this->assertFalse($validator->isSafeIp('::1'));
        $this->assertFalse($validator->isSafeIp('fe80::1'));
        $this->assertFalse($validator->isSafeIp('fc00::1'));
        $this->assertFalse($validator->isSafeIp('::ffff:127.0.0.1'));
        $this->assertFalse($validator->isSafeIp('::ffff:10.0.0.1'));

        $this->assertTrue($validator->isSafeIp('93.184.216.34'));
        $this->assertTrue($validator->isSafeIp('142.250.190.46'));
    }

    public function test_existing_unsafe_subscription_is_safely_revoked_on_delivery_without_http_request(): void
    {
        $user = $this->user();
        $unsafeSubscription = new BrowserPushSubscription;
        $unsafeSubscription->forceFill([
            'user_id' => $user->id,
            'endpoint' => 'https://127.0.0.1/internal',
            'endpoint_hash' => BrowserPushSubscription::endpointHash('https://127.0.0.1/internal'),
            'public_key' => 'BOr0-valid_public-key_0123456789_abcdefghijklmnopqrstuvwxyz',
            'auth_secret' => 'valid_auth-secret_012345',
            'content_encoding' => 'aes128gcm',
        ])->save();

        $transport = new FakeBrowserPushTransport;
        $notificationId = (string) Str::uuid();
        $payload = ['title' => 'Test Notification', 'body' => 'Body'];

        $job = new SendBrowserPushNotification($user->id, $notificationId, $payload);
        $job->handle($transport);

        // Verify transport was NOT called
        $this->assertEmpty($transport->sent);

        // Verify delivery recorded failure
        $this->assertDatabaseHas('browser_push_deliveries', [
            'browser_push_subscription_id' => $unsafeSubscription->id,
            'status' => 'failed',
            'failure_code' => 'unsafe_destination',
        ]);

        // Verify subscription is disabled and revoked
        $unsafeSubscription->refresh();
        $this->assertNotNull($unsafeSubscription->disabled_at);
        $this->assertNotNull($unsafeSubscription->revoked_at);
    }

    public function test_dns_rebinding_public_at_validation_private_at_connection_fails_closed_ipv4(): void
    {
        $validator = app(WebPushDestinationValidator::class);
        $endpoint = 'https://push.example.test/rebind-v4';

        // Phase 1: DNS resolves to public IP during validation
        $currentIp = '93.184.216.34';
        $validator->setDnsResolver(function (string $host) use (&$currentIp) {
            return [$currentIp];
        });

        $this->assertTrue($validator->validateUrl($endpoint));

        $user = $this->user();
        $subscription = new BrowserPushSubscription;
        $subscription->forceFill([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => BrowserPushSubscription::endpointHash($endpoint),
            'public_key' => 'BOr0-valid_public-key_0123456789_abcdefghijklmnopqrstuvwxyz',
            'auth_secret' => 'valid_auth-secret_012345',
            'content_encoding' => 'aes128gcm',
        ])->save();

        // Phase 2: Attacker changes DNS answer to loopback before outbound HTTP connection
        $currentIp = '127.0.0.1';

        $transport = new MinishlinkBrowserPushTransport;
        $result = $transport->send($subscription, ['title' => 'SSRF Test']);

        $this->assertFalse($result->successful);
        $this->assertSame('unsafe_destination', $result->failureCode);
        $this->assertTrue($result->permanentFailure);

        $validator->setDnsResolver(null);
    }

    public function test_dns_rebinding_public_at_validation_private_at_connection_fails_closed_ipv6(): void
    {
        $validator = app(WebPushDestinationValidator::class);
        $endpoint = 'https://push.example.test/rebind-v6';

        // Phase 1: Valid public IP at registration
        $currentIp = '93.184.216.34';
        $validator->setDnsResolver(function (string $host) use (&$currentIp) {
            return [$currentIp];
        });

        $this->assertTrue($validator->validateUrl($endpoint));

        $user = $this->user();
        $subscription = new BrowserPushSubscription;
        $subscription->forceFill([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => BrowserPushSubscription::endpointHash($endpoint),
            'public_key' => 'BOr0-valid_public-key_0123456789_abcdefghijklmnopqrstuvwxyz',
            'auth_secret' => 'valid_auth-secret_012345',
            'content_encoding' => 'aes128gcm',
        ])->save();

        // Phase 2: Attacker changes DNS answer to IPv6 loopback (::1) and IPv4-mapped loopback
        foreach (['::1', '::ffff:127.0.0.1', 'fe80::1', 'fc00::1'] as $unsafeReboundIp) {
            $currentIp = $unsafeReboundIp;
            $transport = new MinishlinkBrowserPushTransport;
            $result = $transport->send($subscription, ['title' => 'SSRF IPv6 Test']);

            $this->assertFalse($result->successful);
            $this->assertSame('unsafe_destination', $result->failureCode);
            $this->assertTrue($result->permanentFailure);
        }

        $validator->setDnsResolver(null);
    }

    public function test_minishlink_transport_pins_validated_ip_in_curl_resolve_options(): void
    {
        $validator = app(WebPushDestinationValidator::class);
        $endpoint = 'https://push.example.test/pinned-delivery';

        $validator->setDnsResolver(fn () => ['93.184.216.34']);

        $user = $this->user();
        $subscription = new BrowserPushSubscription;
        $subscription->forceFill([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => BrowserPushSubscription::endpointHash($endpoint),
            'public_key' => 'BOr0-valid_public-key_0123456789_abcdefghijklmnopqrstuvwxyz',
            'auth_secret' => 'valid_auth-secret_012345',
            'content_encoding' => 'aes128gcm',
        ])->save();

        $capturedClientOptions = null;
        $inspectableTransport = new class($capturedClientOptions) extends MinishlinkBrowserPushTransport
        {
            public function __construct(public &$capturedOptions) {}

            protected function createHttpClient(array $options): Client
            {
                $this->capturedOptions = $options;

                return new Client($options);
            }
        };

        // Attempt send: will fail at mock/network stage or return result, but will have constructed client
        $inspectableTransport->send($subscription, ['title' => 'Test']);

        $this->assertNotNull($capturedClientOptions);
        $this->assertFalse($capturedClientOptions['allow_redirects']);
        $this->assertArrayHasKey('curl', $capturedClientOptions);
        $this->assertArrayHasKey(CURLOPT_RESOLVE, $capturedClientOptions['curl']);
        $this->assertContains('push.example.test:443:93.184.216.34', $capturedClientOptions['curl'][CURLOPT_RESOLVE]);
        // TLS verification MUST NOT be disabled
        $this->assertNotFalse($capturedClientOptions['verify'] ?? true);

        $validator->setDnsResolver(null);
    }

    public function test_minishlink_transport_pins_validated_ipv6_with_bracket_formatting_in_curl_resolve_options(): void
    {
        $validator = app(WebPushDestinationValidator::class);
        $endpoint = 'https://ipv6.example.test/push';

        // Legitimate public IPv6 address
        $validator->setDnsResolver(fn () => ['2606:2800:220:1:248:1893:25c8:1946']);

        $user = $this->user();
        $subscription = new BrowserPushSubscription;
        $subscription->forceFill([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => BrowserPushSubscription::endpointHash($endpoint),
            'public_key' => 'BOr0-valid_public-key_0123456789_abcdefghijklmnopqrstuvwxyz',
            'auth_secret' => 'valid_auth-secret_012345',
            'content_encoding' => 'aes128gcm',
        ])->save();

        $capturedClientOptions = null;
        $inspectableTransport = new class($capturedClientOptions) extends MinishlinkBrowserPushTransport
        {
            public function __construct(public &$capturedOptions) {}

            protected function createHttpClient(array $options): Client
            {
                $this->capturedOptions = $options;

                return new Client($options);
            }
        };

        $inspectableTransport->send($subscription, ['title' => 'Test']);

        $this->assertNotNull($capturedClientOptions);
        $this->assertFalse($capturedClientOptions['allow_redirects']);
        $this->assertArrayHasKey('curl', $capturedClientOptions);
        $this->assertArrayHasKey(CURLOPT_RESOLVE, $capturedClientOptions['curl']);
        // Numerical IPv6 MUST be enclosed in brackets according to libcurl specification
        $this->assertContains('ipv6.example.test:443:[2606:2800:220:1:248:1893:25c8:1946]', $capturedClientOptions['curl'][CURLOPT_RESOLVE]);
        // TLS/SNI/Host verification MUST NOT be disabled
        $this->assertNotFalse($capturedClientOptions['verify'] ?? true);

        $validator->setDnsResolver(null);
    }

    public function test_minishlink_transport_pins_multiple_dual_stack_ips_in_single_curl_resolve_entry(): void
    {
        $validator = app(WebPushDestinationValidator::class);
        $endpoint = 'https://dualstack.example.test/push';

        // Dual-stack host resolving to both public IPv4 and public IPv6
        $validator->setDnsResolver(fn () => [
            '93.184.216.34',
            '2606:2800:220:1:248:1893:25c8:1946',
        ]);

        $user = $this->user();
        $subscription = new BrowserPushSubscription;
        $subscription->forceFill([
            'user_id' => $user->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => BrowserPushSubscription::endpointHash($endpoint),
            'public_key' => 'BOr0-valid_public-key_0123456789_abcdefghijklmnopqrstuvwxyz',
            'auth_secret' => 'valid_auth-secret_012345',
            'content_encoding' => 'aes128gcm',
        ])->save();

        $capturedClientOptions = null;
        $inspectableTransport = new class($capturedClientOptions) extends MinishlinkBrowserPushTransport
        {
            public function __construct(public &$capturedOptions) {}

            protected function createHttpClient(array $options): Client
            {
                $this->capturedOptions = $options;

                return new Client($options);
            }
        };

        $inspectableTransport->send($subscription, ['title' => 'Test']);

        $this->assertNotNull($capturedClientOptions);
        $this->assertFalse($capturedClientOptions['allow_redirects']);
        $this->assertArrayHasKey('curl', $capturedClientOptions);
        $this->assertArrayHasKey(CURLOPT_RESOLVE, $capturedClientOptions['curl']);
        // Joined into a single libcurl-supported comma-separated entry
        $this->assertSame(
            ['dualstack.example.test:443:93.184.216.34,[2606:2800:220:1:248:1893:25c8:1946]'],
            $capturedClientOptions['curl'][CURLOPT_RESOLVE],
        );
        $this->assertNotFalse($capturedClientOptions['verify'] ?? true);

        $validator->setDnsResolver(null);
    }

    private function user(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('name', 'team_member')->value('id'),
        ]);
    }
}
