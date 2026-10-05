<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
use App\Models\CompanySetting;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

if (! preg_match('/^task_management_r6_company(?:_\d+)?$/', DB::getDatabaseName())) {
    throw new RuntimeException('R6 synthetic company required');
}
Artisan::call('migrate', ['--force' => true]);
Artisan::call('db:seed', ['--force' => true]);
if (User::exists()) {
    throw new RuntimeException('Empty DB required');
}
$m = User::factory()->create(['name' => 'R6 Company Manager', 'email' => 'manager@r6.example.invalid', 'role_id' => Role::where('name', 'manager')->value('id')]);
$pm = User::factory()->create(['name' => 'R6 Project Manager', 'role_id' => Role::where('name', 'project_manager')->value('id')]);
CompanySetting::create(['company_name' => 'R6 Aged Company', 'timezone' => 'Asia/Kathmandu', 'installed_at' => now()->subYears(3)]);
$employeeCount = (int) ($argv[1] ?? 1000);
$users = [];
for ($i = 1; $i <= $employeeCount; $i++) {
    $users[] = ['name' => 'Employee '.$i.($i % 17 === 0 ? ' renamed नेपाल' : ''), 'email' => 'employee'.$i.'@r6.example.invalid', 'password' => $m->password, 'active' => $i > 100, 'role_id' => Role::where('name', 'team_member')->value('id'), 'created_at' => now()->subYears(2), 'updated_at' => now()];
}foreach (array_chunk($users, 200) as $chunk) {
    DB::table('users')->insert($chunk);
}
$ids = User::where('id', '>', $pm->id)->pluck('id')->all();
$projects = [];
for ($i = 1; $i <= 12; $i++) {
    $p = Project::factory()->create(['name' => 'R6 Project '.$i, 'status' => $i === 1 ? 'archived' : 'active', 'project_manager_id' => $pm->id]);
    $projects[] = $p->id;
    $pivot = [];
    foreach ($ids as $id) {
        $pivot[] = ['project_id' => $p->id, 'user_id' => $id, 'added_by' => $m->id, 'created_at' => now()->subYears(2), 'updated_at' => now()];
    }foreach (array_chunk($pivot, 200) as $chunk) {
        DB::table('project_user')->insert($chunk);
    }
}
for ($offset = 0; $offset < 3400; $offset += 200) {
    $tasks = [];
    $subs = [];
    $approvals = [];
    $events = [];
    for ($i = $offset + 1; $i <= min($offset + 200, 3400); $i++) {
        $completed = $i <= 3000;
        $cancel = $i > 3300;
        $status = $completed ? 'completed' : ($cancel ? 'cancelled' : 'in_progress');
        $owner = $ids[($i - 1) % $employeeCount];
        if (! $completed && ! $cancel) {
            $owner = $ids[100 + ($i % ($employeeCount - 100))];
        }$created = now()->subDays(10 + ($i % $employeeCount));
        $finished = $created->copy()->addDays(3);
        $tasks[] = ['id' => $i, 'task_uid' => (string) Str::ulid(), 'title' => 'R6 Company Work '.$i, 'project_id' => $projects[$i % 12], 'assignee_id' => $owner, 'reviewer_id' => $m->id, 'created_by' => $m->id, 'assigned_by' => $m->id, 'priority' => 'Medium', 'status' => $status, 'progress' => $completed ? 100 : 25, 'due_date' => $created->copy()->addDays(5)->toDateString(), 'execution_due_date' => $created->copy()->addDays(5)->toDateString(), 'started_at' => $created, 'submitted_at' => $completed ? $finished : null, 'review_started_at' => $completed ? $finished : null, 'approved_at' => $completed ? $finished : null, 'approved_by' => $completed ? $m->id : null, 'completed_at' => $completed ? $finished : null, 'completed_by' => $completed ? $m->id : null, 'cancelled_at' => $cancel ? $finished : null, 'cancelled_by' => $cancel ? $m->id : null, 'lock_version' => $completed ? 5 : 2, 'created_at' => $created, 'updated_at' => $finished];
        if ($completed) {
            $subs[] = ['id' => $i, 'task_id' => $i, 'submitted_by' => $owner, 'submitted_at' => $finished, 'submission_note' => 'Synthetic retained delivery', 'created_at' => $finished, 'updated_at' => $finished];
            $approvals[] = ['task_id' => $i, 'submission_id' => $i, 'approved_by' => $m->id, 'assigned_reviewer_id' => $m->id, 'approved_at' => $finished, 'is_override' => false, 'created_at' => $finished, 'updated_at' => $finished];
        }
        $events[] = ['event_uid' => (string) Str::ulid(), 'task_id' => $i, 'sequence' => 1, 'event_type' => $completed ? 'task.completed' : ($cancel ? 'task.cancelled' : 'task.started'), 'actor_id' => $m->id, 'source' => 'r6_synthetic_company', 'occurred_at' => $finished, 'created_at' => $finished, 'updated_at' => $finished];
    }DB::table('tasks')->insert($tasks);
    if ($subs) {
        DB::table('task_submissions')->insert($subs);
    }if ($approvals) {
        DB::table('task_approvals')->insert($approvals);
    }DB::table('task_events')->insert($events);
}
for ($offset = 0; $offset < 5000; $offset += 200) {
    $rows = [];
    for ($i = $offset; $i < $offset + 200; $i++) {
        $rows[] = ['id' => (string) Str::uuid(), 'type' => 'R6SyntheticNotice', 'notifiable_type' => User::class, 'notifiable_id' => $m->id, 'data' => json_encode(['message' => 'Historical notice '.$i]), 'created_at' => now()->subDays($i % $employeeCount), 'updated_at' => now()];
    }DB::table('notifications')->insert($rows);
}
echo json_encode(['manager' => $m->id, 'employees' => $employeeCount, 'inactive' => 100, 'projects' => 12, 'tasks' => 3400, 'completed' => 3000, 'active' => 300, 'cancelled' => 100, 'notifications' => 5000, 'age_days_max' => 1009, 'synthetic_sparse_event_history' => true], JSON_PRETTY_PRINT);
