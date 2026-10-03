<?php

namespace App\Console\Commands;

use App\Services\TaskDeadlineCandidates;
use App\Services\TaskDeadlineNotificationDelivery;
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
    public function handle(TaskDeadlineNotificationDelivery $deliveries, TaskDeadlineCandidates $candidates): int
    {
        $notificationCount = 0;
        $suppressedCount = 0;

        $candidates->each(TaskDeadlineNotificationDelivery::OVERDUE, function (int $taskId) use ($deliveries, &$notificationCount, &$suppressedCount): void {
            $result = $deliveries->deliver($taskId, TaskDeadlineNotificationDelivery::OVERDUE);
            $result->delivered() ? $notificationCount++ : $suppressedCount++;
        });
        $this->info("Sent {$notificationCount} overdue task notifications.");
        if ($suppressedCount > 0) {
            $this->warn("Suppressed {$suppressedCount} stale or ownerless overdue notifications.");
        }

        return self::SUCCESS;
    }
}
