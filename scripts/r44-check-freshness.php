<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ClientFreshness;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! preg_match('/^task_management_r43_[a-z0-9_]+$/', DB::getDatabaseName())) {
    throw new RuntimeException('Requires the disposable R43 performance fixture.');
}
$actor = User::where('email', match ($argv[1] ?? 'manager') {
    'member' => 'member001@r43.example.invalid', 'pm' => 'pm@r43.example.invalid', default => 'manager@r43.example.invalid',
})->firstOrFail();
$actor->load('role');
$queries = 0;
$models = 0;
DB::listen(function () use (&$queries): void {
    $queries++;
});
foreach ([Task::class, Project::class, User::class] as $model) {
    $model::retrieved(function () use (&$models): void {
        $models++;
    });
}
$start = hrtime(true);
$version = app(ClientFreshness::class)->version($actor);
$elapsed = (hrtime(true) - $start) / 1e6;
$peak = memory_get_peak_usage(true);
if ($queries > 8 || $models !== 0 || $peak > 96 * 1024 * 1024 || strlen($version) !== 64) {
    throw new RuntimeException('Freshness budget exceeded.');
}
echo json_encode(['role' => $argv[1] ?? 'manager', 'queries' => $queries, 'hydrated' => $models, 'wall_ms' => $elapsed, 'peak_bytes' => $peak, 'memory_limit' => ini_get('memory_limit')], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
