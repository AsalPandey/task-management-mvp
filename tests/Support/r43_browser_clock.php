<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! preg_match('/^task_management_r43_[a-z0-9_]+$/', DB::getDatabaseName())) {
    throw new RuntimeException('Disposable R43 browser fixture required.');
}

// Read only the effective company clock; never use the application's report as
// the test oracle or change the server clock to keep yesterday's count green.
echo now(config('app.timezone'))->toDateString(), PHP_EOL;
