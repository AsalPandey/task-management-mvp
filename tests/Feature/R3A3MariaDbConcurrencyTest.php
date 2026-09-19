<?php

namespace Tests\Feature;

use App\Enums\TaskState;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\TaskHistory;
use App\Models\User;
use App\Services\TaskEventRecorder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class R3A3MariaDbConcurrencyTest extends TestCase
{
    public function test_racing_updates_produce_one_winner_and_one_stale_conflict(): void
    {
        $this->requireDisposableMariaDb();
        [$manager, $reviewer, $assignee, $project] = $this->fixtures();
        $task = $this->activeTask($project, $assignee, $reviewer);
        $initialVersion = (int) $task->lock_version;

        $startFile = $this->barrier('r3a3-update-start-');
        $payload1 = [
            'actor_id' => $manager->id,
            'task_id' => $task->id,
            'correlation_id' => 'r3a3-racing-update-1',
            'expected_version' => $initialVersion,
            'data' => ['title' => 'Title won by Worker 1'],
        ];
        $payload2 = [
            'actor_id' => $manager->id,
            'task_id' => $task->id,
            'correlation_id' => 'r3a3-racing-update-2',
            'expected_version' => $initialVersion,
            'data' => ['title' => 'Title won by Worker 2'],
        ];

        try {
            $first = $this->worker('update', $payload1, startFile: $startFile);
            $second = $this->worker('update', $payload2, startFile: $startFile);
            $first->start();
            $second->start();
            file_put_contents($startFile, 'go');
            $first->wait();
            $second->wait();

            $results = [$this->decode($first), $this->decode($second)];
            $outcomes = array_column($results, 'result');

            $this->assertContains('success', $outcomes);
            $this->assertContains('conflict', $outcomes);
            $this->assertCount(2, $outcomes);

            $winner = $results[0]['result'] === 'success' ? $results[0] : $results[1];
            $loser = $results[0]['result'] === 'conflict' ? $results[0] : $results[1];

            $this->assertSame(409, $loser['status_code']);
            $this->assertSame($initialVersion, $loser['expected_version']);
            $this->assertSame($initialVersion + 1, $loser['current_version']);

            $fresh = $task->fresh();
            $this->assertSame($initialVersion + 1, $fresh->lock_version);
            $this->assertSame($winner['details']['title'], $fresh->title);

            // Verify exactly one updated event and history record created
            $this->assertSame(1, TaskEvent::query()->where('task_id', $task->id)->where('event_type', TaskEventRecorder::UPDATED)->count());
            $this->assertSame(1, TaskHistory::query()->where('task_id', $task->id)->where('action', 'updated')->count());
        } finally {
            $this->cleanupProject($project, [$manager, $reviewer, $assignee]);
            @unlink($startFile);
        }
    }

    public function test_concurrent_updates_to_different_fields_still_conflict_without_silent_merge(): void
    {
        $this->requireDisposableMariaDb();
        [$manager, $reviewer, $assignee, $project] = $this->fixtures();
        $task = $this->activeTask($project, $assignee, $reviewer);
        $initialVersion = (int) $task->lock_version;

        $startFile = $this->barrier('r3a3-diff-fields-start-');
        $payload1 = [
            'actor_id' => $manager->id,
            'task_id' => $task->id,
            'correlation_id' => 'r3a3-field-1',
            'expected_version' => $initialVersion,
            'data' => ['title' => 'Title by Worker 1'],
        ];
        $payload2 = [
            'actor_id' => $manager->id,
            'task_id' => $task->id,
            'correlation_id' => 'r3a3-field-2',
            'expected_version' => $initialVersion,
            'data' => ['comments' => 'Comments by Worker 2'],
        ];

        try {
            $first = $this->worker('update', $payload1, startFile: $startFile);
            $second = $this->worker('update', $payload2, startFile: $startFile);
            $first->start();
            $second->start();
            file_put_contents($startFile, 'go');
            $first->wait();
            $second->wait();

            $results = [$this->decode($first), $this->decode($second)];
            $outcomes = array_column($results, 'result');

            $this->assertContains('success', $outcomes);
            $this->assertContains('conflict', $outcomes);

            $winner = $results[0]['result'] === 'success' ? $results[0] : $results[1];
            $loser = $results[0]['result'] === 'conflict' ? $results[0] : $results[1];

            $fresh = $task->fresh();
            $this->assertSame($initialVersion + 1, $fresh->lock_version);

            if ($winner['details']['title'] === 'Title by Worker 1') {
                $this->assertSame('Title by Worker 1', $fresh->title);
                $this->assertNull($fresh->comments); // Comments from loser must NOT be merged
            } else {
                $this->assertSame('Comments by Worker 2', $fresh->comments);
                $this->assertNotSame('Title by Worker 1', $fresh->title); // Title from loser must NOT be merged
            }
        } finally {
            $this->cleanupProject($project, [$manager, $reviewer, $assignee]);
            @unlink($startFile);
        }
    }

    public function test_workflow_submission_and_concurrent_edit_conflict_cleanly(): void
    {
        $this->requireDisposableMariaDb();
        [$manager, $reviewer, $assignee, $project] = $this->fixtures();
        $task = $this->activeTask($project, $assignee, $reviewer);
        $task->forceFill(['status' => TaskState::InProgress])->save();
        $initialVersion = (int) $task->lock_version;

        $readyFile = $this->barrier('r3a3-submit-ready-');
        $submitPayload = [
            'actor_id' => $assignee->id,
            'task_id' => $task->id,
            'correlation_id' => 'r3a3-concurrent-submit',
            'expected_version' => $initialVersion,
            'submission_note' => 'Worker submission.',
        ];
        $updatePayload = [
            'actor_id' => $manager->id,
            'task_id' => $task->id,
            'correlation_id' => 'r3a3-stale-update',
            'expected_version' => $initialVersion,
            'data' => ['title' => 'Stale title update'],
        ];

        try {
            $submitWorker = $this->worker('submit', $submitPayload, hold: 400, readyFile: $readyFile);
            $submitWorker->start();
            $this->waitForBarrier($submitWorker, $readyFile);

            $updateWorker = $this->worker('update', $updatePayload);
            $updateWorker->start();

            $submitWorker->wait();
            $updateWorker->wait();

            $submitResult = $this->decode($submitWorker);
            $updateResult = $this->decode($updateWorker);

            $this->assertSame('success', $submitResult['result']);
            $this->assertSame('conflict', $updateResult['result']);
            $this->assertSame(409, $updateResult['status_code']);

            $fresh = $task->fresh();
            $this->assertSame(TaskState::Submitted, $fresh->machineState());
            $this->assertSame($initialVersion + 1, $fresh->lock_version);
            $this->assertNotSame('Stale title update', $fresh->title);
        } finally {
            $this->cleanupProject($project, [$manager, $reviewer, $assignee]);
            @unlink($readyFile);
        }
    }

    private function requireDisposableMariaDb(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            || preg_match('/^task_management_phase28_r3a3_[a-z0-9_]+$/', DB::getDatabaseName()) !== 1) {
            $this->markTestSkipped('Requires an isolated R3A.3 MariaDB database.');
        }
    }

    private function fixtures(): array
    {
        $this->seed();
        $manager = $this->user('manager');
        $reviewer = $this->user('manager');
        $assignee = $this->user('team_member');
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $project->members()->attach([$assignee->id, $reviewer->id]);

        return [$manager, $reviewer, $assignee, $project];
    }

    private function activeTask(Project $project, User $assignee, User $reviewer): Task
    {
        $task = new Task([
            'title' => 'R3A3 concurrency baseline task',
            'project_id' => $project->id,
            'assignee_id' => $assignee->id,
            'status' => TaskState::NotStarted,
            'priority' => 'Medium',
            'progress' => 0,
        ]);
        $task->forceFill([
            'reviewer_id' => $reviewer->id,
            'lock_version' => 1,
        ])->save();

        return $task->fresh();
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('name', $role)->value('id')]);
    }

    private function worker(string $operation, array $payload, int $hold = 0, ?string $readyFile = null, ?string $startFile = null): Process
    {
        return new Process([
            PHP_BINARY, base_path('tests/Support/r3a3_concurrency_worker.php'), $operation,
            json_encode($payload, JSON_THROW_ON_ERROR), (string) $hold, $readyFile ?? '', $startFile ?? '',
        ], base_path(), timeout: 20);
    }

    private function barrier(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        $this->assertNotFalse($path);
        unlink($path);

        return $path;
    }

    private function waitForBarrier(Process $worker, string $path): void
    {
        $deadline = microtime(true) + 5;
        while (! file_exists($path) && microtime(true) < $deadline) {
            if (! $worker->isRunning()) {
                $this->fail($worker->getErrorOutput().$worker->getOutput());
            }
            usleep(20_000);
        }
        $this->assertFileExists($path);
    }

    private function decode(Process $process): array
    {
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());

        return json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
    }

    private function cleanupProject(Project $project, array $users): void
    {
        $taskIds = Task::withTrashed()->where('project_id', $project->id)->pluck('id');
        DB::table('task_notification_deliveries')->whereIn('task_id', $taskIds)->delete();
        DB::table('task_events')->whereIn('task_id', $taskIds)->delete();
        DB::table('task_approvals')->whereIn('task_id', $taskIds)->delete();
        DB::table('task_submissions')->whereIn('task_id', $taskIds)->delete();
        DB::table('task_histories')->whereIn('task_id', $taskIds)->delete();
        DB::table('task_revision_cycles')->whereIn('task_id', $taskIds)->delete();
        Task::withTrashed()->whereIn('id', $taskIds)->forceDelete();
        $project->members()->detach();
        $project->forceDelete();
        User::withTrashed()->whereIn('id', collect($users)->pluck('id'))->forceDelete();
    }
}
