<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Task;
use App\Notifications\TaskDeadlineReminderNotification;

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
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $tomorrow = now()->addDay()->startOfDay();
        $tasks = Task::where('status', '!=', 'Completed')
            ->whereDate('due_date', $tomorrow)
            ->with('assignee')
            ->get();
        foreach ($tasks as $task) {
            if ($task->assignee) {
                $task->assignee->notify(new TaskDeadlineReminderNotification());
            }
        }
        $this->info('Task deadline reminders sent.');
    }
}
