<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\Task;

class TaskUpdatedNotification extends Notification
{
    use Queueable;

    protected $task;
    protected $changes;
    protected $updatedBy;

    /**
     * Create a new notification instance.
     */
    public function __construct(Task $task, $changes = [], $updatedBy = null)
    {
        $this->task = $task;
        $this->changes = $changes;
        $this->updatedBy = $updatedBy;
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
        $updatedByName = $this->updatedBy ? $this->updatedBy->name : 'System';
        $changeSummary = $this->getChangeSummary();
        
        return (new MailMessage)
            ->subject('Task Updated: ' . $this->task->title)
            ->line("The task '{$this->task->title}' has been updated by {$updatedByName}.")
            ->line($changeSummary)
            ->action('View Task', url('/tasks'))
            ->line('Please review the changes.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $updatedByName = $this->updatedBy ? $this->updatedBy->name : 'System';
        $changeSummary = $this->getChangeSummary();
        
        return [
            'task_id' => $this->task->id,
            'task_title' => $this->task->title,
            'updated_by' => $updatedByName,
            'changes' => $this->changes,
            'message' => "Task '{$this->task->title}' was updated by {$updatedByName}. {$changeSummary}",
            'type' => 'task_updated'
        ];
    }

    /**
     * Get a summary of the changes made to the task.
     */
    private function getChangeSummary()
    {
        if (empty($this->changes)) {
            return 'Task details have been updated.';
        }

        $summary = [];
        foreach ($this->changes as $field => $value) {
            switch ($field) {
                case 'title':
                    $summary[] = 'Title updated';
                    break;
                case 'description':
                    $summary[] = 'Description updated';
                    break;
                case 'priority':
                    $summary[] = "Priority changed to {$value}";
                    break;
                case 'status':
                    $summary[] = "Status changed to {$value}";
                    break;
                case 'progress':
                    $summary[] = "Progress updated to {$value}%";
                    break;
                case 'due_date':
                    $summary[] = 'Due date updated';
                    break;
                case 'assignee_id':
                    $summary[] = 'Assignee changed';
                    break;
                default:
                    $summary[] = ucfirst($field) . ' updated';
            }
        }

        return implode(', ', $summary);
    }
} 