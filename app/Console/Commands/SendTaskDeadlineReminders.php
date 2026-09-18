<?php

namespace App\Console\Commands;

use App\Enums\TaskState;
use App\Models\Task;
use App\Services\TaskDeadlineNotificationDelivery;
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
    public function handle(TaskDeadlineNotificationDelivery $deliveries): int
    {
        $tomorrow = today(config('app.timezone'))->addDay()->toDateString();
        $notificationCount = 0;
        $suppressedCount = 0;

        Task::query()
            ->whereNotIn('status', [TaskState::Completed->value, TaskState::Cancelled->value])
            ->where(function ($query) use ($tomorrow): void {
                $query->whereDate('execution_due_date', $tomorrow)
                    ->orWhereDate('due_date', $tomorrow)
                    ->orWhereDate('review_due_date', $tomorrow)
                    ->orWhereDate('revision_due_date', $tomorrow);
            })
            ->chunkById(200, function ($tasks) use ($deliveries, &$notificationCount, &$suppressedCount): void {
                foreach ($tasks as $task) {
                    $result = $deliveries->deliver($task->id, TaskDeadlineNotificationDelivery::DEADLINE_REMINDER);
                    $result->delivered() ? $notificationCount++ : $suppressedCount++;
                }
            });

        $this->info("Sent {$notificationCount} deadline reminder notifications.");
        if ($suppressedCount > 0) {
            $this->warn("Suppressed {$suppressedCount} stale or ownerless deadline reminders.");
        }

        return self::SUCCESS;
    }
}
