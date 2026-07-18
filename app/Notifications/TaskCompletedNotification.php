<?php

namespace App\Notifications;

use App\Models\CompletedTask;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskCompletedNotification extends Notification
{
    use Queueable;

    protected $task;

    protected $completedBy;

    protected $isForAssignee;

    /**
     * Create a new notification instance.
     */
    public function __construct($task, $completedBy = null, $isForAssignee = true)
    {
        $this->task = $task;
        $this->completedBy = $completedBy;
        $this->isForAssignee = $isForAssignee;
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
        $completedByName = $this->completedBy ? $this->completedBy->name : 'Unknown';
        $taskTitle = $this->task instanceof CompletedTask ? $this->task->title : $this->task->title;

        if ($this->isForAssignee) {
            return (new MailMessage)
                ->subject('Task Completed: '.$taskTitle)
                ->line("Great job! You have completed the task '{$taskTitle}'.")
                ->line('Task was completed on: '.now()->format('M d, Y H:i'))
                ->action('View Completed Tasks', url('/completed-tasks'))
                ->line('Keep up the excellent work!');
        } else {
            return (new MailMessage)
                ->subject('Task Completed by Team Member: '.$taskTitle)
                ->line("The task '{$taskTitle}' has been completed by {$completedByName}.")
                ->line('Task was completed on: '.now()->format('M d, Y H:i'))
                ->action('View Completed Tasks', url('/completed-tasks'))
                ->line('Thank you for managing the team!');
        }
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $completedByName = $this->completedBy ? $this->completedBy->name : 'Unknown';
        $taskTitle = $this->task instanceof CompletedTask ? $this->task->title : $this->task->title;
        $completedAt = $this->getFormattedCompletedAt();

        if ($this->isForAssignee) {
            return [
                'task_id' => $this->task->id,
                'task_title' => $taskTitle,
                'completed_by' => $completedByName,
                'completed_at' => $completedAt,
                'message' => "Task '{$taskTitle}' has been completed successfully!",
                'type' => 'task_completed_assignee',
            ];
        } else {
            return [
                'task_id' => $this->task->id,
                'task_title' => $taskTitle,
                'completed_by' => $completedByName,
                'completed_at' => $completedAt,
                'message' => "Task '{$taskTitle}' was completed by {$completedByName}.",
                'type' => 'task_completed_assignor',
            ];
        }
    }

    /**
     * Get formatted completed at date with safety checks
     */
    private function getFormattedCompletedAt()
    {
        if ($this->task instanceof CompletedTask && $this->task->completed_at) {
            // If it's already a Carbon instance
            if (is_object($this->task->completed_at) && method_exists($this->task->completed_at, 'format')) {
                return $this->task->completed_at->format('M d, Y H:i');
            }

            // If it's a string, try to parse it
            if (is_string($this->task->completed_at)) {
                try {
                    $date = Carbon::parse($this->task->completed_at);

                    return $date->format('M d, Y H:i');
                } catch (\Exception $e) {
                    return $this->task->completed_at;
                }
            }
        }

        return now()->format('M d, Y H:i');
    }
}
