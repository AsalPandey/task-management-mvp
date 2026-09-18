<?php

namespace Tests\Support;

use App\Contracts\BrowserPushTransport;
use App\Contracts\BrowserPushTransportResult;
use App\Models\BrowserPushSubscription;

final class BarrierBrowserPushTransport implements BrowserPushTransport
{
    public function __construct(
        private readonly string $logFile,
        private readonly string $readyFile = '',
        private readonly string $releaseFile = '',
        private readonly bool $temporaryFailure = false,
    ) {}

    public function send(BrowserPushSubscription $subscription, array $payload): BrowserPushTransportResult
    {
        file_put_contents($this->logFile, $subscription->id.PHP_EOL, FILE_APPEND | LOCK_EX);
        if ($this->readyFile !== '') {
            file_put_contents($this->readyFile, 'transport-entered');
        }
        if ($this->releaseFile !== '') {
            $deadline = microtime(true) + 15;
            while (! file_exists($this->releaseFile) && microtime(true) < $deadline) {
                usleep(10_000);
            }
        }

        return $this->temporaryFailure
            ? new BrowserPushTransportResult(false, false, 503, 'http_503')
            : new BrowserPushTransportResult(true);
    }
}
