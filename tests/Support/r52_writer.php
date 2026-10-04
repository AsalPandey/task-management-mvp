<?php

use App\Models\User;
use App\Services\RequiredWorkflowNotifications;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! str_starts_with(DB::getDatabaseName(), 'task_management_r52_concurrency_')) {
    throw new RuntimeException('Disposable R52 MariaDB schema required.');
}
$spec = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
auth()->guard('web')->setUser(User::findOrFail($spec['actor']));
$paused = false;
$pause = function () use ($spec, &$paused): void {
    if ($paused) {
        return;
    }
    $paused = true;
    file_put_contents($spec['ready'], 'barrier');
    $until = microtime(true) + 25;
    while (! file_exists($spec['release']) && microtime(true) < $until) {
        usleep(10000);
    }
    if (! file_exists($spec['release'])) {
        throw new RuntimeException('R52 release timeout');
    }
};
if (($spec['pause'] ?? '') === 'before-accounts') {
    Event::listen(TransactionBeginning::class, function ($event) use ($pause) {
        if ($event->connection->transactionLevel() === 1) {
            $pause();
        }
    });
} elseif (($spec['pause'] ?? '') === 'reviewer-read') {
    Event::listen('eloquent.retrieved: App\Models\User', function ($user) use ($spec, $pause) {
        if (DB::transactionLevel() && (int) $user->id === (int) $spec['reviewer']) {
            $pause();
        }
    });
} else {
    DB::listen(function ($query) use ($spec, $pause) {
        if (($spec['pause'] ?? '') === 'locked-accounts' && str_contains($query->sql, '`users`') && str_contains($query->sql, 'for update')) {
            $pause();
        }
        if (($spec['pause'] ?? '') === 'unique' && str_contains($query->sql, '`projects`') && str_contains($query->sql, 'count(*)')) {
            $pause();
        }
        if (($spec['pause'] ?? '') === 'before-delivery' && DB::transactionLevel() === 0
            && str_contains($query->sql, '`workflow_notification_intents`') && str_starts_with($query->sql, 'select')) {
            $pause();
        }
        if (($spec['pause'] ?? '') === 'locked-intent' && str_contains($query->sql, '`workflow_notification_intents`') && str_contains($query->sql, 'for update')) {
            $pause();
        }
    });
}
if (isset($spec['retry'])) {
    app(RequiredWorkflowNotifications::class)->deliver($spec['retry']);
    file_put_contents($spec['result'], json_encode(['status' => 200]));
    exit;
}
$request = Request::create($spec['path'], $spec['method'], server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode($spec['data'], JSON_THROW_ON_ERROR));
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
file_put_contents($spec['result'], json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)], JSON_THROW_ON_ERROR));
$kernel->terminate($request, $response);
