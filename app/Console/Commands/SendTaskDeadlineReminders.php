<?php

namespace App\Console\Commands;

use App\Models\Task;
use App\Notifications\TaskDeadlineReminderNotification;
use Illuminate\Console\Command;

class SendTaskDeadlineReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-task-deadline-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send one-time reminders for tasks due tomorrow';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $tomorrow = now()->addDay()->toDateString();
        $tasks = Task::query()
            ->where('status', '!=', 'Completed')
            ->whereDate('due_date', $tomorrow)
            ->whereNull('deadline_reminder_sent_at')
            ->with('assignee')
            ->get();

        $notificationCount = 0;
        foreach ($tasks as $task) {
            if ($task->assignee) {
                $task->assignee->notify(new TaskDeadlineReminderNotification($task));
                $task->forceFill(['deadline_reminder_sent_at' => now()])->save();
                $notificationCount++;
            }
        }

        $this->info("Sent {$notificationCount} deadline reminder notifications.");

        return self::SUCCESS;
    }
}
