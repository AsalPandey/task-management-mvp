<?php

use App\Models\Task;
use App\Models\User;
use App\Services\TaskDeadlineNotificationDelivery;
use App\Services\TaskNotificationDispatcher;
use App\Services\TaskTransitionExecutor;
use App\TaskTransitions\ApproveTask;
use App\TaskTransitions\CancelTask;
use App\ValueObjects\TaskOperationContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $operation, $taskId, $argument, $readyFile, $releaseFile] = $argv;

try {
    $result = DB::transaction(function () use ($operation, $taskId, $argument, $readyFile, $releaseFile): array {
        $task = Task::query()->whereKey((int) $taskId)->lockForUpdate()->firstOrFail();
        file_put_contents($readyFile, 'locked');
        $deadline = microtime(true) + 15;
        while (! file_exists($releaseFile) && microtime(true) < $deadline) {
            usleep(10_000);
        }

        if ($operation === 'deliver') {
            $delivery = app(TaskDeadlineNotificationDelivery::class)->deliver($task->id, $argument);

            return ['result' => $delivery->status];
        }

        if ($operation === 'rollback-deliver') {
            app(TaskDeadlineNotificationDelivery::class)->deliver($task->id, $argument);
            throw new RuntimeException('Intentional pre-commit worker failure.');
        }

        if ($operation === 'scheduler') {
            Artisan::call('app:send-overdue-task-notifications');

            return ['result' => 'scheduled'];
        }

        if ($operation === 'opportunistic') {
            app(TaskNotificationDispatcher::class)->taskUpdated($task, [], $task->assignee);

            return ['result' => 'opportunistic'];
        }

        if ($operation === 'deadline') {
            $task->forceFill(['execution_due_date' => $argument, 'due_date' => $argument])->save();

            return ['result' => 'mutated'];
        }

        if (in_array($operation, ['assignee', 'reviewer'], true)) {
            $task->forceFill([$operation.'_id' => (int) $argument])->save();

            return ['result' => 'mutated'];
        }

        $actor = User::query()->findOrFail((int) $argument);
        $executor = app(TaskTransitionExecutor::class);
        $context = TaskOperationContext::test($actor->id, "r2b5q-{$operation}");
        $command = $operation === 'cancel'
            ? app()->make(CancelTask::class, [
                'cancellationReason' => 'R2B.5Q concurrent cancellation',
                'expectedState' => $task->machineState()->value,
            ])
            : app(ApproveTask::class);
        $executor->execute($task, $actor, $command, $context);

        return ['result' => 'transitioned'];
    });

    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage());
    exit(1);
}
