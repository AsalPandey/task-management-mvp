<?php

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TaskReminderGenerationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_schema_contains_generation_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('tasks', [
            'deadline_reminder_generation',
            'overdue_notification_generation',
        ]));
    }

    public function test_additive_upgrade_preserves_legacy_task_and_adopts_existing_markers(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'r2b1-upgrade-');
        $this->assertNotFalse($path);

        Config::set('database.connections.r2b1_upgrade', [
            'driver' => 'sqlite',
            'database' => $path,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        try {
            $this->assertSame(0, Artisan::call('migrate:fresh', [
                '--database' => 'r2b1_upgrade',
                '--force' => true,
            ]));
            $this->assertSame(0, Artisan::call('migrate:rollback', [
                '--database' => 'r2b1_upgrade',
                '--step' => 6,
                '--force' => true,
            ]));

            $id = DB::connection('r2b1_upgrade')->table('tasks')->insertGetId([
                'task_uid' => '00000000000000000000000001',
                'title' => 'Preserved legacy reminder task',
                'status' => 'in_progress',
                'execution_due_date' => '2026-09-20',
                'due_date' => '2026-09-20',
                'deadline_reminder_sent_at' => '2026-09-19 08:00:00',
                'overdue_notification_sent_at' => '2026-09-21 00:00:00',
                'created_at' => '2026-09-16 00:00:00',
                'updated_at' => '2026-09-16 00:00:00',
            ]);
            $before = (array) DB::connection('r2b1_upgrade')->table('tasks')->find($id);

            $this->assertSame(0, Artisan::call('migrate', [
                '--database' => 'r2b1_upgrade',
                '--force' => true,
            ]));

            $task = Task::on('r2b1_upgrade')->findOrFail($id);
            $fingerprint = $task->activeDeadlineGeneration()?->fingerprint();
            $this->assertNotNull($fingerprint);
            $this->assertSame($fingerprint, $task->deadline_reminder_generation);
            $this->assertSame($fingerprint, $task->overdue_notification_generation);

            $after = (array) DB::connection('r2b1_upgrade')->table('tasks')->find($id);
            unset($after['deadline_reminder_generation'], $after['overdue_notification_generation'], $after['lock_version']);
            $this->assertSame($before, $after);

            $this->assertSame(0, Artisan::call('migrate:rollback', [
                '--database' => 'r2b1_upgrade',
                '--step' => 6,
                '--force' => true,
            ]));
            $this->assertFalse(Schema::connection('r2b1_upgrade')->hasColumn('tasks', 'deadline_reminder_generation'));
            $this->assertSame($before, (array) DB::connection('r2b1_upgrade')->table('tasks')->find($id));

            $this->assertSame(0, Artisan::call('migrate', [
                '--database' => 'r2b1_upgrade',
                '--force' => true,
            ]));
            $this->assertTrue(Schema::connection('r2b1_upgrade')->hasColumn('tasks', 'overdue_notification_generation'));
        } finally {
            DB::purge('r2b1_upgrade');
            if (is_string($path) && file_exists($path)) {
                unlink($path);
            }
        }
    }
}
