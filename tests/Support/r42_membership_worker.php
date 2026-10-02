<?php

use App\Models\Project;
use App\Models\User;
use App\Services\ProjectMembershipService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! str_starts_with(DB::getDatabaseName(), 'task_management_r42_membership_')) {
    throw new RuntimeException('Requires disposable membership database.');
}
[$script, $projectId, $memberId, $actorId, $barrier] = $argv;
$deadline = microtime(true) + 10;
while (! is_file($barrier)) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Membership start barrier timed out.');
    }
    usleep(10000);
}
$changed = app(ProjectMembershipService::class)->change(Project::findOrFail($projectId), (int) $memberId, User::findOrFail($actorId), true);
echo json_encode(['changed' => $changed], JSON_THROW_ON_ERROR);
