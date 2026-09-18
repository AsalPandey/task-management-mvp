<?php

namespace App\ValueObjects;

use App\Enums\NotificationCategory;

final readonly class NotificationDeliveryDecision
{
    public function __construct(
        public NotificationCategory $category,
        public ?string $preferenceKey,
        public bool $allowed,
    ) {}
}
