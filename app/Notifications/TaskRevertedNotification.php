<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskRevertedNotification extends Notification
{
    use Queueable;

    protected $task;

    protected $revertedBy;

    /**
     * Create a new notification instance.
     */
    public function __construct($task, $revertedBy = null)
    {
        $this->task = $task;
        $this->revertedBy = $revertedBy;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $revertedByName = $this->revertedBy ? $this->revertedBy->name : 'System';
        $taskTitle = $this->task->title;

        return (new MailMessage)
            ->subject('Task Reverted: '.$taskTitle)
            ->line("The completed task '{$taskTitle}' has been reverted to active status by {$revertedByName}.")
            ->line('The task is now back in your active tasks list.')
            ->line('Status: In Progress')
            ->action('View Task', url('/tasks'))
            ->line('Please continue working on this task.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $revertedByName = $this->revertedBy ? $this->revertedBy->name : 'System';
        $taskTitle = $this->task->title;

        return [
            'task_id' => $this->task->id,
            'task_title' => $taskTitle,
            'reverted_by' => $revertedByName,
            'reverted_at' => now()->format('M d, Y H:i'),
            'message' => "Task '{$taskTitle}' was reverted to active status by {$revertedByName}.",
            'type' => 'task_reverted',
        ];
    }
}
