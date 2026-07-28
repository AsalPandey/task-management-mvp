<?php

namespace App\ValueObjects;

use Illuminate\Support\Str;

final readonly class BrowserPushMessage
{
    public function __construct(
        public string $title,
        public string $body,
        public string $type,
        public string $targetPath = '/notifications/all',
        public ?int $taskId = null,
        public ?string $taskUid = null,
        public ?string $state = null,
        public ?string $deadline = null,
        public ?string $tag = null,
    ) {}

    public function payload(string $notificationId): array
    {
        $fallback = (string) (parse_url(url('/notifications/all'), PHP_URL_PATH) ?: '/notifications/all');
        $target = str_starts_with($this->targetPath, '/')
            && ! str_starts_with($this->targetPath, '//')
            ? $this->targetPath
            : $fallback;

        return [
            'title' => Str::limit(strip_tags($this->title), 80, ''),
            'body' => Str::limit(strip_tags($this->body), 180, ''),
            'icon' => (string) (parse_url(asset('icons/pwa-192.png'), PHP_URL_PATH) ?: '/icons/pwa-192.png'),
            'badge' => (string) (parse_url(asset('icons/pwa-badge-96.png'), PHP_URL_PATH) ?: '/icons/pwa-badge-96.png'),
            'tag' => $this->tag ?: "task-management-{$notificationId}",
            'data' => array_filter([
                'notification_id' => $notificationId,
                'type' => $this->type,
                'task_id' => $this->taskId,
                'task_uid' => $this->taskUid,
                'state' => $this->state,
                'deadline' => $this->deadline,
                'target' => $target,
            ], fn (mixed $value): bool => $value !== null),
        ];
    }
}
