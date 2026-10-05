<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! preg_match('/^task_management_r6_company(?:_\d+)?$/', DB::getDatabaseName())) {
    throw new RuntimeException('Private R61 roster fixture required.');
}
$results = [];
foreach (['projects', 'tasks', 'manager', 'projects/2/members', 'projects/2/candidates?search=Employee%20999'] as $route) {
    $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', base_path('scripts/r61-profile-roster.php'), '/'.$route], base_path(), timeout: 60);
    $process->mustRun();
    $data = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    if ($data['status'] !== 200 || $data['peak_bytes'] > 64 * 1024 * 1024 || $data['bytes'] > 512 * 1024
        || ($data['hydrated']['App\\Models\\User'] ?? 0) > 800 || $data['queries'] > 40) {
        throw new RuntimeException('Roster read exceeded measured headroom/response/hydration/query budget: '.json_encode($data));
    }
    $results[] = $data;
}
echo json_encode(['classification' => 'PASS', 'employee_count' => DB::table('users')->count() - 2, 'results' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
