<?php

namespace App\Notifications;

use App\Contracts\SendsBrowserPush;
use App\Models\Task;
use App\Notifications\Channels\BrowserPushChannel;
use App\Support\BrowserPushMessageFactory;
use App\ValueObjects\BrowserPushMessage;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class TaskOverdueNotification extends Notification implements SendsBrowserPush
{
    use Queueable;

    protected $task;

    protected $daysOverdue;

    protected Carbon $deadline;

    /**
     * Create a new notification instance.
     */
    public function __construct(Task $task, $daysOverdue = null)
    {
        $this->task = $task;
        $this->deadline = $task->activeDeadline()
            ?? throw new \InvalidArgumentException('An active task deadline is required for an overdue notification.');
        $this->daysOverdue = $daysOverdue ?? CarbonImmutable::parse(
            $this->deadline->toDateString(),
            config('app.timezone'),
        )->startOfDay()->diffInDays(CarbonImmutable::now(config('app.timezone'))->startOfDay());
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', BrowserPushChannel::class];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $dueDate = $this->deadline->format('M d, Y');
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
        $dueDate = $this->deadline->format('M d, Y');
        $priority = $this->task->priority;
        $progress = $this->task->progress;

        return [
            'task_id' => $this->task->id,
            'task_uid' => $this->task->task_uid,
            'task_title' => $this->task->title,
            'due_date' => $dueDate,
            'priority' => $priority,
            'progress' => $progress,
            'days_overdue' => $this->daysOverdue,
            'message' => "Task '{$this->task->title}' is overdue by {$this->daysOverdue} day(s). Priority: {$priority}, Progress: {$progress}%",
            'type' => 'task_overdue',
        ];
    }

    public function toBrowserPush(object $notifiable): BrowserPushMessage
    {
        return BrowserPushMessageFactory::fromNotificationPayload($this->toArray($notifiable));
    }
}
