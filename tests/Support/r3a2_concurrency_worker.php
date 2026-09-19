<?php

use App\Exceptions\AccountLifecycleException;
use App\Exceptions\DuplicateTaskOperationException;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\AccountLifecycleService;
use App\Services\TaskLifecycleService;
use App\Services\TaskTransitionExecutor;
use App\TaskTransitions\ReopenApprovedTask;
use App\TaskTransitions\StartTaskReview;
use App\ValueObjects\TaskOperationContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$operation = (string) ($argv[1] ?? '');
$payload = json_decode($argv[2] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
$holdMilliseconds = (int) ($argv[3] ?? 0);
$readyFile = $argv[4] ?? null;
$startFile = $argv[5] ?? null;

if ($startFile) {
    $deadline = microtime(true) + 5;
    while (! file_exists($startFile) && microtime(true) < $deadline) {
        usleep(10_000);
    }
}

try {
    $details = DB::transaction(function () use ($operation, $payload, $holdMilliseconds, $readyFile) {
        $actor = User::query()->findOrFail((int) $payload['actor_id']);

        if ($operation === 'create') {
            $task = app(TaskLifecycleService::class)->create(
                $payload['task'],
                $actor,
                TaskOperationContext::test($actor->id, $payload['correlation_id']),
            );

            return ['task_id' => $task->id, 'outcome' => 'created'];
        }

        if ($operation === 'demote') {
            Role::query()->where('name', 'manager')->lockForUpdate()->first();
            $target = User::query()->with('role')->whereKey($payload['target_id'])->lockForUpdate()->firstOrFail();
            if ($readyFile) {
                file_put_contents($readyFile, 'locked');
            }
            if ($holdMilliseconds > 0) {
                usleep($holdMilliseconds * 1000);
            }
            $memberRole = Role::query()->where('name', 'team_member')->firstOrFail();
            app(AccountLifecycleService::class)->assertCanChangeRole($target, $memberRole->id, $actor);
            $target->update(['role_id' => $memberRole->id]);

            return ['outcome' => 'demoted'];
        }

        if ($operation === 'deactivate') {
            $target = User::query()->with('role')->whereKey($payload['target_id'])->lockForUpdate()->firstOrFail();
            if ($readyFile) {
                file_put_contents($readyFile, 'locked');
            }
            if ($holdMilliseconds > 0) {
                usleep($holdMilliseconds * 1000);
            }
            app(AccountLifecycleService::class)->assertCanDeactivate($target, $actor);
            $target->forceFill(['active' => false])->save();

            return ['outcome' => 'deactivated'];
        }

        $task = Task::query()->findOrFail((int) $payload['task_id']);
        $executor = app(TaskTransitionExecutor::class);
        $context = TaskOperationContext::test($actor->id, $payload['correlation_id']);

        if ($operation === 'review') {
            $result = $executor->execute($task, $actor, app(StartTaskReview::class), $context);
        } elseif ($operation === 'reopen') {
            $result = $executor->execute($task, $actor, app()->make(ReopenApprovedTask::class, [
                'reopenReason' => 'Concurrency-safe reviewer reconciliation.',
                'revisionDueDate' => now(config('app.timezone'))->addDays(3)->toDateString(),
                'reviewerId' => (int) $payload['reviewer_id'],
                'reworkInstructions' => 'Correct and resubmit the work.',
            ]), $context);
        } else {
            throw new InvalidArgumentException("Unsupported operation [{$operation}].");
        }

        return ['task_id' => $result->task->id, 'outcome' => $operation];
    }, 3);

    $result = ['result' => 'success', 'database' => DB::getDatabaseName(), 'details' => $details];
} catch (DuplicateTaskOperationException) {
    $result = ['result' => 'duplicate', 'database' => DB::getDatabaseName()];
} catch (AccountLifecycleException $exception) {
    $result = ['result' => 'conflict', 'status_code' => $exception->getStatusCode(), 'message' => $exception->getMessage()];
} catch (ValidationException $exception) {
    $result = ['result' => 'conflict', 'status_code' => 422, 'message' => collect($exception->errors())->flatten()->first()];
} catch (Throwable $exception) {
    $result = ['result' => 'error', 'exception' => get_class($exception), 'message' => $exception->getMessage()];
}

fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));
