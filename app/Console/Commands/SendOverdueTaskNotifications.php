<?php

namespace App\Console\Commands;

use App\Models\Task;
use App\Notifications\TaskOverdueNotification;
use Illuminate\Console\Command;

class SendOverdueTaskNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-overdue-task-notifications';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send notifications for overdue tasks';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $overdueTasks = Task::query()
            ->where('status', '!=', 'Completed')
            ->where('due_date', '<', now())
            ->whereNull('overdue_notification_sent_at')
            ->with('assignee')
            ->get();

        $notificationCount = 0;
        foreach ($overdueTasks as $task) {
            if ($task->assignee) {
                $task->assignee->notify(new TaskOverdueNotification($task));
                $task->forceFill(['overdue_notification_sent_at' => now()])->save();
                $notificationCount++;
            }
        }

        $this->info("Sent {$notificationCount} overdue task notifications.");

        return self::SUCCESS;
    }
}
