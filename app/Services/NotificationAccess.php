<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class NotificationAccess
{
    public function forOwnerReader(int $ownerId, array $data): array
    {
        // Memoize presentation only on the current HTTP read request. Delivery and
        // mutation authorization always use allows() and fresh database state.
        $request = request();
        $memoize = $request->isMethodSafe() && $request->route() !== null;
        $owner = $this->readerResource(User::class, $ownerId, $memoize);

        return $this->forReader($owner, $data, $memoize);
    }

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

    public function forReader(?User $user, array $data, bool $memoize = false): array
    {
        $task = isset($data['task_id']) ? $this->readerResource(Task::class, $data['task_id'], $memoize) : null;
        $project = ! isset($data['task_id']) && isset($data['project_id'])
            ? $this->readerResource(Project::class, $data['project_id'], $memoize) : null;
        if (! $user || ! $user->isActive()
            || (isset($data['task_id']) && (! $task || ! $user->can('view', $task)))
            || (! isset($data['task_id']) && isset($data['project_id']) && (! $project || ! $user->can('view', $project)))) {
            return ['type' => 'access_removed', 'message' => 'This notification is no longer available.'];
        }
        if ($task) {
            if (! $user->can('viewManagementNotes', $task)) {
                foreach (['reopen_reason_reference', 'reopen_reason_excerpt', 'cancellation_reason_reference', 'cancellation_reason_excerpt'] as $key) {
                    unset($data[$key]);
                }
            }
        }

        return $data;
    }

    private function readerResource(string $model, int|string $id, bool $memoize): ?Model
    {
        if (! $memoize) {
            return $model::find($id);
        }
        $request = request();
        $key = $model.':'.$id;
        $resources = $request->attributes->get('notification_reader_resources', []);
        if (! array_key_exists($key, $resources)) {
            $resources[$key] = $model::find($id);
            $request->attributes->set('notification_reader_resources', $resources);
        }

        return $resources[$key];
    }
}
