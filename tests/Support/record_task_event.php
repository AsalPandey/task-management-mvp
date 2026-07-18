<?php

use App\Models\Task;
use App\Services\TaskEventRecorder;
use App\ValueObjects\TaskOperationContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$taskId = (int) ($argv[1] ?? 0);
$worker = (string) ($argv[2] ?? 'worker');
$holdMilliseconds = (int) ($argv[3] ?? 0);
$readyFile = $argv[4] ?? null;

$event = DB::transaction(function () use ($taskId, $worker, $holdMilliseconds, $readyFile) {
    if ($holdMilliseconds > 0) {
        Task::withTrashed()->whereKey($taskId)->lockForUpdate()->firstOrFail();

        if ($readyFile) {
            file_put_contents($readyFile, 'locked');
        }

        usleep($holdMilliseconds * 1000);
    }

    $task = Task::withTrashed()->findOrFail($taskId);

    return app(TaskEventRecorder::class)->record(
        $task,
        TaskEventRecorder::UPDATED,
        TaskOperationContext::test(correlationId: "concurrency-{$worker}"),
        ['progress' => ['before' => 0, 'after' => (int) $worker]],
        ['worker' => $worker],
    );
});

fwrite(STDOUT, json_encode([
    'database' => DB::getDatabaseName(),
    'event_uid' => $event->event_uid,
    'sequence' => $event->sequence,
], JSON_THROW_ON_ERROR));
