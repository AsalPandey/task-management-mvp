<?php

use App\Exceptions\DuplicateTaskOperationException;
use App\Exceptions\StaleTaskEditException;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskLifecycleService;
use App\Services\TaskTransitionExecutor;
use App\TaskTransitions\ApproveTask;
use App\TaskTransitions\CancelTask;
use App\TaskTransitions\ChangeTaskDeadline;
use App\TaskTransitions\ReassignTaskReviewer;
use App\TaskTransitions\ReopenApprovedTask;
use App\TaskTransitions\SubmitTask;
use App\ValueObjects\TaskOperationContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$operation = (string) ($argv[1] ?? 'update');
$payload = json_decode($argv[2] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
$holdMilliseconds = (int) ($argv[3] ?? 0);
$readyFile = $argv[4] ?? null;
$startFile = $argv[5] ?? null;

if ($startFile) {
    $deadline = microtime(true) + 5;
    while (! file_exists($startFile) && microtime(true) < $deadline) {
        usleep(5_000);
    }
}

try {
    $details = DB::transaction(function () use ($operation, $payload, $holdMilliseconds, $readyFile) {
        $actor = User::query()->findOrFail((int) $payload['actor_id']);
        $taskQuery = Task::query();

        if ($readyFile) {
            $taskQuery->lockForUpdate();
        }

        $task = $taskQuery->findOrFail((int) $payload['task_id']);

        if ($readyFile) {
            file_put_contents($readyFile, 'ready');
        }
        if ($holdMilliseconds > 0) {
            usleep($holdMilliseconds * 1000);
        }

        if ($operation === 'update') {
            $context = TaskOperationContext::test(
                $actor->id,
                $payload['correlation_id'] ?? null,
                expectedVersion: isset($payload['expected_version']) ? (int) $payload['expected_version'] : null,
            );

            $updated = app(TaskLifecycleService::class)->update(
                $task,
                $payload['data'] ?? [],
                $actor,
                $context,
            );

            return [
                'task_id' => $updated->id,
                'outcome' => 'updated',
                'lock_version' => $updated->lock_version,
                'title' => $updated->title,
                'comments' => $updated->comments,
            ];
        }

        if (in_array($operation, ['submit', 'deadline', 'reassign', 'cancel', 'approve', 'reopen'], true)) {
            $context = TaskOperationContext::test(
                $actor->id,
                $payload['correlation_id'] ?? null,
                expectedVersion: isset($payload['expected_version']) ? (int) $payload['expected_version'] : null,
            );

            $command = match ($operation) {
                'submit' => app()->make(SubmitTask::class, [
                    'submissionNote' => $payload['submission_note'] ?? 'Concurrent submission',
                ]),
                'deadline' => app()->make(ChangeTaskDeadline::class, [
                    'deadlineType' => $payload['deadline_type'] ?? 'execution',
                    'dueDate' => $payload['due_date'],
                    'reason' => $payload['reason'] ?? null,
                ]),
                'reassign' => app()->make(ReassignTaskReviewer::class, [
                    'reviewerId' => (int) $payload['reviewer_id'],
                    'reason' => $payload['reason'] ?? null,
                ]),
                'cancel' => app()->make(CancelTask::class, [
                    'cancellationReason' => $payload['cancellation_reason'],
                    'expectedState' => $task->machineState()->value,
                ]),
                'approve' => app()->make(ApproveTask::class, [
                    'approvalComment' => $payload['approval_comment'] ?? null,
                ]),
                'reopen' => app()->make(ReopenApprovedTask::class, [
                    'reopenReason' => $payload['reopen_reason'],
                    'revisionDueDate' => $payload['revision_due_date'],
                    'reviewerId' => isset($payload['reviewer_id']) ? (int) $payload['reviewer_id'] : null,
                    'reworkInstructions' => $payload['rework_instructions'] ?? null,
                ]),
            };

            $result = app(TaskTransitionExecutor::class)->execute(
                $task,
                $actor,
                $command,
                $context,
            );

            return [
                'task_id' => $result->task->id,
                'outcome' => $operation,
                'lock_version' => $result->task->lock_version,
                'state' => $result->task->machineState()->value,
            ];
        }

        throw new InvalidArgumentException("Unsupported operation [{$operation}].");
    }, 3);

    $result = [
        'result' => 'success',
        'database' => DB::getDatabaseName(),
        'details' => $details,
    ];
} catch (StaleTaskEditException $exception) {
    $result = [
        'result' => 'conflict',
        'status_code' => 409,
        'message' => $exception->getMessage(),
        'current_version' => $exception->currentVersion,
        'expected_version' => $exception->expectedVersion,
    ];
} catch (DuplicateTaskOperationException) {
    $result = [
        'result' => 'duplicate',
        'status_code' => 409,
    ];
} catch (ValidationException $exception) {
    $result = [
        'result' => 'validation_error',
        'status_code' => 422,
        'message' => collect($exception->errors())->flatten()->first(),
    ];
} catch (Throwable $exception) {
    $result = [
        'result' => 'error',
        'exception' => get_class($exception),
        'message' => $exception->getMessage(),
    ];
}

fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));
