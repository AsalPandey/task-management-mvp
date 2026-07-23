<?php

use App\Models\Task;
use App\Models\User;
use App\Services\TaskLifecycleService;
use App\Services\TaskTransitionExecutor;
use App\TaskTransitions\HoldTask;
use App\TaskTransitions\ResumeTask;
use App\TaskTransitions\StartTask;
use App\ValueObjects\TaskOperationContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$operation = (string) ($argv[1] ?? 'complete');
$taskId = (int) ($argv[2] ?? 0);
$actorId = (int) ($argv[3] ?? 0);
$worker = (string) ($argv[4] ?? 'worker');
$holdMilliseconds = (int) ($argv[5] ?? 0);
$readyFile = $argv[6] ?? null;

try {
    $task = DB::transaction(function () use ($operation, $taskId, $actorId, $worker, $holdMilliseconds, $readyFile) {
        if ($holdMilliseconds > 0) {
            Task::withTrashed()->whereKey($taskId)->lockForUpdate()->firstOrFail();

            if ($readyFile) {
                file_put_contents($readyFile, 'locked');
            }

            usleep($holdMilliseconds * 1000);
        }

        $task = Task::withTrashed()->findOrFail($taskId);
        $actor = User::query()->findOrFail($actorId);
        $service = app(TaskLifecycleService::class);
        $executor = app(TaskTransitionExecutor::class);
        $context = TaskOperationContext::test(
            $actor->id,
            "{$operation}-concurrency-{$worker}",
        );

        return match ($operation) {
            'complete' => $service->complete($task, $actor, context: $context),
            'reopen' => $service->reopen($task, $actor, $context),
            'start' => $executor->execute($task, $actor, app(StartTask::class), $context)->task,
            'hold' => $executor->execute(
                $task,
                $actor,
                app()->make(HoldTask::class, ['reason' => 'Concurrent hold']),
                $context,
            )->task,
            'resume' => $executor->execute($task, $actor, app(ResumeTask::class), $context)->task,
            default => throw new InvalidArgumentException("Unsupported lifecycle operation [{$operation}]."),
        };
    });

    $result = [
        'result' => 'transitioned',
        'database' => DB::getDatabaseName(),
        'task_id' => $task->id,
        'task_uid' => $task->task_uid,
        'status' => $task->status,
    ];
} catch (ValidationException $exception) {
    $result = [
        'result' => 'conflict',
        'status_code' => 422,
        'database' => DB::getDatabaseName(),
        'message' => collect($exception->errors())->flatten()->first(),
    ];
}

fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));
