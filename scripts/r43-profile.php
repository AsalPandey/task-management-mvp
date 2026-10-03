<?php

use App\Http\Controllers\NotificationController;
use App\Models\User;
use App\Services\TaskAnalyticsService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! preg_match('/^task_management_r43_[a-z0-9_]+$/', DB::getDatabaseName())) {
    throw new RuntimeException('Isolated R43 schema required.');
}
Carbon::setTestNow(CarbonImmutable::parse('2026-10-03 12:00:00', config('app.timezone')));
$operation = $argv[1] ?? 'analytics';
$role = $argv[2] ?? 'manager';
$viewer = User::query()->where('email', match ($role) {
    'pm' => 'pm@r43.example.invalid', 'member' => 'member001@r43.example.invalid', default => 'manager@r43.example.invalid'
})->firstOrFail();
$viewer->load('role');
auth()->guard('web')->setUser($viewer);
$queries = 0;
$sqlMs = 0;
$statements = [];
$hydrated = [];
$patterns = [];
DB::listen(function ($query) use (&$queries, &$sqlMs, &$statements, &$patterns): void {
    $queries++;
    $sqlMs += $query->time;
    $verb = strtolower(strtok(ltrim($query->sql), ' '));
    $statements[$verb] = ($statements[$verb] ?? 0) + 1;
    $hash = hash('sha256', $query->sql);
    $patterns[$hash] = ($patterns[$hash] ?? 0) + 1;
});
Event::listen('eloquent.retrieved: *', function ($event, $data) use (&$hydrated): void {
    $class = $data[0]::class;
    $hydrated[$class] = ($hydrated[$class] ?? 0) + 1;
});
$before = memory_get_usage(true);
$start = microtime(true);
$bytes = 0;
$values = [];
$status = 200;
if (in_array($operation, ['analytics', 'analytics-cohort'], true)) {
    $report = app(TaskAnalyticsService::class)->report($viewer,
        $operation === 'analytics-cohort' ? '2026-10-01' : null,
        $operation === 'analytics-cohort' ? '2026-10-03' : null);
    foreach (['totalTasks', 'totalActiveTasks', 'totalCompletedTasks', 'cancelledTasks', 'inProgressTasks', 'overdueTasks', 'completionRate', 'completionEvents', 'cancellationEvents'] as $key) {
        $values[$key] = $report[$key];
    }
    foreach (['statusCounts', 'priorityCounts', 'creationTrend', 'completionTrend', 'overdueTrend', 'teamPerformance', 'avgProgress', 'dueSoonTasks', 'executionTaskCount', 'reviewQueueTaskCount'] as $key) {
        $values[$key] = $report[$key];
    }
} elseif ($operation === 'mark-all') {
    app(NotificationController::class)->markAllAsRead();
} elseif (in_array($operation, ['reminders', 'overdue'], true)) {
    $status = Artisan::call($operation === 'reminders' ? 'app:send-task-deadline-reminders' : 'app:send-overdue-task-notifications');
    $values['command_output'] = trim(Artisan::output());
} else {
    $path = match ($operation) {
        'dashboard' => $role === 'member' ? '/team-dashboard' : '/manager', 'projects' => '/projects', 'tasks' => '/tasks', 'team' => '/team-management', 'notifications' => '/notifications/all', 'member-analytics' => '/team-management/'.User::where('email', 'member001@r43.example.invalid')->value('id').'/analytics?dateFrom=2026-09-01&dateTo=2026-10-03', 'csv' => '/analytics/export/csv?dateFrom=2026-09-01&dateTo=2026-10-03', 'print' => '/analytics/print?dateFrom=2026-09-01&dateTo=2026-10-03', 'analytics-page' => '/analytics?dateFrom=2026-09-01&dateTo=2026-10-03', default => throw new RuntimeException('Unknown operation')
    };
    $request = Request::create($path);
    $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
    $status = $response->getStatusCode();
    if (method_exists($response, 'getOriginalContent') && $response->getOriginalContent() instanceof View) {
        $data = $response->getOriginalContent()->getData();
        foreach (['totalTasks', 'totalActiveTasks', 'totalCompletedTasks', 'cancelledTasks', 'completionRate', 'tasksCreated', 'completionEvents', 'cancellationEvents', 'statusCounts', 'productivity', 'overdueTrend', 'currentActiveCount', 'todayTotalTasks', 'todayCompletedCount', 'totalOverdueCount', 'todayStatusCounts', 'currentTotalTasks', 'todayCompletedTasks', 'todayOverdueTasks', 'currentOverdueTasks'] as $key) {
            if (isset($data[$key]) && ! $data[$key] instanceof Collection) {
                $values[$key] = $data[$key];
            }
        }
        foreach (['todayOverdueTasks', 'todayOverdueList', 'recentTasks', 'recentCompletedHistory', 'upcoming', 'recentActivity'] as $key) {
            if (isset($data[$key]) && $data[$key] instanceof Collection) {
                $values[$key.'PreviewRows'] = $data[$key]->count();
            }
        }
        foreach (['tasks', 'projects', 'users', 'notifications'] as $key) {
            if (isset($data[$key]) && $data[$key] instanceof LengthAwarePaginator) {
                $values[$key.'PageRows'] = $data[$key]->count();
            }
        }
        if (isset($data['projects']) && $data['projects'] instanceof LengthAwarePaginator) {
            $values['projectsChildTaskRelations'] = $data['projects']->getCollection()->filter(fn ($project) => $project->relationLoaded('tasks'))->count();
        }
    }
    if ($response instanceof StreamedResponse) {
        ob_start();
        $response->sendContent();
        $bytes = strlen(ob_get_clean());
    } else {
        $bytes = strlen($response->getContent());
    }
}
$result = ['operation' => $operation, 'role' => $role, 'database' => DB::getDatabaseName(), 'memory_limit' => ini_get('memory_limit'), 'status' => $status, 'wall_ms' => round((microtime(true) - $start) * 1000, 2), 'sql_ms' => round($sqlMs, 2), 'queries' => $queries, 'statements' => $statements, 'duplicate_query_executions' => array_sum(array_map(fn ($count) => max(0, $count - 1), $patterns)), 'memory_delta_bytes' => memory_get_usage(true) - $before, 'process_peak_bytes' => memory_get_peak_usage(true), 'response_bytes' => $bytes, 'hydrated' => $hydrated, 'values' => $values];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
if ($status !== 200 && ! ($status === 0 && in_array($operation, ['reminders', 'overdue'], true))) {
    exit(1);
}
