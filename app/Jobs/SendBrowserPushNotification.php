<?php

namespace App\Jobs;

use App\Contracts\BrowserPushTransport;
use App\Models\BrowserPushDelivery;
use App\Models\BrowserPushSubscription;
use App\Models\User;
use App\Notifications\Channels\BrowserPushChannel;
use App\Services\NotificationAccess;
use App\Services\NotificationPreferencePolicy;
use App\Services\WebPushDestinationValidator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendBrowserPushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

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

    public function handle(
        BrowserPushTransport $transport,
        ?NotificationPreferencePolicy $preferences = null,
    ): void {
        $preferences ??= app(NotificationPreferencePolicy::class);
        $user = User::find($this->userId);
        if (! app(NotificationAccess::class)->allows($user, $this->payload['data'] ?? [])
            || ! $user
            || ! $preferences->decideForType(
                $user,
                (string) data_get($this->payload, 'data.type', ''),
                BrowserPushChannel::class,
            )->allowed) {
            return;
        }

        $subscriptions = BrowserPushSubscription::query()
            ->enabled()
            ->where('user_id', $this->userId)
            ->when($this->subscriptionId, fn ($query) => $query->whereKey($this->subscriptionId))
            ->whereHas('user', fn ($query) => $query->where('active', true))
            ->get();

        $temporaryFailure = false;
        foreach ($subscriptions as $subscription) {
            $delivery = $this->claimDelivery($subscription);
            if (! $delivery) {
                continue;
            }

            if (! app(WebPushDestinationValidator::class)->validateUrl($subscription->endpoint)) {
                $delivery->update([
                    'status' => 'failed',
                    'failure_code' => 'unsafe_destination',
                    'lease_expires_at' => null,
                ]);
                $subscription->forceFill([
                    'last_failure_at' => now(),
                    'disabled_at' => now(),
                    'revoked_at' => now(),
                ])->save();

                Log::warning('Revoked browser push subscription with unsafe destination.', [
                    'subscription_id' => $subscription->id,
                    'notification_id' => $this->notificationId,
                ]);

                continue;
            }

            $result = $transport->send($subscription, $this->payload);
            if ($result->successful) {
                $delivery->update([
                    'status' => 'sent',
                    'failure_code' => null,
                    'lease_expires_at' => null,
                    'next_attempt_at' => null,
                    'delivered_at' => now(),
                ]);
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
                'lease_expires_at' => null,
                'next_attempt_at' => $result->permanentFailure ? null : now()->addSeconds(60),
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

            $temporaryFailure = $temporaryFailure || ! $result->permanentFailure;
        }

        if ($temporaryFailure && $this->job !== null) {
            $this->release(60);
        }
    }

    private function claimDelivery(BrowserPushSubscription $subscription): ?BrowserPushDelivery
    {
        return DB::transaction(function () use ($subscription): ?BrowserPushDelivery {
            BrowserPushSubscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            BrowserPushDelivery::query()->firstOrCreate([
                'browser_push_subscription_id' => $subscription->id,
                'notification_id' => $this->notificationId,
            ]);

            $delivery = BrowserPushDelivery::query()
                ->where('browser_push_subscription_id', $subscription->id)
                ->where('notification_id', $this->notificationId)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($delivery->status, ['sent', 'expired'], true)
                || ($delivery->status === 'processing' && $delivery->lease_expires_at?->isFuture())
                || ($delivery->status === 'failed' && $delivery->next_attempt_at?->isFuture())) {
                return null;
            }

            $delivery->forceFill([
                'status' => 'processing',
                'attempt_count' => $delivery->attempt_count + 1,
                'claimed_at' => now(),
                'lease_expires_at' => now()->addMinutes(5),
                'next_attempt_at' => null,
            ])->save();

            return $delivery;
        }, 3);
    }
}
