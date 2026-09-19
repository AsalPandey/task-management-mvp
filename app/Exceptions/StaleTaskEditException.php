<?php

namespace App\Exceptions;

use App\Models\Task;
use RuntimeException;

class StaleTaskEditException extends RuntimeException
{
    public function __construct(
        public readonly Task $task,
        public readonly int $expectedVersion,
        public readonly int $currentVersion,
        string $message = 'This task changed after you opened it. Your update was not saved. Review the latest version and try again.',
    ) {
        parent::__construct($message, 409);
    }
}
