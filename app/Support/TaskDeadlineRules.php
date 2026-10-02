<?php

namespace App\Support;

use App\Enums\TaskState;
use App\Models\Task;

final class TaskDeadlineRules
{
    private const ALLOWED_STATES = [
        'execution' => [TaskState::NotStarted, TaskState::InProgress, TaskState::OnHold],
        'review' => [TaskState::NotStarted, TaskState::InProgress, TaskState::OnHold, TaskState::Submitted, TaskState::InReview],
        'revision' => [TaskState::RevisionRequested, TaskState::InProgress],
    ];

    public static function supports(string $kind): bool
    {
        return isset(self::ALLOWED_STATES[$kind]);
    }

    public static function allows(Task $task, string $kind): bool
    {
        return self::supports($kind) && in_array($task->machineState(), self::ALLOWED_STATES[$kind], true);
    }

    public static function reasonRequired(Task $task, string $kind): bool
    {
        return match ($kind) {
            'execution' => $task->machineState() !== TaskState::NotStarted || $task->started_at !== null,
            'review' => $task->machineState()->isReviewState(),
            'revision' => true,
        };
    }
}
