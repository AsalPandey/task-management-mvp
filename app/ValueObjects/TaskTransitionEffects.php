<?php

namespace App\ValueObjects;

use Closure;

final readonly class TaskTransitionEffects
{
    /**
     * @param  array<string, mixed>  $historyChanges
     * @param  array<int, array{type: string, changed_fields: array<string, mixed>, metadata?: array<string, mixed>|null}>  $events
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public ?string $historyAction = null,
        public array $historyChanges = [],
        public array $events = [],
        public ?Closure $afterCommit = null,
        public array $metadata = [],
    ) {}
}
