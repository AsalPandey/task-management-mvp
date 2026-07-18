<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskEvent;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TaskEventMariaDbConcurrencyTest extends TestCase
{
    public function test_two_connections_serialize_event_sequences_on_the_task_row(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Row-lock concurrency requires an isolated MySQL/MariaDB database.');
        }

        $task = Task::query()->create(['title' => 'MariaDB event concurrency task']);
        $readyFile = tempnam(sys_get_temp_dir(), 'task-event-lock-');

        if ($readyFile === false) {
            $this->fail('Unable to allocate the concurrency barrier file.');
        }

        unlink($readyFile);

        try {
            $first = $this->worker($task, '1', 1500, $readyFile);
            $first->start();
            $this->waitForLock($first, $readyFile);

            $second = $this->worker($task, '2');
            $second->start();
            usleep(200_000);
            $this->assertTrue($second->isRunning(), 'The second connection should be waiting for the task row lock.');

            $first->wait();
            $second->wait();

            $this->assertTrue($first->isSuccessful(), $first->getErrorOutput().$first->getOutput());
            $this->assertTrue($second->isSuccessful(), $second->getErrorOutput().$second->getOutput());
            $firstResult = $this->decodeWorkerResult($first);
            $secondResult = $this->decodeWorkerResult($second);
            $this->assertSame(DB::getDatabaseName(), $firstResult['database']);
            $this->assertSame(DB::getDatabaseName(), $secondResult['database']);
            $this->assertSame([1, 2], [$firstResult['sequence'], $secondResult['sequence']]);
            $this->assertSame([1, 2], $task->events()->pluck('sequence')->all());
            $this->assertSame(2, $task->events()->distinct()->count('event_uid'));
            $this->assertSame(
                ['concurrency-1', 'concurrency-2'],
                $task->events()->orderBy('sequence')->pluck('correlation_id')->all(),
            );
        } finally {
            if (isset($first) && $first->isRunning()) {
                $first->stop();
            }

            if (isset($second) && $second->isRunning()) {
                $second->stop();
            }

            if (file_exists($readyFile)) {
                unlink($readyFile);
            }

            TaskEvent::query()->where('task_id', $task->id)->delete();
            $task->forceDelete();
        }
    }

    private function worker(Task $task, string $worker, int $holdMilliseconds = 0, ?string $readyFile = null): Process
    {
        return new Process([
            PHP_BINARY,
            base_path('tests/Support/record_task_event.php'),
            (string) $task->id,
            $worker,
            (string) $holdMilliseconds,
            $readyFile ?? '',
        ], base_path(), timeout: 15);
    }

    private function waitForLock(Process $worker, string $readyFile): void
    {
        $deadline = microtime(true) + 5;

        while (! file_exists($readyFile) && microtime(true) < $deadline) {
            if (! $worker->isRunning()) {
                $this->fail('The first worker exited before acquiring its row lock: '.$worker->getErrorOutput().$worker->getOutput());
            }

            usleep(20_000);
        }

        $this->assertFileExists($readyFile, 'The first worker did not acquire its task row lock in time.');
    }

    private function decodeWorkerResult(Process $worker): array
    {
        $output = trim($worker->getOutput());

        if (preg_match('/(\{[^\r\n]+\})$/', $output, $matches) !== 1) {
            $this->fail('Worker did not return a JSON result: '.json_encode($output));
        }

        return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    }
}
