<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class TaskStatusConversionMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversion_maps_only_legacy_states_and_preserves_identity_content_and_metadata(): void
    {
        $this->ensureTaskSchema();
        DB::table('tasks')->delete();
        $migration = $this->migration();
        $migration->down();

        $createdAt = '2026-07-20 08:30:00';
        $updatedAt = '2026-07-21 09:45:00';
        $completedAt = '2026-07-22 10:15:00';
        $rows = [];

        foreach ([
            'Not Started' => 'not_started',
            'In Progress' => 'in_progress',
            'On Hold' => 'on_hold',
            'Completed' => 'completed',
        ] as $display => $machine) {
            $uid = (string) Str::ulid();
            $rows[$display] = [
                'id' => DB::table('tasks')->insertGetId([
                    'task_uid' => $uid,
                    'title' => "{$display} conversion",
                    'description' => 'Content must remain unchanged.',
                    'priority' => 'Medium',
                    'status' => $display,
                    'progress' => $display === 'Completed' ? 100 : 25,
                    'completed_at' => $display === 'Completed' ? $completedAt : null,
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                    'deleted_at' => $display === 'On Hold' ? '2026-07-22 11:00:00' : null,
                ]),
                'uid' => $uid,
                'machine' => $machine,
            ];
        }

        $migration->up();

        foreach ($rows as $display => $expected) {
            $task = DB::table('tasks')->where('id', $expected['id'])->first();

            $this->assertSame($expected['id'], $task->id);
            $this->assertSame($expected['uid'], $task->task_uid);
            $this->assertSame($expected['machine'], $task->status);
            $this->assertSame("{$display} conversion", $task->title);
            $this->assertSame('Content must remain unchanged.', $task->description);
            $this->assertSame($createdAt, $task->created_at);
            $this->assertSame($updatedAt, $task->updated_at);
        }

        $completed = DB::table('tasks')->where('id', $rows['Completed']['id'])->first();
        $held = DB::table('tasks')->where('id', $rows['On Hold']['id'])->first();

        $this->assertSame($completedAt, $completed->completed_at);
        $this->assertNotNull($held->deleted_at);
        $this->assertDatabaseCount('tasks', 4);

        $defaultStatusId = DB::table('tasks')->insertGetId([
            'task_uid' => (string) Str::ulid(),
            'title' => 'Database default state',
            'priority' => 'Medium',
            'progress' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(
            'not_started',
            DB::table('tasks')->where('id', $defaultStatusId)->value('status'),
        );

        DB::table('tasks')->delete();
    }

    public function test_unknown_status_aborts_before_any_row_is_converted(): void
    {
        $this->ensureTaskSchema();
        DB::table('tasks')->delete();
        $migration = $this->migration();
        $migration->down();
        DB::table('tasks')->insert([
            [
                'task_uid' => (string) Str::ulid(),
                'title' => 'Known status',
                'priority' => 'Medium',
                'status' => 'In Progress',
                'progress' => 20,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'task_uid' => (string) Str::ulid(),
                'title' => 'Unknown status',
                'priority' => 'Medium',
                'status' => 'Mystery',
                'progress' => 20,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        try {
            $migration->up();
            $this->fail('Unknown task states must abort the conversion.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Mystery', $exception->getMessage());
        }

        $this->assertSame(
            ['In Progress', 'Mystery'],
            DB::table('tasks')->orderBy('id')->pluck('status')->all(),
        );

        DB::table('tasks')->where('status', 'Mystery')->update(['status' => 'Not Started']);
        $migration->up();
        DB::table('tasks')->delete();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_07_23_000005_convert_task_statuses_to_machine_values.php');
    }

    private function ensureTaskSchema(): void
    {
        if (! Schema::hasTable('tasks')) {
            $this->artisan('migrate:fresh', ['--force' => true]);
        }
    }

    protected function connectionsToTransact(): array
    {
        return DB::getDriverName() === 'mysql' ? [] : [null];
    }
}
