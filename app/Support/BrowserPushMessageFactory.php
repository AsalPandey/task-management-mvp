<?php

namespace App\Support;

use App\ValueObjects\BrowserPushMessage;

final class BrowserPushMessageFactory
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromNotificationPayload(array $payload): BrowserPushMessage
    {
        $type = (string) ($payload['type'] ?? 'task_update');
        $taskId = isset($payload['task_id']) ? (int) $payload['task_id'] : null;
        $title = match (true) {
            str_contains($type, 'assigned') => 'New task assigned',
            str_contains($type, 'deadline_reminder') => 'Task deadline reminder',
            str_contains($type, 'overdue') => 'Task overdue',
            str_contains($type, 'submitted'), str_contains($type, 'resubmitted') => 'Task ready for review',
            str_contains($type, 'revision') || str_contains($type, 'reopened') => 'Task revision update',
            str_contains($type, 'approved') || str_contains($type, 'completed') => 'Task approved and completed',
            str_contains($type, 'cancelled') => 'Task cancelled',
            str_contains($type, 'reviewer_reassigned') => 'Task reviewer changed',
            str_contains($type, 'deadline_changed') => 'Task deadline changed',
            default => 'Task updated',
        };

        $state = isset($payload['current_state']) ? (string) $payload['current_state'] : null;
        $filters = http_build_query(array_filter(['status' => $state]));
        $targetPath = $taskId
            ? self::applicationPath('tasks').($filters ? "?{$filters}" : '')."#task-{$taskId}"
            : self::applicationPath('notifications/all');

        return new BrowserPushMessage(
            title: $title,
            body: (string) ($payload['message'] ?? 'A task has an update.'),
            type: $type,
            targetPath: $targetPath,
            taskId: $taskId,
            taskUid: isset($payload['task_uid']) ? (string) $payload['task_uid'] : null,
            state: $state,
            deadline: self::deadline($payload),
            tag: $taskId ? "task-{$taskId}-{$type}" : null,
        );
    }

    public static function applicationPath(string $path): string
    {
        return (string) (parse_url(url('/'.ltrim($path, '/')), PHP_URL_PATH) ?: '/');
    }

    private static function deadline(array $payload): ?string
    {
        foreach (['deadline', 'due_date', 'review_due_date', 'revision_due_date'] as $key) {
            if (filled($payload[$key] ?? null)) {
                return (string) $payload[$key];
            }
        }

        return null;
    }
}
