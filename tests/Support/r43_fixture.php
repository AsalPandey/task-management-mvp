<?php

use App\Models\CompanySetting;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Synthetic qualification data only; never executes against an ordinary database. */
function r43Fixture(int $size, int $projectCount = 20, int $notificationCount = 0): array
{
    if (! preg_match('/^task_management_r43_[a-z0-9_]+$/', DB::getDatabaseName())
        || ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
        throw new RuntimeException('R43 fixtures require an explicitly disposable MariaDB schema.');
    }
    if (DB::table('tasks')->exists() || DB::table('users')->exists()) {
        throw new RuntimeException('Requires a fresh seeded schema without company users or tasks.');
    }
    $manager = User::factory()->create(['name' => 'R43 Manager', 'email' => 'manager@r43.example.invalid', 'password' => password_hash('R43-disposable-browser-secret-123!', PASSWORD_BCRYPT), 'role_id' => Role::where('name', 'manager')->value('id')]);
    $pm = User::factory()->create(['name' => 'R43 Project Manager', 'email' => 'pm@r43.example.invalid', 'password' => $manager->password, 'role_id' => Role::where('name', 'project_manager')->value('id')]);
    $members = [];
    foreach (range(1, 40) as $i) {
        $members[] = User::factory()->create(['name' => sprintf('R43 Member %03d', $i), 'email' => sprintf('member%03d@r43.example.invalid', $i), 'password' => $manager->password, 'role_id' => Role::where('name', 'team_member')->value('id')]);
    }
    CompanySetting::query()->create(['company_name' => 'R43 Disposable Company', 'timezone' => config('app.timezone'), 'installed_at' => now()]);
    $projects = [];
    foreach (range(1, $projectCount) as $i) {
        $id = DB::table('projects')->insertGetId(['name' => sprintf('R43 Project %03d', $i), 'description' => 'Realistic synthetic qualification project', 'project_manager_id' => $i % 2 ? $pm->id : $manager->id, 'status' => 'active', 'created_at' => '2026-09-01 12:00:00', 'updated_at' => '2026-10-03 12:00:00']);
        $projects[] = $id;
        foreach (array_merge([$pm], $members) as $member) {
            DB::table('project_user')->insert(['project_id' => $id, 'user_id' => $member->id, 'added_by' => $manager->id, 'created_at' => '2026-09-01 12:00:00', 'updated_at' => '2026-09-01 12:00:00']);
        }
    }
    // Independent arithmetic fixture oracle: avoid asking the production report to compute expectations.
    $states = ['not_started', 'in_progress', 'on_hold', 'submitted', 'in_review', 'revision_requested', 'completed', 'cancelled'];
    $oracle = ['total' => $size, 'active' => 0, 'completed' => 0, 'cancelled' => 0, 'in_progress' => 0, 'overdue' => 0, 'status_counts' => array_fill_keys($states, 0), 'creation_dates' => [], 'event_counts' => ['task.completed' => 0, 'task.cancelled' => 0]];
    for ($offset = 0; $offset < $size; $offset += 250) {
        $tasks = [];
        $events = [];
        for ($i = $offset; $i < min($offset + 250, $size); $i++) {
            $state = $states[$i % 8];
            $created = CarbonImmutable::parse('2026-10-03 12:00:00')->subDays($i % 30)->format('Y-m-d H:i:s');
            $due = CarbonImmutable::parse('2026-10-03')->addDays(($i % 7) - 2)->toDateString();
            $id = $i + 1;
            $final = in_array($state, ['completed', 'cancelled'], true);
            $oracle['status_counts'][$state]++;
            $oracle['active'] += ! $final;
            $oracle['completed'] += $state === 'completed';
            $oracle['cancelled'] += $state === 'cancelled';
            $oracle['in_progress'] += $state === 'in_progress';
            $oracle['overdue'] += ! $final && $i % 7 < 2;
            $date = substr($created, 0, 10);
            $oracle['creation_dates'][$date] = ($oracle['creation_dates'][$date] ?? 0) + 1;
            $tasks[] = ['id' => $id, 'task_uid' => (string) Str::ulid(), 'title' => sprintf('R43 Work %05d', $id), 'description' => str_repeat('Business work नेपाल 😀. ', 12), 'comments' => 'Synthetic business notes', 'project_id' => $projects[intdiv($i, 8) % $projectCount], 'assignee_id' => $members[intdiv($i, 8) % 40]->id, 'reviewer_id' => $manager->id, 'created_by' => $manager->id, 'priority' => ['Low', 'Medium', 'High'][$i % 3], 'status' => $state, 'progress' => $state === 'completed' ? 100 : $i % 100, 'due_date' => $due, 'execution_due_date' => $due, 'review_due_date' => in_array($state, ['submitted', 'in_review'], true) ? $due : null, 'revision_due_date' => $state === 'revision_requested' ? $due : null, 'completed_at' => $state === 'completed' ? '2026-10-03 08:00:00' : null, 'completed_by' => $state === 'completed' ? $manager->id : null, 'created_at' => $created, 'updated_at' => '2026-10-03 12:00:00', 'lock_version' => 1];
            if ($final) {
                $type = $state === 'completed' ? 'task.completed' : 'task.cancelled';
                $oracle['event_counts'][$type]++;
                $events[] = ['event_uid' => (string) Str::ulid(), 'task_id' => $id, 'sequence' => 1, 'event_type' => $type, 'actor_id' => $manager->id, 'source' => 'qualification_fixture', 'occurred_at' => '2026-10-03 08:00:00', 'created_at' => '2026-10-03 08:00:00', 'updated_at' => '2026-10-03 08:00:00'];
            }
        }
        DB::table('tasks')->insert($tasks);
        if ($events) {
            DB::table('task_events')->insert($events);
        }
    }
    for ($offset = 0; $offset < $notificationCount; $offset += 250) {
        $rows = [];
        for ($i = $offset; $i < min($offset + 250, $notificationCount); $i++) {
            $rows[] = ['id' => (string) Str::uuid(), 'type' => 'R43SyntheticBusinessNotice', 'notifiable_type' => User::class, 'notifiable_id' => $manager->id, 'data' => json_encode(['message' => 'Synthetic notification '.$i], JSON_THROW_ON_ERROR), 'created_at' => '2026-10-03 09:00:00', 'updated_at' => '2026-10-03 09:00:00'];
        }
        DB::table('notifications')->insert($rows);
    }

    return ['size' => $size, 'projects' => $projectCount, 'notifications' => $notificationCount, 'manager_id' => $manager->id, 'pm_id' => $pm->id, 'member_id' => $members[0]->id, 'oracle' => $oracle];
}
