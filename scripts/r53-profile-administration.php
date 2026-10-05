<?php

use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountLifecycleService;
use App\Services\ProjectManagerReplacementService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! preg_match('/^task_management_r43_r53_[a-z0-9_]+$/', DB::getDatabaseName())) {
    throw new RuntimeException('Disposable R53 schema required');
}
$operation = $argv[1];
if (! in_array($operation, ['pm-replace', 'role-demote'], true)) {
    throw new RuntimeException('Unknown administration operation.');
}
$user = User::with('role')->where('email', 'manager@r43.example.invalid')->firstOrFail();
auth()->guard('web')->setUser($user);
$queries = 0;
$sql = 0;
$models = [];
$affected = 0;
DB::listen(function ($q) use (&$queries, &$sql) {
    $queries++;
    $sql += $q->time;
});
Event::listen('eloquent.retrieved: *', function ($event, $data) use (&$models) {
    $models[$data[0]::class] = ($models[$data[0]::class] ?? 0) + 1;
});
$start = microtime(true);
$transactionWorkMs = null;
$status = 200;
try {
    DB::transaction(function () use ($operation, $user, &$affected, &$transactionWorkMs, $start, $argv) {
        User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        if ($operation === 'pm-replace') {
            $project = Project::orderBy('id')->lockForUpdate()->firstOrFail();
            app(ProjectManagerReplacementService::class)->reconcile($project, $project->project_manager_id, $user->id, $user);
            $project->update(['project_manager_id' => $user->id]);
        } else {
            app(AccountLifecycleService::class)->assertCanChangeRole($user, Role::where('name', 'project_manager')->value('id'), $user);
        }
        $affected = DB::table('task_histories')->where('action', 'reviewer_reassigned')->count();
        // Excludes commit I/O and synchronous after-commit delivery. The single
        // atomic transaction still retains its acquired locks throughout this work.
        $transactionWorkMs = round((microtime(true) - $start) * 1000, 2);
        if (($argv[2] ?? '') === 'rollback') {
            DB::rollBack();
        }
    });
} catch (Throwable $e) {
    $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
    $error = $e->getMessage();
}
echo json_encode(['operation' => $operation, 'status' => $status, 'error' => $error ?? null, 'memory_limit' => ini_get('memory_limit'), 'wall_ms' => round((microtime(true) - $start) * 1000, 2), 'transaction_work_ms' => $transactionWorkMs, 'sql_ms' => round($sql, 2), 'queries' => $queries, 'hydrated' => $models, 'affected' => $affected, 'process_peak_bytes' => memory_get_peak_usage(true)], JSON_PRETTY_PRINT).PHP_EOL;
