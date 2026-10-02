<?php

use App\Exceptions\TaskTransitionException;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskTransitionExecutor;
use App\TaskTransitions\ReopenApprovedTask;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! str_starts_with(DB::getDatabaseName(), 'task_management_r42_upgrade_') || DB::getDriverName() !== 'mysql') {
    throw new RuntimeException('Requires disposable MariaDB upgrade schema.');
}
if (DB::table('information_schema.tables')->where('table_schema', DB::getDatabaseName())->count() !== 0) {
    throw new RuntimeException('Requires empty upgrade schema.');
}
$baseline = array_values(array_map(fn ($file) => 'database/migrations/'.basename($file), array_filter(glob(database_path('migrations/*.php')), fn ($file) => basename($file) < '2026_07_19')));
if (Artisan::call('migrate', ['--path' => $baseline, '--force' => true]) !== 0) {
    throw new RuntimeException(Artisan::output());
}
Artisan::call('db:seed', ['--force' => true]);
$manager = DB::table('users')->insertGetId(['name' => 'Historical manager', 'email' => 'historical-manager@r42.test', 'password' => password_hash('dummy-legacy-fixture-password', PASSWORD_BCRYPT), 'role_id' => DB::table('roles')->where('name', 'manager')->value('id'), 'active' => true]);
$member = DB::table('users')->insertGetId(['name' => 'Historical member', 'email' => 'historical-member@r42.test', 'password' => password_hash('dummy-legacy-fixture-password', PASSWORD_BCRYPT), 'role_id' => DB::table('roles')->where('name', 'team_member')->value('id'), 'active' => true]);
$project = DB::table('projects')->insertGetId(['name' => 'Historical project', 'project_manager_id' => $manager, 'status' => 'active']);
DB::table('project_user')->insert(['project_id' => $project, 'user_id' => $member, 'added_by' => $manager]);
$ids = [];
foreach (['Not Started', 'In Progress', 'On Hold', 'Completed'] as $status) {
    $ids[$status] = DB::table('tasks')->insertGetId(['title' => 'Historical '.$status, 'description' => 'Preserve original work', 'project_id' => $project, 'assignee_id' => $member, 'priority' => 'Medium', 'status' => $status, 'progress' => $status === 'Completed' ? 100 : 25, 'due_date' => '2026-07-20', 'created_at' => '2026-07-01 12:00:00', 'updated_at' => '2026-07-20 12:00:00']);
}
if (Artisan::call('migrate', ['--force' => true]) !== 0) {
    throw new RuntimeException(Artisan::output());
}
if (Artisan::call('tasks:backfill-uids') !== 0) {
    throw new RuntimeException(Artisan::output());
}
$rows = Task::query()->whereIn('id', $ids)->orderBy('id')->get();
foreach ($rows as $task) {
    if (! $task->task_uid || $task->description !== 'Preserve original work') {
        throw new RuntimeException('Historical identity/content changed.');
    }
}
$completed = Task::findOrFail($ids['Completed']);
if (! $completed->legacy_completion_provenance || $completed->approval()->exists()) {
    throw new RuntimeException('Untruthful legacy completion provenance.');
}
$command = app()->make(ReopenApprovedTask::class, ['reopenReason' => 'Historical follow-up', 'revisionDueDate' => today()->addDays(3)->toDateString(), 'reviewerId' => $manager]);
try {
    app(TaskTransitionExecutor::class)->execute($completed, User::findOrFail($manager), $command);
    throw new RuntimeException('Legacy completion incorrectly reopened without approval.');
} catch (TaskTransitionException $exception) {
    if (! str_contains($exception->getMessage(), 'historical completion')) {
        throw $exception;
    }
}
Auth::guard('web')->setUser(User::findOrFail($manager));
$response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle(Request::create('/history'));
if ($response->getStatusCode() !== 200 || ! str_contains($response->getContent(), 'Historical completion: approval evidence unavailable')) {
    throw new RuntimeException('Historical completion is not readable with its truthful UI explanation.');
}
echo json_encode(['ids' => $ids, 'statuses' => $rows->map(fn ($task) => $task->machineState()->value), 'uid_count' => $rows->whereNotNull('task_uid')->count(), 'approval_count' => DB::table('task_approvals')->count(), 'event_count' => DB::table('task_events')->count(), 'legacy_completion' => $completed->legacy_completion_provenance, 'reopen' => 'restricted with truthful explanation; separately reviewed follow-up supported'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
