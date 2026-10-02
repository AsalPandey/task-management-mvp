<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use App\Support\TaskDeadlineRules;

final class TaskDeadlineAction
{
    public function for(Task $task, User $viewer): ?array
    {
        $kind = $task->activeDeadlineKind();
        if (! $kind || ! $viewer->can('changeDeadline', $task) || ! TaskDeadlineRules::allows($task, $kind)) {
            return null;
        }

        return [
            'type' => $kind,
            'label' => 'Change '.ucfirst($kind).' Deadline',
            'url' => route('tasks.deadline.change', ['task' => $task, 'deadlineType' => $kind]),
            'reason_required' => TaskDeadlineRules::reasonRequired($task, $kind),
        ];
    }
}
