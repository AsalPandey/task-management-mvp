<?php

namespace App\Console\Commands;

use App\Enums\TaskState;
use App\Models\Task;
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
    public function handle(TaskDeadlineNotificationDelivery $deliveries): int
    {
        $today = today(config('app.timezone'))->toDateString();
        $notificationCount = 0;
        $suppressedCount = 0;

        Task::query()
            ->whereNotIn('status', [TaskState::Completed->value, TaskState::Cancelled->value])
            ->where(function ($query) use ($today): void {
                $query->whereDate('execution_due_date', '<', $today)
                    ->orWhereDate('due_date', '<', $today)
                    ->orWhereDate('review_due_date', '<', $today)
                    ->orWhereDate('revision_due_date', '<', $today);
            })
            ->chunkById(200, function ($tasks) use ($deliveries, &$notificationCount, &$suppressedCount): void {
                foreach ($tasks as $task) {
                    $result = $deliveries->deliver($task->id, TaskDeadlineNotificationDelivery::OVERDUE);
                    $result->delivered() ? $notificationCount++ : $suppressedCount++;
                }
            });

        $this->info("Sent {$notificationCount} overdue task notifications.");
        if ($suppressedCount > 0) {
            $this->warn("Suppressed {$suppressedCount} stale or ownerless overdue notifications.");
        }

        return self::SUCCESS;
    }
}
