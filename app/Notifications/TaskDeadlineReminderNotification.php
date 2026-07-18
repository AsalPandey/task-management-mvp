<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskDeadlineReminderNotification extends Notification
{
    use Queueable;

    protected $task;

    /**
     * Create a new notification instance.
     */
    public function __construct(Task $task)
    {
        $this->task = $task;
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
        $dueDate = $this->task->due_date->format('M d, Y');
        $priority = $this->task->priority;
        $progress = $this->task->progress;

        return (new MailMessage)
            ->subject('Task Deadline Reminder: '.$this->task->title)
            ->line("Your task '{$this->task->title}' is due tomorrow ({$dueDate}).")
            ->line("Priority: {$priority}")
            ->line("Current Progress: {$progress}%")
            ->action('View Task', url('/tasks'))
            ->line('Please ensure timely completion!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $dueDate = $this->task->due_date->format('M d, Y');
        $priority = $this->task->priority;
        $progress = $this->task->progress;

        return [
            'task_id' => $this->task->id,
            'task_title' => $this->task->title,
            'due_date' => $dueDate,
            'priority' => $priority,
            'progress' => $progress,
            'message' => "Task '{$this->task->title}' is due tomorrow ({$dueDate}). Priority: {$priority}, Progress: {$progress}%",
            'type' => 'task_deadline_reminder',
        ];
    }
}
