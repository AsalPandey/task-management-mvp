<?php

namespace App\Contracts;

use App\Models\BrowserPushSubscription;

interface BrowserPushTransport
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(BrowserPushSubscription $subscription, array $payload): BrowserPushTransportResult;
}
