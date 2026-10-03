<?php

namespace App\Console\Commands;

use App\Services\TaskDeadlineCandidates;
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
    public function handle(TaskDeadlineNotificationDelivery $deliveries, TaskDeadlineCandidates $candidates): int
    {
        $notificationCount = 0;
        $suppressedCount = 0;

        $candidates->each(TaskDeadlineNotificationDelivery::DEADLINE_REMINDER, function (int $taskId) use ($deliveries, &$notificationCount, &$suppressedCount): void {
            $result = $deliveries->deliver($taskId, TaskDeadlineNotificationDelivery::DEADLINE_REMINDER);
            $result->delivered() ? $notificationCount++ : $suppressedCount++;
        });
        $this->info("Sent {$notificationCount} deadline reminder notifications.");
        if ($suppressedCount > 0) {
            $this->warn("Suppressed {$suppressedCount} stale or ownerless deadline reminders.");
        }

        return self::SUCCESS;
    }
}
