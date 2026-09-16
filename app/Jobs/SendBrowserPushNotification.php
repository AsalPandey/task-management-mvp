<?php

namespace App\Jobs;

use App\Contracts\BrowserPushTransport;
use App\Models\BrowserPushDelivery;
use App\Models\BrowserPushSubscription;
use App\Models\User;
use App\Services\NotificationAccess;
use App\Services\WebPushDestinationValidator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendBrowserPushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $notificationId,
        public readonly array $payload,
        public readonly ?int $subscriptionId = null,
    ) {
        $this->onQueue(config('webpush.queue'));
    }

    public function handle(BrowserPushTransport $transport): void
    {
        if (! app(NotificationAccess::class)->allows(
            User::find($this->userId), $this->payload['data'] ?? [],
        )) {
            return;
        }

        $subscriptions = BrowserPushSubscription::query()
            ->enabled()
            ->where('user_id', $this->userId)
            ->when($this->subscriptionId, fn ($query) => $query->whereKey($this->subscriptionId))
            ->whereHas('user', fn ($query) => $query->where('active', true))
            ->get();

        foreach ($subscriptions as $subscription) {
            $delivery = BrowserPushDelivery::query()->firstOrCreate([
                'browser_push_subscription_id' => $subscription->id,
                'notification_id' => $this->notificationId,
            ]);

            if (! $delivery->wasRecentlyCreated || $delivery->status === 'sent') {
                continue;
            }

            if (! app(WebPushDestinationValidator::class)->validateUrl($subscription->endpoint)) {
                $delivery->update([
                    'status' => 'failed',
                    'failure_code' => 'unsafe_destination',
                ]);
                $subscription->forceFill([
                    'last_failure_at' => now(),
                    'disabled_at' => now(),
                    'revoked_at' => now(),
                ])->save();

                Log::warning('Revoked browser push subscription with unsafe destination.', [
                    'subscription_id' => $subscription->id,
                    'notification_id' => $this->notificationId,
                    'endpoint' => $subscription->endpoint,
                ]);

                continue;
            }

            $result = $transport->send($subscription, $this->payload);
            if ($result->successful) {
                $delivery->update(['status' => 'sent', 'failure_code' => null]);
                $subscription->forceFill([
                    'last_successful_delivery_at' => now(),
                    'last_failure_at' => null,
                    'failure_count' => 0,
                ])->save();

                continue;
            }

            $failures = $subscription->failure_count + 1;
            $disable = $result->permanentFailure
                || $failures >= max(1, (int) config('webpush.stale_after_failures'));

            $delivery->update([
                'status' => $result->permanentFailure ? 'expired' : 'failed',
                'failure_code' => $result->failureCode,
            ]);
            $subscription->forceFill([
                'last_failure_at' => now(),
                'failure_count' => $failures,
                'disabled_at' => $disable ? now() : null,
                'revoked_at' => $result->permanentFailure ? now() : null,
            ])->save();

            Log::warning('Browser push delivery failed.', [
                'subscription_id' => $subscription->id,
                'notification_id' => $this->notificationId,
                'status_code' => $result->statusCode,
                'failure_code' => $result->failureCode,
                'permanent' => $result->permanentFailure,
            ]);
        }
    }
}
