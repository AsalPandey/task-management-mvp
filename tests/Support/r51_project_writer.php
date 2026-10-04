<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::getDatabaseName() === '' || ! str_starts_with(DB::getDatabaseName(), 'task_management_r51_')) {
    throw new RuntimeException('R5 isolated race schema required.');
}
$spec = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$actor = User::findOrFail($spec['actor']);
auth()->guard('web')->setUser($actor);
$paused = false;
$transactionAttempts = 0;
Event::listen(TransactionBeginning::class, static function ($event) use (&$transactionAttempts): void {
    if ($event->connection->transactionLevel() === 1) {
        $transactionAttempts++;
    }
});
if (($spec['pause'] ?? '') === 'binding') {
    Event::listen('eloquent.retrieved: App\Models\Project', function ($project) use ($spec, &$paused): void {
        if (! $paused && (int) $project->id === (int) $spec['project']) {
            $paused = true;
            file_put_contents($spec['ready'], 'route-bound stale project');
            $until = microtime(true) + 20;
            while (! file_exists($spec['release']) && microtime(true) < $until) {
                usleep(10000);
            }
            if (! file_exists($spec['release'])) {
                throw new RuntimeException('Race release deadline expired.');
            }
        }
    });
} elseif (($spec['pause'] ?? '') === 'locked-project') {
    DB::listen(function ($query) use ($spec, &$paused): void {
        if (! $paused && str_contains($query->sql, '`projects`') && str_contains($query->sql, 'for update')) {
            $paused = true;
            file_put_contents($spec['ready'], 'project writer lock held');
            $until = microtime(true) + 20;
            while (! file_exists($spec['release']) && microtime(true) < $until) {
                usleep(10000);
            }
            if (! file_exists($spec['release'])) {
                throw new RuntimeException('Race release deadline expired.');
            }
        }
    });
}
if ($spec['fail_delete'] ?? false) {
    Project::deleting(static function (): void {
        throw new RuntimeException('R51 controlled delete failure');
    });
}
$request = Request::create($spec['path'], $spec['method'], server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode($spec['data'], JSON_THROW_ON_ERROR));
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
file_put_contents($spec['result'], json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true), 'pause_reached' => $paused, 'transaction_attempts' => $transactionAttempts], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
$kernel->terminate($request, $response);
