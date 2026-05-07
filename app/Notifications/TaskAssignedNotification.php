<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\Task;

class TaskAssignedNotification extends Notification
{
    use Queueable;

    protected $task;
    protected $assignor;

    /**
     * Create a new notification instance.
     */
    public function __construct(Task $task, $assignor = null)
    {
        $this->task = $task;
        $this->assignor = $assignor;
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
        $assignorName = $this->assignor ? $this->assignor->name : 'System';
        $dueDate = $this->getFormattedDueDate();
        
        return (new MailMessage)
            ->subject('New Task Assigned: ' . $this->task->title)
            ->line("You have been assigned a new task by {$assignorName}.")
            ->line("Task: {$this->task->title}")
            ->line("Priority: {$this->task->priority}")
            ->line($dueDate)
            ->action('View Task', url('/tasks'))
            ->line('Thank you for using our application!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $assignorName = $this->assignor ? $this->assignor->name : 'System';
        $dueDate = $this->getFormattedDueDate();
        
        return [
            'task_id' => $this->task->id,
            'task_title' => $this->task->title,
            'priority' => $this->task->priority,
            'due_date' => $dueDate,
            'assignor' => $assignorName,
            'message' => "New task '{$this->task->title}' assigned by {$assignorName}. Priority: {$this->task->priority}, {$dueDate}",
            'type' => 'task_assigned'
        ];
    }

    /**
     * Get formatted due date with safety checks
     */
    private function getFormattedDueDate()
    {
        if (!$this->task->due_date) {
            return 'No due date';
        }

        // If it's already a Carbon instance
        if (is_object($this->task->due_date) && method_exists($this->task->due_date, 'format')) {
            return 'Due: ' . $this->task->due_date->format('M d, Y');
        }

        // If it's a string, try to parse it
        if (is_string($this->task->due_date)) {
            try {
                $date = \Carbon\Carbon::parse($this->task->due_date);
                return 'Due: ' . $date->format('M d, Y');
            } catch (\Exception $e) {
                return 'Due: ' . $this->task->due_date;
            }
        }

        return 'No due date';
    }
}
