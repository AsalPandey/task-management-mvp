<?php

namespace App\Console\Commands;

use App\Models\Task;
use App\Support\UlidGenerator;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class BackfillTaskUids extends Command
{
    private const BATCH_SIZE = 500;

    private const MAX_UID_ATTEMPTS = 5;

    protected $signature = 'tasks:backfill-uids
                            {--dry-run : Report required changes without writing to the database}';

    protected $description = 'Assign stable ULIDs to task rows that do not have one';

    public function __construct(private readonly UlidGenerator $ulids)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $requiresUid = Task::withTrashed()->whereNull('task_uid')->count();
        $alreadyAssigned = Task::withTrashed()->whereNotNull('task_uid')->count();

        $this->info("Rows requiring a UID: {$requiresUid}");
        $this->info("Rows already assigned: {$alreadyAssigned}");

        if ($this->option('dry-run')) {
            $this->info('Dry run complete. No database writes were performed.');

            return self::SUCCESS;
        }

        $processed = 0;
        $skipped = $alreadyAssigned;
        $failed = 0;
        $currentBatchSize = 0;

        try {
            Task::withTrashed()
                ->whereNull('task_uid')
                ->select('id')
                ->orderBy('id')
                ->chunkById(self::BATCH_SIZE, function (Collection $tasks) use (&$processed, &$skipped, &$currentBatchSize) {
                    $currentBatchSize = $tasks->count();
                    $ids = $tasks->pluck('id')->all();

                    [$batchProcessed, $batchSkipped] = DB::transaction(function () use ($ids) {
                        $lockedTasks = Task::withTrashed()
                            ->whereKey($ids)
                            ->orderBy('id')
                            ->lockForUpdate()
                            ->get(['id', 'task_uid']);
                        $batchProcessed = 0;
                        $batchSkipped = 0;

                        foreach ($lockedTasks as $task) {
                            if ($task->task_uid !== null) {
                                $batchSkipped++;

                                continue;
                            }

                            if ($this->assignUid($task->id)) {
                                $batchProcessed++;
                            } else {
                                $batchSkipped++;
                            }
                        }

                        return [$batchProcessed, $batchSkipped];
                    });

                    $processed += $batchProcessed;
                    $skipped += $batchSkipped;
                    $currentBatchSize = 0;
                });
        } catch (Throwable $exception) {
            $failed += max(1, $currentBatchSize);
            $this->error('UID backfill stopped: '.$exception->getMessage());
            $this->reportCounts($processed, $skipped, $failed);

            return self::FAILURE;
        }

        $this->reportCounts($processed, $skipped, $failed);

        return self::SUCCESS;
    }

    private function assignUid(int $taskId): bool
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::MAX_UID_ATTEMPTS; $attempt++) {
            try {
                return DB::table('tasks')
                    ->where('id', $taskId)
                    ->whereNull('task_uid')
                    ->update(['task_uid' => $this->ulids->generate()]) === 1;
            } catch (UniqueConstraintViolationException $exception) {
                $lastException = $exception;
            }
        }

        throw new RuntimeException(
            "Unable to assign a unique UID to task {$taskId} after bounded retries.",
            previous: $lastException,
        );
    }

    private function reportCounts(int $processed, int $skipped, int $failed): void
    {
        $this->newLine();
        $this->line("Processed: {$processed}");
        $this->line("Skipped: {$skipped}");
        $this->line("Failed: {$failed}");
    }
}
