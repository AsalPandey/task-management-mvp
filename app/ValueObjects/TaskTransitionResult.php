<?php

namespace App\ValueObjects;

use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\TaskHistory;

final readonly class TaskTransitionResult
{
    /**
     * @param  array<int, TaskEvent>  $events
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public Task $task,
        public ?TaskHistory $history,
        public array $events,
        public array $metadata = [],
    ) {}
}
