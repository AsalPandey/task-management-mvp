<?php

namespace App\Contracts;

use App\Models\Task;
use App\Models\User;
use App\ValueObjects\TaskOperationContext;
use App\ValueObjects\TaskTransitionEffects;

interface TaskTransitionCommand
{
    public function ability(): string;

    public function validate(Task $task, User $actor): void;

    public function apply(Task $task, User $actor, TaskOperationContext $context): TaskTransitionEffects;
}
