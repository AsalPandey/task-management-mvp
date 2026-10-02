<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// Qualification only: requires an already-created EMPTY, explicitly disposable database.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! str_starts_with(DB::getDatabaseName(), 'task_management_r41_browser')
    || ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
    || ! app()->environment('production') || config('app.debug')
    || config('session.driver') !== 'database' || config('queue.default') !== 'database'
    || config('cache.default') !== 'database') {
    throw new RuntimeException('Requires an empty disposable R41 MariaDB database and production-like configuration.');
}
if ((int) DB::table('information_schema.tables')->where('table_schema', DB::getDatabaseName())->count() !== 0) {
    throw new RuntimeException('Refusing to prepare a nonempty database. Create a new disposable schema.');
}
foreach (['migrate' => ['--seed' => true, '--force' => true], 'optimize' => []] as $command => $arguments) {
    $exit = Artisan::call($command, $arguments);
    echo Artisan::output();
    if ($exit !== 0) {
        throw new RuntimeException('Browser preparation failed: '.$command);
    }
}
