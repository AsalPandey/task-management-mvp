<?php

namespace App\ValueObjects;

final readonly class TaskNotificationDeliveryResult
{
    public function __construct(
        public string $status,
        public ?string $reason = null,
        public ?int $deliveryId = null,
    ) {}

    public function delivered(): bool
    {
        return $this->status === 'delivered';
    }
}
