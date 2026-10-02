<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! str_starts_with(DB::getDatabaseName(), 'task_management_r41_browser')) {
    throw new RuntimeException('Requires disposable browser schema.');
}
// Only historical date travel is performed; tasks, users, memberships and states come from UI.
foreach (['R42 Past A' => '2026-01-01 00:00:00', 'R42 Past B' => '2026-01-01 23:59:59'] as $title => $createdAt) {
    if (DB::table('tasks')->where('title', $title)->count() !== 1) {
        throw new RuntimeException('Missing unique UI-created date fixture.');
    }
    DB::table('tasks')->where('title', $title)->update(['created_at' => $createdAt]);
}
echo json_encode(['date_only_updates' => 2], JSON_THROW_ON_ERROR);
