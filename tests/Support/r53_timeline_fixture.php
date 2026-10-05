<?php

use App\Models\Task;
use App\Services\TaskEventRecorder;
use App\ValueObjects\TaskOperationContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! str_starts_with(DB::getDatabaseName(), 'task_management_r41_browser_')) {
    throw new RuntimeException('Disposable R53 browser schema required.');
}
$task = Task::where('title', 'R53 Traversable History')->findOrFail((int) $argv[1]);
$count = (int) ($argv[2] ?? 205);
if (! in_array($count, [1, 205], true)) {
    throw new RuntimeException('Unsupported fixture count.');
}
for ($i = 0; $i < $count; $i++) {
    app(TaskEventRecorder::class)->record($task, TaskEventRecorder::UPDATED,
        TaskOperationContext::test($task->created_by), [], ['reason_reference' => 'r53-private-management-reference']);
}
echo json_encode(['events' => $task->events()->count()]);
