<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Task;
use App\Notifications\TaskOverdueNotification;

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
    public function handle()
    {
        $overdueTasks = Task::where('status', '!=', 'Completed')
            ->where('due_date', '<', now())
            ->with('assignee')
            ->get();

        $notificationCount = 0;
        foreach ($overdueTasks as $task) {
            if ($task->assignee) {
                $task->assignee->notify(new TaskOverdueNotification($task));
                $notificationCount++;
            }
        }

        $this->info("Sent {$notificationCount} overdue task notifications.");
    }
} 