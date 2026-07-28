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
use Illuminate\Support\Str;

class TaskReviewWorkflowNotification extends Notification implements SendsBrowserPush
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
        return ['database', BrowserPushChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        $cycle = $this->task->activeRevisionCycle;
        $approval = $this->task->approval;
        $canViewManagementNotes = $notifiable instanceof User
            && $notifiable->can('viewManagementNotes', $this->task);

        $message = match ($this->transition) {
            'reviewer_reassigned' => "{$this->actor->name} reassigned the reviewer for '{$this->task->title}'.",
            'deadline_changed' => "{$this->actor->name} changed a workflow deadline for '{$this->task->title}'.",
            'approved_completed' => "{$this->actor->name} approved and completed '{$this->task->title}'.",
            'reopened_revision_required' => "{$this->actor->name} reopened '{$this->task->title}' for revision.",
            'cancelled' => "{$this->actor->name} cancelled '{$this->task->title}'.",
            default => "{$this->actor->name} changed '{$this->task->title}' to {$this->task->statusLabel()}.",
        };

        $payload = [
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
            'approver_id' => $approval?->approved_by,
            'approver' => $approval?->approver?->name ?? $this->actor->name,
            'approved_at' => $this->task->approved_at?->toAtomString(),
            'completed_at' => $this->task->completed_at?->toAtomString(),
            'approval_comment_reference' => $approval?->approval_comment
                ? "task_approvals:{$approval->id}"
                : null,
            'revision_count' => (int) $this->task->revision_count,
            'revision_cycle_id' => $cycle?->id,
            'revision_cycle_number' => $cycle?->cycle_number,
            'revision_due_date' => $this->task->revision_due_date?->toDateString(),
            'feedback_reference' => $cycle ? "task_revision_cycles:{$cycle->id}" : null,
            'feedback_excerpt' => $cycle?->formal_feedback
                ? Str::limit($cycle->formal_feedback, 160)
                : null,
            'cancelled_by' => $this->task->cancelled_by,
            'cancelled_by_name' => $this->task->cancelledBy?->name,
            'cancelled_at' => $this->task->cancelled_at?->toAtomString(),
            'current_state' => $this->task->machineState()->value,
            'required_action' => $this->requiredAction,
            'task_url' => route('tasks'),
            'type' => 'task_'.$this->transition,
            'message' => $message,
        ];

        if ($canViewManagementNotes) {
            $payload += [
                'reopen_reason_reference' => $cycle?->reopen_reason
                    ? "task_revision_cycles:{$cycle->id}"
                    : null,
                'reopen_reason_excerpt' => $cycle?->reopen_reason
                    ? Str::limit($cycle->reopen_reason, 160)
                    : null,
                'cancellation_reason_reference' => $this->task->cancellation_reason
                    ? "tasks:{$this->task->id}:cancellation_reason"
                    : null,
                'cancellation_reason_excerpt' => $this->task->cancellation_reason
                    ? Str::limit($this->task->cancellation_reason, 160)
                    : null,
            ];
        }

        return $payload;
    }

    public function toBrowserPush(object $notifiable): BrowserPushMessage
    {
        return BrowserPushMessageFactory::fromNotificationPayload($this->toArray($notifiable));
    }
}
