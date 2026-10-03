<?php

use App\Models\User;
use App\Services\TaskAnalyticsService;
use App\Services\TaskDeadlineCandidates;
use App\Services\TaskDeadlineNotificationDelivery;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! preg_match('/^task_management_r43_[a-z0-9_]+$/', DB::getDatabaseName())) {
    throw new RuntimeException('Disposable schema required.');
}
Carbon::setTestNow('2026-10-03 12:00:00');
$viewer = User::where('email', 'manager@r43.example.invalid')->firstOrFail();
auth()->setUser($viewer);
$queries = [];
DB::listen(function ($query) use (&$queries): void {
    if (str_starts_with(strtolower($query->sql), 'select')) {
        $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
    }
});
app(TaskAnalyticsService::class)->report($viewer, '2026-09-01', '2026-10-03');
$response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle(Request::create('/projects'));
app(TaskDeadlineCandidates::class)->each(TaskDeadlineNotificationDelivery::OVERDUE, fn ($id) => null);
$plans = [];
foreach ($queries as $query) {
    if (str_contains($query['sql'], 'COUNT(*)') || str_contains($query['sql'], 'active_tasks_count') || str_contains($query['sql'], 'deadline_kind')) {
        $plans[] = $query + ['explain' => DB::select('EXPLAIN '.$query['sql'], $query['bindings'])];
    }
}
echo json_encode(['database' => DB::getDatabaseName(), 'plans' => $plans], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
