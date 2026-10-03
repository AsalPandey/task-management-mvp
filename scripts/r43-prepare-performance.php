<?php

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
require __DIR__.'/../tests/Support/r43_fixture.php';
$size = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT);
$projects = filter_var($argv[2] ?? 20, FILTER_VALIDATE_INT);
$notifications = filter_var($argv[3] ?? 0, FILTER_VALIDATE_INT);
if ($size === false || $size < 0 || $size > 50000 || $projects < 1 || $projects > 100 || $notifications < 0 || $notifications > 10000) {
    throw new RuntimeException('Invalid synthetic fixture dimensions.');
}
echo json_encode(r43Fixture($size, $projects, $notifications), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
