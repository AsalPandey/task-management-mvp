<?php

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! preg_match('/^task_management_r43_[a-z0-9_]+$/', DB::getDatabaseName()) || ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
    throw new RuntimeException('Disposable R43 MariaDB schema required.');
}
$size = DB::table('tasks')->count();
$projects = DB::table('projects')->count();
if ($size > 50000 || ! $projects) {
    throw new RuntimeException('Expected a prepared qualification fixture.');
}
$check = function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$expected = function (string $role, ?int $member = null, bool $cohort = false) use ($size, $projects): array {
    $states = ['not_started' => 'Not Started', 'in_progress' => 'In Progress', 'on_hold' => 'On Hold', 'submitted' => 'Submitted', 'in_review' => 'In Review', 'revision_requested' => 'Revision Requested', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
    $result = ['totalTasks' => 0, 'totalActiveTasks' => 0, 'totalCompletedTasks' => 0, 'cancelledTasks' => 0, 'inProgressTasks' => 0, 'overdueTasks' => 0, 'completionEvents' => 0, 'cancellationEvents' => 0, 'dueSoonTasks' => 0, 'executionTaskCount' => 0, 'reviewQueueTaskCount' => 0];
    $counts = [];
    $creation = [];
    $priority = [];
    $progress = 0;
    for ($i = 0; $i < $size; $i++) {
        $owner = intdiv($i, 8) % 40;
        $project = intdiv($i, 8) % $projects;
        if ($role === 'pm' && $project % 2 !== 0 || $role === 'member' && $owner !== 0 || $member !== null && $owner !== $member) {
            continue;
        }
        $state = array_keys($states)[$i % 8];
        $final = $i % 8 >= 6;
        $result['completionEvents'] += $state === 'completed';
        $result['cancellationEvents'] += $state === 'cancelled';
        $date = CarbonImmutable::parse('2026-10-03')->subDays($i % 30)->format('M d');
        $creation[$date] = ($creation[$date] ?? 0) + 1;
        if ($cohort && $i % 30 > 2) {
            continue;
        }
        $result['totalTasks']++;
        $result['totalActiveTasks'] += ! $final;
        $result['totalCompletedTasks'] += $state === 'completed';
        $result['cancelledTasks'] += $state === 'cancelled';
        $result['inProgressTasks'] += $state === 'in_progress';
        $result['overdueTasks'] += ! $final && $i % 7 < 2;
        $result['dueSoonTasks'] += ! $final && $i % 7 === 3;
        $result['executionTaskCount'] += in_array($i % 8, [0, 1, 2, 5], true);
        $result['reviewQueueTaskCount'] += in_array($i % 8, [3, 4], true);
        $label = $states[$state];
        $counts[$label] = ($counts[$label] ?? 0) + 1;
        if (! $final) {
            $p = ['Low', 'Medium', 'High'][$i % 3];
            $priority[$p] = ($priority[$p] ?? 0) + 1;
            $progress += $i % 100;
        }
    }
    $result['completionRate'] = $result['totalTasks'] - $result['cancelledTasks'] ? round($result['totalCompletedTasks'] / ($result['totalTasks'] - $result['cancelledTasks']) * 100, 1) : 0.0;
    $result['avgProgress'] = $result['totalActiveTasks'] ? round($progress / $result['totalActiveTasks']) : 0;
    ksort($counts);
    ksort($priority);

    return $result + ['statusCounts' => $counts, 'creationTrend' => $creation, 'priorityCounts' => $priority];
};
$run = function (string $operation, string $role = 'manager') use ($check): array {
    $process = proc_open([PHP_BINARY, '-d', 'memory_limit=128M', __DIR__.'/r43-profile.php', $operation, $role], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    $check($code === 0, "{$operation}/{$role} failed: {$err} {$out}");
    $data = json_decode($out, true, flags: JSON_THROW_ON_ERROR);
    $check($data['memory_limit'] === '128M' && $data['process_peak_bytes'] <= 96 * 1024 * 1024, "{$operation} exceeds the 96 MiB measured-peak guard.");

    return $data;
};
$results = [];
foreach (['manager', 'pm', 'member'] as $role) {
    $data = $run('analytics', $role);
    $oracle = $expected($role);
    $values = $data['values'];
    foreach ($oracle as $key => $value) {
        if ($key === 'creationTrend') {
            foreach ($values[$key] as $date => $count) {
                $check($count === ($value[$date] ?? 0), 'Creation trend mismatch');
            }
        } elseif ($key === 'statusCounts' || $key === 'priorityCounts') {
            $actual = $values[$key];
            ksort($actual);
            $check($actual === $value, "{$role} {$key} mismatch");
        } else {
            $check((is_float($value) ? (float) $values[$key] === $value : $values[$key] === $value), "{$role} {$key} mismatch: ".json_encode([$values[$key], $value]));
        }
    }
    $check($values['completionTrend']['Oct 03'] === $oracle['completionEvents'], 'Completion event trend mismatch');
    $check($values['overdueTrend']['Oct 03'] === $oracle['overdueTasks'], 'Overdue trend mismatch');
    $check(($data['hydrated']['App\\Models\\Task'] ?? 0) === 0 && $data['queries'] <= 10, 'Aggregate query/hydration guard');
    $results[] = $data;
    $filtered = $run('analytics-cohort', $role);
    $cohortOracle = $expected($role, cohort: true);
    foreach (['totalTasks', 'totalActiveTasks', 'totalCompletedTasks', 'cancelledTasks', 'inProgressTasks', 'overdueTasks', 'completionEvents', 'cancellationEvents'] as $key) {
        $check($filtered['values'][$key] === $cohortOracle[$key], 'Filtered cohort/event oracle mismatch for '.$role.'/'.$key);
    }
    foreach ($filtered['values']['teamPerformance'] as $memberRow) {
        $memberNumber = (int) substr($memberRow['name'], -3) - 1;
        $memberOracle = $expected($role, $memberNumber, true);
        $check($memberRow['total'] === $memberOracle['totalTasks'] && $memberRow['completed'] === $memberOracle['totalCompletedTasks']
            && $memberRow['overdue'] === $memberOracle['overdueTasks'], 'Per-member cohort DTO oracle mismatch');
    }
    $check($filtered['queries'] <= 10 && ($filtered['hydrated']['App\\Models\\Task'] ?? 0) === 0, 'Filtered report budget');
    $results[] = $filtered;
    $dashboard = $run('dashboard', $role);
    $dashboardOracle = $expected($role);
    $v = $dashboard['values'];
    $activeKey = $role === 'member' ? 'currentTotalTasks' : 'currentActiveCount';
    $overdueKey = $role === 'member' ? 'todayOverdueTasks' : 'totalOverdueCount';
    $check($v[$activeKey] === $dashboardOracle['totalActiveTasks'] && $v[$overdueKey] === $dashboardOracle['overdueTasks'], 'Dashboard oracle mismatch');
    $check($dashboard['queries'] <= 80 && ($dashboard['hydrated']['App\\Models\\Task'] ?? 0) <= 40 && $dashboard['response_bytes'] <= 200000, 'Dashboard budget');
    foreach ($v as $key => $value) {
        if (str_ends_with($key, 'PreviewRows')) {
            $check($value <= 10, 'Unbounded dashboard preview');
        }
    }
    $results[] = $dashboard;
}
foreach (['projects' => [64, 16, 400000], 'tasks' => [80, 45, 400000], 'team' => [64, 16, 160000], 'notifications' => [150, 60, 180000], 'analytics-page' => [64, 16, 180000], 'member-analytics' => [64, 26, 120000], 'csv' => [30, 0, 50000], 'print' => [30, 0, 40000], 'mark-all' => [1, 0, 0]] as $operation => $budget) {
    $data = $run($operation);
    // Page chrome may authorize at most eight task notifications; aggregate services and CSV have no task retrieval.
    $check($data['queries'] <= $budget[0] && ($data['hydrated']['App\\Models\\Task'] ?? 0) <= $budget[1] && $data['response_bytes'] <= $budget[2], "{$operation} read budget");
    if ($operation === 'mark-all') {
        $check(($data['statements']['update'] ?? 0) === 1, 'Bulk update budget');
    }
    if ($operation === 'projects') {
        $check($data['values']['projectsChildTaskRelations'] === 0 && $data['values']['projectsPageRows'] <= 12, 'Project child/pagination budget');
    }
    if ($operation === 'analytics-page' || $operation === 'print') {
        $check($data['values']['totalTasks'] === $size, 'HTTP aggregate oracle');
    }
    if ($operation === 'member-analytics') {
        $check($data['values']['totalTasks'] === $expected('manager', 0)['totalTasks'], 'Member HTTP aggregate oracle');
    }
    $results[] = $data;
}
foreach (['reminders', 'overdue'] as $operation) {
    $data = $run($operation);
    $check($data['queries'] <= 60000, 'Bounded per-candidate scheduler budget');
    $results[] = $data;
    $repeat = $run($operation);
    $check(($repeat['hydrated']['App\\Models\\Task'] ?? 0) === 0 && $repeat['queries'] <= 50, 'Consumed-generation repeat budget');
    $check(str_contains($repeat['values']['command_output'], 'Sent 0 '), 'Duplicate scheduler delivery');
    $results[] = $repeat;
}
echo json_encode(['classification' => 'PASS', 'size' => $size, 'database' => DB::getDatabaseName(), 'fresh_processes' => count($results), 'results' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
