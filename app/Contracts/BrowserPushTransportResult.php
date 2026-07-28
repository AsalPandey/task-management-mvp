<?php

namespace App\Contracts;

final readonly class BrowserPushTransportResult
{
    public function __construct(
        public bool $successful,
        public bool $permanentFailure = false,
        public ?int $statusCode = null,
        public ?string $failureCode = null,
    ) {}
}
