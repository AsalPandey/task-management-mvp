<?php

namespace App\Services;

use App\Contracts\BrowserPushTransport;
use App\Contracts\BrowserPushTransportResult;
use App\Models\BrowserPushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

class MinishlinkBrowserPushTransport implements BrowserPushTransport
{
    public function send(BrowserPushSubscription $subscription, array $payload): BrowserPushTransportResult
    {
        $webPush = new WebPush([
            'VAPID' => [
                'subject' => config('webpush.vapid.subject'),
                'publicKey' => config('webpush.vapid.public_key'),
                'privateKey' => config('webpush.vapid.private_key'),
            ],
        ], [
            'TTL' => config('webpush.ttl'),
            'urgency' => 'normal',
        ]);
        $webPush->setReuseVAPIDHeaders(true);

        try {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'keys' => [
                        'p256dh' => $subscription->public_key,
                        'auth' => $subscription->auth_secret,
                    ],
                    'contentEncoding' => $subscription->content_encoding,
                ]),
                json_encode($payload, JSON_THROW_ON_ERROR),
            );

            $report = null;
            foreach ($webPush->flush() as $candidate) {
                $report = $candidate;
                break;
            }

            if (! $report) {
                return new BrowserPushTransportResult(false, failureCode: 'missing_report');
            }

            $status = $report->getResponse()?->getStatusCode();

            return new BrowserPushTransportResult(
                successful: $report->isSuccess(),
                permanentFailure: $report->isSubscriptionExpired(),
                statusCode: $status,
                failureCode: $report->isSuccess() ? null : 'http_'.($status ?: 'transport'),
            );
        } catch (Throwable $exception) {
            return new BrowserPushTransportResult(
                false,
                failureCode: 'transport_'.class_basename($exception),
            );
        }
    }
}
