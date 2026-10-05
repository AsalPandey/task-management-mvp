<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! preg_match('/^task_management_r43_r53_[a-z0-9_]+$/', DB::getDatabaseName()) || DB::getDriverName() !== 'mysql') {
    throw new RuntimeException('Disposable R53 MariaDB schema required.');
}
$size = DB::table('tasks')->count();
if (! in_array($size, [10000, 25000], true) || DB::table('task_histories')->exists()) {
    throw new RuntimeException('A fresh dense 10k or 25k fixture is required.');
}
$run = function (string $operation): array {
    $process = proc_open([PHP_BINARY, '-d', 'memory_limit=128M', __DIR__.'/r53-profile-administration.php', $operation],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException($operation.' failed: '.$errors.$output);
    }
    $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    if ($data['memory_limit'] !== '128M' || $data['process_peak_bytes'] > 64 * 1024 * 1024) {
        throw new RuntimeException('Administration exceeds the 64 MiB headroom budget.');
    }

    return $data;
};
$results = [];
$results[] = $run('role-demote');
if ($results[0]['status'] !== 409 || ($results[0]['hydrated']['App\\Models\\Task'] ?? 0) !== 0) {
    throw new RuntimeException('Dependent demotion must reject without task hydration.');
}
// All active tasks now require actual reconciliation; final states/provenance remain untouched.
$expected = DB::table('tasks')->whereNotIn('status', ['completed', 'cancelled'])->count();
DB::table('tasks')->whereNotIn('status', ['completed', 'cancelled'])->update(['reviewer_id' => 2]);
$results[] = $run('pm-replace');
if ($results[1]['status'] !== 200 || $results[1]['affected'] !== $expected
    || DB::table('tasks')->where('reviewer_id', 1)->where('lock_version', 2)->count() !== $expected
    || DB::table('task_histories')->where('action', 'reviewer_reassigned')->count() !== $expected
    || DB::table('task_events')->where('event_type', 'task.reviewer_reassigned')->count() !== $expected
    || DB::table('workflow_notification_intents')->where('transition', 'reviewer_reassigned')->count() !== $expected * 3
    || DB::table('workflow_notification_intents')->where('status', 'pending')->exists()) {
    throw new RuntimeException('Reconciliation state, version, provenance or required delivery mismatch.');
}
$results[] = $run('role-demote');
if ($results[2]['status'] !== 200 || ($results[2]['hydrated']['App\\Models\\Task'] ?? 0) !== 0) {
    throw new RuntimeException('Own-project reviewer demotion validation must succeed without hydration.');
}
echo json_encode(['classification' => 'PASS', 'size' => $size, 'affected' => $expected, 'results' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
