<?php

namespace App\ValueObjects;

use App\Models\User;

final readonly class TaskDeadlineOwnership
{
    public function __construct(
        public ?TaskDeadlineGeneration $generation,
        public ?User $owner,
        public ?string $suppressionReason = null,
    ) {}

    public function isDeliverable(): bool
    {
        return $this->generation !== null
            && $this->owner !== null
            && $this->suppressionReason === null;
    }
}
