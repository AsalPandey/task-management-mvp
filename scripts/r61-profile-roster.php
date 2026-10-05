<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpFoundation\StreamedResponse;

if (! preg_match('/^task_management_r6_company(?:_\d+)?$/', DB::getDatabaseName())) {
    throw new RuntimeException('R6 company only');
}
$actor = User::where('email', 'manager@r6.example.invalid')->firstOrFail();
auth()->guard('web')->setUser($actor);
$queries = 0;
$sql = 0;
$models = [];
DB::listen(function ($q) use (&$queries, &$sql) {
    $queries++;
    $sql += $q->time;
});
Event::listen('eloquent.retrieved: *', function ($event, $data) use (&$models) {
    $models[$data[0]::class] = ($models[$data[0]::class] ?? 0) + 1;
});
$start = microtime(true);
$request = Request::create($argv[1]);
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
$body = $response->getContent();
if ($response instanceof StreamedResponse) {
    ob_start();
    $response->sendContent();
    $body = ob_get_clean();
}$data = ['route' => $argv[1], 'status' => $response->getStatusCode(), 'wall_ms' => round((microtime(true) - $start) * 1000, 2), 'sql_ms' => $sql, 'queries' => $queries, 'hydrated' => $models, 'bytes' => strlen($body), 'peak_bytes' => memory_get_peak_usage(true), 'memory_limit' => ini_get('memory_limit')];
echo json_encode($data, JSON_PRETTY_PRINT);
$kernel->terminate($request, $response);
