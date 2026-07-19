<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class TaskNotificationDispatchException extends RuntimeException
{
    public function __construct(
        public readonly string $operation,
        public readonly int $taskId,
        Throwable $previous,
    ) {
        parent::__construct(
            "Task notification dispatch failed for [{$operation}] on task [{$taskId}].",
            0,
            $previous,
        );
    }
}
