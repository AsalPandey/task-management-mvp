<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskOverdueNotification extends Notification
{
    use Queueable;

    protected $task;

    protected $daysOverdue;

    /**
     * Create a new notification instance.
     */
    public function __construct(Task $task, $daysOverdue = null)
    {
        $this->task = $task;
        $this->daysOverdue = $daysOverdue ?? now()->diffInDays($task->due_date);
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
            ->subject('Task Overdue: '.$this->task->title)
            ->line("Your task '{$this->task->title}' is overdue by {$this->daysOverdue} day(s).")
            ->line("Due Date: {$dueDate}")
            ->line("Priority: {$priority}")
            ->line("Current Progress: {$progress}%")
            ->action('View Task', url('/tasks'))
            ->line('Please complete this task as soon as possible!');
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
            'days_overdue' => $this->daysOverdue,
            'message' => "Task '{$this->task->title}' is overdue by {$this->daysOverdue} day(s). Priority: {$priority}, Progress: {$progress}%",
            'type' => 'task_overdue',
        ];
    }
}
