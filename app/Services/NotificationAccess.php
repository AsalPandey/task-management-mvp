<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;

final class NotificationAccess
{
    public function allows(?User $user, array $data): bool
    {
        if (! $user || ! $user->isActive()) {
            return false;
        }
        if (isset($data['task_id'])) {
            $task = Task::find($data['task_id']);

            return $task && $user->can('view', $task);
        }
        if (isset($data['project_id'])) {
            $project = Project::find($data['project_id']);

            return $project && $user->can('view', $project);
        }

        return true;
    }

    public function forReader(?User $user, array $data): array
    {
        if (! $this->allows($user, $data)) {
            return ['type' => 'access_removed', 'message' => 'This notification is no longer available.'];
        }
        if (isset($data['task_id'])) {
            $task = Task::find($data['task_id']);
            if (! $user->can('viewManagementNotes', $task)) {
                foreach (['reopen_reason_reference', 'reopen_reason_excerpt', 'cancellation_reason_reference', 'cancellation_reason_excerpt'] as $key) {
                    unset($data[$key]);
                }
            }
        }

        return $data;
    }
}
