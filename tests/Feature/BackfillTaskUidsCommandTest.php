<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Support\UlidGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackfillTaskUidsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_counts_without_writing(): void
    {
        $this->insertTask(['title' => 'Missing UID']);
        $existingUid = '00000000000000000000000001';
        $this->insertTask(['title' => 'Existing UID', 'task_uid' => $existingUid]);

        $this->artisan('tasks:backfill-uids', ['--dry-run' => true])
            ->expectsOutput('Rows requiring a UID: 1')
            ->expectsOutput('Rows already assigned: 1')
            ->expectsOutput('Dry run complete. No database writes were performed.')
            ->assertSuccessful();

        $this->assertSame(1, Task::withTrashed()->whereNull('task_uid')->count());
        $this->assertSame($existingUid, Task::withTrashed()->where('title', 'Existing UID')->value('task_uid'));
    }

    public function test_apply_includes_soft_deleted_rows_preserves_existing_data_and_is_idempotent(): void
    {
        $timestamp = now()->subDay()->startOfSecond();
        $activeId = $this->insertTask([
            'title' => 'Active missing UID',
            'description' => 'Preserve this content',
            'updated_at' => $timestamp,
        ]);
        $deletedId = $this->insertTask([
            'title' => 'Deleted missing UID',
            'deleted_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $existingUid = '00000000000000000000000009';
        $existingId = $this->insertTask([
            'title' => 'Existing UID',
            'task_uid' => $existingUid,
            'updated_at' => $timestamp,
        ]);
        $before = DB::table('tasks')->whereIn('id', [$activeId, $deletedId, $existingId])->orderBy('id')->get();

        $this->artisan('tasks:backfill-uids')
            ->expectsOutput('Rows requiring a UID: 2')
            ->expectsOutput('Rows already assigned: 1')
            ->expectsOutput('Processed: 2')
            ->expectsOutput('Skipped: 1')
            ->expectsOutput('Failed: 0')
            ->assertSuccessful();

        $afterFirstRun = DB::table('tasks')->whereIn('id', [$activeId, $deletedId, $existingId])->orderBy('id')->get();
        $this->assertSame('Preserve this content', $afterFirstRun->firstWhere('id', $activeId)->description);
        $this->assertSame($before->firstWhere('id', $activeId)->updated_at, $afterFirstRun->firstWhere('id', $activeId)->updated_at);
        $this->assertSame($before->firstWhere('id', $deletedId)->deleted_at, $afterFirstRun->firstWhere('id', $deletedId)->deleted_at);
        $this->assertSame($before->firstWhere('id', $deletedId)->updated_at, $afterFirstRun->firstWhere('id', $deletedId)->updated_at);
        $this->assertSame($existingUid, $afterFirstRun->firstWhere('id', $existingId)->task_uid);
        $this->assertSame(0, Task::withTrashed()->whereNull('task_uid')->count());

        $uids = $afterFirstRun->pluck('task_uid', 'id')->all();
        $this->artisan('tasks:backfill-uids')
            ->expectsOutput('Rows requiring a UID: 0')
            ->expectsOutput('Rows already assigned: 3')
            ->expectsOutput('Processed: 0')
            ->expectsOutput('Skipped: 3')
            ->expectsOutput('Failed: 0')
            ->assertSuccessful();

        $this->assertSame($uids, DB::table('tasks')->whereIn('id', array_keys($uids))->orderBy('id')->pluck('task_uid', 'id')->all());
    }

    public function test_an_unexpected_uid_failure_rolls_back_the_entire_batch(): void
    {
        $firstId = $this->insertTask(['title' => 'First batch task']);
        $secondId = $this->insertTask(['title' => 'Second batch task']);
        $repeatedUid = '00000000000000000000000001';
        $this->app->instance(UlidGenerator::class, new RepeatingBackfillUlidGenerator($repeatedUid));

        $this->artisan('tasks:backfill-uids')
            ->expectsOutputToContain('UID backfill stopped: Unable to assign a unique UID to task')
            ->expectsOutput('Processed: 0')
            ->expectsOutput('Skipped: 0')
            ->expectsOutput('Failed: 2')
            ->assertFailed();

        $this->assertNull(Task::withTrashed()->whereKey($firstId)->value('task_uid'));
        $this->assertNull(Task::withTrashed()->whereKey($secondId)->value('task_uid'));
    }

    public function test_large_sets_are_chunked_in_primary_key_order(): void
    {
        $now = now()->startOfSecond();
        $rows = [];

        for ($number = 1; $number <= 501; $number++) {
            $rows[] = [
                'title' => "Chunked task {$number}",
                'priority' => 'Medium',
                'status' => 'Not Started',
                'progress' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('tasks')->insert($chunk);
        }

        $expectedUids = collect(range(1, 501))
            ->map(fn (int $number) => str_pad((string) $number, 26, '0', STR_PAD_LEFT))
            ->all();
        $this->app->instance(UlidGenerator::class, new SequenceBackfillUlidGenerator($expectedUids));

        $this->artisan('tasks:backfill-uids')
            ->expectsOutput('Processed: 501')
            ->expectsOutput('Skipped: 0')
            ->expectsOutput('Failed: 0')
            ->assertSuccessful();

        $this->assertSame($expectedUids, DB::table('tasks')->orderBy('id')->pluck('task_uid')->all());
    }

    private function insertTask(array $attributes = []): int
    {
        return DB::table('tasks')->insertGetId(array_merge([
            'title' => 'Backfill task',
            'priority' => 'Medium',
            'status' => 'Not Started',
            'progress' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }
}

class RepeatingBackfillUlidGenerator extends UlidGenerator
{
    public function __construct(private readonly string $ulid) {}

    public function generate(): string
    {
        return $this->ulid;
    }
}

class SequenceBackfillUlidGenerator extends UlidGenerator
{
    private int $position = 0;

    public function __construct(private readonly array $ulids) {}

    public function generate(): string
    {
        return $this->ulids[$this->position++];
    }
}
