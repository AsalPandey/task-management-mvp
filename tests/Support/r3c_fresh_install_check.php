<?php

use App\Models\CompanySetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$fail = static function (string $message): never {
    fwrite(STDERR, "R3C fresh-install check failed: {$message}\n");
    exit(1);
};

$requiredTables = [
    'users', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs',
    'job_batches', 'failed_jobs', 'roles', 'permissions', 'permission_role',
    'company_settings', 'projects', 'project_user', 'project_histories', 'tasks',
    'completed_tasks', 'task_histories', 'notifications', 'task_events',
    'task_revision_cycles', 'task_submissions', 'task_approvals',
    'browser_push_subscriptions', 'browser_push_deliveries',
    'task_notification_deliveries',
];

foreach ($requiredTables as $table) {
    if (! Schema::hasTable($table)) {
        $fail("missing table {$table}");
    }
}

foreach (['task_uid', 'lock_version', 'active_revision_cycle_id', 'deadline_reminder_generation', 'overdue_notification_generation'] as $column) {
    if (! Schema::hasColumn('tasks', $column)) {
        $fail("missing tasks.{$column}");
    }
}

foreach (['event_uid', 'sequence', 'correlation_id', 'operation_key'] as $column) {
    if (! Schema::hasColumn('task_events', $column)) {
        $fail("missing task_events.{$column}");
    }
}

if (Role::query()->count() !== 3 || Permission::query()->count() < 10) {
    $fail('system roles or permissions are incomplete');
}

if (User::query()->count() !== 1) {
    $fail('bootstrap must create exactly one user');
}

$manager = User::query()->with('role')->sole();
if (! $manager->hasRole('manager') || $manager->email !== getenv('INITIAL_MANAGER_EMAIL')) {
    $fail('the initial user is not the configured manager');
}

if (! Hash::check((string) getenv('R3C_EXPECTED_MANAGER_PASSWORD'), $manager->password)) {
    $fail('the initial manager password was not hashed correctly or was changed');
}

if (! Auth::attempt(['email' => $manager->email, 'password' => (string) getenv('R3C_EXPECTED_MANAGER_PASSWORD')])) {
    $fail('the initial manager cannot authenticate through the application guard');
}
Auth::logout();

Cache::put('r3c-fresh-install-probe', 'available', 60);
if (Cache::get('r3c-fresh-install-probe') !== 'available') {
    $fail('the configured cache store is not operational');
}
Cache::forget('r3c-fresh-install-probe');

Queue::connection()->pushRaw('{"displayName":"r3c-fresh-install-probe"}', 'r3c-probe');
if (Queue::connection()->size('r3c-probe') !== 1) {
    $fail('the configured queue is not operational');
}
Queue::connection()->clear('r3c-probe');

$session = app('session')->driver();
$session->start();
$session->put('r3c-fresh-install-probe', 'available');
$session->save();
if (! DB::table('sessions')->where('id', $session->getId())->exists()) {
    $fail('the configured session store is not operational');
}
DB::table('sessions')->where('id', $session->getId())->delete();

if (User::query()->where('email', 'like', 'demo.%')->exists()) {
    $fail('demo users were installed');
}

if (CompanySetting::query()->count() !== 1 || ! CompanySetting::isInstalled()) {
    $fail('company settings are not installed exactly once');
}

foreach (['tasks_task_uid_unique', 'tasks_created_at_index', 'tasks_status_completed_at_index'] as $index) {
    $indexes = collect(Schema::getIndexes('tasks'))->pluck('name');
    if (! $indexes->contains($index)) {
        $fail("missing tasks index {$index}");
    }
}

echo "R3C fresh-install structure and bootstrap verified.\n";
