<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! str_starts_with(DB::getDatabaseName(), 'task_management_r41_browser')
    || ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
    || config('cache.default') !== 'database') {
    throw new RuntimeException('Freshness budget isolation requires the disposable R41 browser database.');
}

// Only clear the named freshness limiter for these disposable fixture accounts.
// Do not flush application cache or disable middleware/security assertions.
foreach (User::query()->whereIn('email', ['manager@r41.example.invalid', 'a@r41.example.invalid', 'pm@r41.example.invalid'])->pluck('id') as $id) {
    RateLimiter::clear(md5('client-freshness'.'user:'.$id));
}

echo "Disposable browser freshness budgets isolated.\n";
