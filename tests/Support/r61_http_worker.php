<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! str_starts_with(DB::getDatabaseName(), 'task_management_r6_')) {
    throw new RuntimeException('Disposable R6 DB required');
}
$spec = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$actor = User::with('role')->findOrFail($spec['actor']);
auth()->guard('web')->setUser($actor);
$paused = false;
if (! empty($spec['pause'])) {
    DB::listen(function ($q) use ($spec, &$paused) {
        $match = match ($spec['pause']) {
            'email-validation' => str_contains($q->sql, '`users`') && in_array($spec['data']['email'], $q->bindings, true),
            'target-binding' => str_contains($q->sql, '`users`') && in_array($spec['target'], $q->bindings), 'locked-accounts' => str_contains($q->sql, '`users`') && str_contains($q->sql, 'for update'), 'locked-project' => str_contains($q->sql, '`projects`') && str_contains($q->sql, 'for update'), default => str_contains($q->sql, '`roles`') && str_contains($q->sql, 'for update')
        };
        if (! $paused && $match) {
            $paused = true;
            file_put_contents($spec['ready'], $q->sql);
            $end = microtime(true) + 25;
            while (! file_exists($spec['release']) && microtime(true) < $end) {
                usleep(10000);
            }if (! file_exists($spec['release'])) {
                throw new RuntimeException('Barrier timed out');
            }
        }
    });
}
$request = Request::create($spec['path'], $spec['method'], server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode($spec['data']));
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
file_put_contents($spec['result'], json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true), 'pause_reached' => $paused], JSON_PRETTY_PRINT));
$kernel->terminate($request, $response);
