<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TaskReviewWorkflowNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Task $task,
        private readonly User $actor,
        private readonly string $transition,
        private readonly string $requiredAction,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'task_id' => (int) $this->task->id,
            'task_uid' => $this->task->task_uid,
            'task_title' => $this->task->title,
            'assignee_id' => (int) $this->task->assignee_id,
            'assignee' => $this->task->assignee?->name,
            'reviewer_id' => (int) $this->task->reviewer_id,
            'reviewer' => $this->task->reviewer?->name,
            'submitted_at' => $this->task->submitted_at?->toAtomString(),
            'review_started_at' => $this->task->review_started_at?->toAtomString(),
            'review_due_date' => $this->task->review_due_date?->toDateString(),
            'current_state' => $this->task->machineState()->value,
            'required_action' => $this->requiredAction,
            'task_url' => route('tasks'),
            'type' => 'task_'.$this->transition,
            'message' => "{$this->actor->name} changed '{$this->task->title}' to {$this->task->statusLabel()}.",
        ];
    }
}
