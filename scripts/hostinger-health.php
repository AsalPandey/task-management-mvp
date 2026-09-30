<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

try {
    require dirname(__DIR__).'/vendor/autoload.php';
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    if (! $app->environment('production') || config('app.debug') || ! filled(config('app.key'))) {
        throw new RuntimeException('Production configuration is incomplete.');
    }

    if (config('database.default') !== 'mysql') {
        throw new RuntimeException('Expected the isolated MySQL application database.');
    }

    DB::select('SELECT 1');
    fwrite(STDOUT, "Production boot and database connection verified.\n");
} catch (Throwable) {
    fwrite(STDERR, "Production configuration/database check failed. No secrets were displayed.\n");
    exit(1);
}
