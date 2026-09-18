<?php

use App\Services\TaskDeadlineNotificationDelivery;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $taskId, $type, $readyFile, $releaseFile] = $argv;
file_put_contents($readyFile, 'ready');
$deadline = microtime(true) + 10;
while (! file_exists($releaseFile) && microtime(true) < $deadline) {
    usleep(10_000);
}

$result = $app->make(TaskDeadlineNotificationDelivery::class)->deliver((int) $taskId, $type);
echo json_encode(['status' => $result->status], JSON_THROW_ON_ERROR);
