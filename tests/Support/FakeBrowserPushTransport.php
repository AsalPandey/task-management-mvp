<?php

namespace Tests\Support;

use App\Contracts\BrowserPushTransport;
use App\Contracts\BrowserPushTransportResult;
use App\Models\BrowserPushSubscription;

class FakeBrowserPushTransport implements BrowserPushTransport
{
    /** @var array<int, array{subscription_id: int, payload: array<string, mixed>}> */
    public array $sent = [];

    /** @var list<BrowserPushTransportResult> */
    public array $results = [];

    public function send(BrowserPushSubscription $subscription, array $payload): BrowserPushTransportResult
    {
        $this->sent[] = [
            'subscription_id' => $subscription->id,
            'payload' => $payload,
        ];

        return array_shift($this->results) ?? new BrowserPushTransportResult(true);
    }
}
