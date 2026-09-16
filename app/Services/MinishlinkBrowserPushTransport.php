<?php

namespace App\Services;

use App\Contracts\BrowserPushTransport;
use App\Contracts\BrowserPushTransportResult;
use App\Models\BrowserPushSubscription;
use GuzzleHttp\Client;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

class MinishlinkBrowserPushTransport implements BrowserPushTransport
{
    public function send(BrowserPushSubscription $subscription, array $payload): BrowserPushTransportResult
    {
        $destination = app(WebPushDestinationValidator::class)->resolveSafeDestination($subscription->endpoint);
        if ($destination === null) {
            return new BrowserPushTransportResult(
                false,
                permanentFailure: true,
                failureCode: 'unsafe_destination',
            );
        }

        $formattedIps = [];
        foreach ($destination['ips'] as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $formattedIps[] = str_starts_with($ip, '[') && str_ends_with($ip, ']') ? $ip : "[{$ip}]";
            } else {
                $formattedIps[] = $ip;
            }
        }

        $resolveEntries = [];
        if ($formattedIps !== []) {
            $resolveEntries[] = "{$destination['host']}:{$destination['port']}:".implode(',', $formattedIps);
        }

        $clientOptions = [
            'allow_redirects' => false,
            'timeout' => 10,
            'connect_timeout' => 5,
        ];

        if (defined('CURLOPT_RESOLVE') && $resolveEntries !== []) {
            $clientOptions['curl'] = [
                CURLOPT_RESOLVE => $resolveEntries,
            ];
        }

        $client = $this->createHttpClient($clientOptions);

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => config('webpush.vapid.subject'),
                'publicKey' => config('webpush.vapid.public_key'),
                'privateKey' => config('webpush.vapid.private_key'),
            ],
        ], [
            'TTL' => config('webpush.ttl'),
            'urgency' => 'normal',
        ], $client);
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

    protected function createHttpClient(array $options): Client
    {
        return new Client($options);
    }
}
