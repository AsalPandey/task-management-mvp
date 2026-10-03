<?php

use App\Http\Controllers\NotificationController;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::getDriverName() !== 'mysql' || ! preg_match('/^task_management_r43_concurrency_[a-z0-9_]+$/', DB::getDatabaseName())) {
    throw new RuntimeException('Isolated R43 concurrency schema required.');
}
[$script,$mode,$userId,$ready,$release] = $argv;
file_put_contents($ready, 'ready');
$until = microtime(true) + 10;
while (! file_exists($release) && microtime(true) < $until) {
    usleep(10000);
}
if (! file_exists($release)) {
    throw new RuntimeException('Barrier timed out.');
}
if ($mode === 'read') {
    auth()->setUser(User::findOrFail($userId));
    app(NotificationController::class)->markAllAsRead();
} elseif ($mode === 'reminders') {
    if (Artisan::call('app:send-task-deadline-reminders') !== 0) {
        throw new RuntimeException('Command failed.');
    }
} else {
    throw new RuntimeException('Unknown worker mode.');
}
echo json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR);
