<?php

use App\Models\Project;
use App\Models\ProjectHistory;
use App\Models\Task;
use App\Models\TaskApproval;
use App\Models\TaskEvent;
use App\Models\TaskHistory;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Read-only database oracle for the real browser suite. Never creates workflow data.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! str_starts_with((string) config('database.connections.mysql.database'), 'task_management_r41_browser')) {
    throw new RuntimeException('Browser oracle requires the disposable R41 browser database.');
}
echo json_encode([
    'users' => User::query()->get(['id', 'name', 'email', 'role_id', 'active']),
    'projects' => Project::all(),
    'memberships' => DB::table('project_user')->get(),
    'project_history' => ProjectHistory::all(),
    'tasks' => Task::query()->get()->map(fn ($task) => $task->getAttributes()),
    'events' => TaskEvent::query()->orderBy('task_id')->orderBy('sequence')->get(),
    'history' => TaskHistory::all(),
    'approvals' => TaskApproval::all(),
    'notifications' => DB::table('notifications')->count(),
], JSON_THROW_ON_ERROR);
