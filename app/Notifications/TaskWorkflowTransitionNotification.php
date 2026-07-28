<?php

namespace App\Notifications;

use App\Contracts\SendsBrowserPush;
use App\Models\Task;
use App\Models\User;
use App\Notifications\Channels\BrowserPushChannel;
use App\Support\BrowserPushMessageFactory;
use App\ValueObjects\BrowserPushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TaskWorkflowTransitionNotification extends Notification implements SendsBrowserPush
{
    use Queueable;

    public function __construct(
        private readonly Task $task,
        private readonly User $actor,
        private readonly string $transition,
        private readonly string $nextAction,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', BrowserPushChannel::class];
    }

    /**
     * @return array<string, int|string|null>
     */
    public function toArray(object $notifiable): array
    {
        $deadline = $this->task->execution_due_date ?? $this->task->due_date;
        $state = $this->task->machineState();

        return [
            'task_id' => (int) $this->task->id,
            'task_uid' => $this->task->task_uid,
            'task_title' => $this->task->title,
            'actor_id' => (int) $this->actor->id,
            'actor' => $this->actor->name,
            'current_state' => $state->value,
            'current_state_label' => $state->label(),
            'deadline' => $deadline?->toDateString(),
            'next_action' => $this->nextAction,
            'type' => 'task_'.$this->transition,
            'message' => "{$this->actor->name} changed '{$this->task->title}' to {$state->label()}.",
        ];
    }

    public function toBrowserPush(object $notifiable): BrowserPushMessage
    {
        return BrowserPushMessageFactory::fromNotificationPayload($this->toArray($notifiable));
    }
}
