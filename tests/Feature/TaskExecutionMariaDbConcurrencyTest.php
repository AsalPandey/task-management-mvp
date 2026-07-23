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

class TaskExecutionMariaDbConcurrencyTest extends TestCase
{
    public function test_concurrent_start_hold_and_resume_each_create_one_transition(): void
    {
        $this->requireDisposableMariaDb();
        [$manager, $projectManager, $assignee, $project, $task, $createdRoles] = $this->fixtures();
        $taskId = $task->id;
        $taskUid = $task->task_uid;
        $deadline = $task->execution_due_date->toDateString();
        $notificationCount = DB::table('notifications')->count();

        try {
            [$start, $duplicateStart] = $this->race('start', $task, $assignee);
            $this->assertRaceResult($start, $duplicateStart, 'Only a not-started task may be started.');
            $startedAt = $task->fresh()->started_at?->toAtomString();
            $this->assertNotNull($startedAt);

            [$hold, $duplicateHold] = $this->race('hold', $task, $assignee);
            $this->assertRaceResult($hold, $duplicateHold, 'Only an in-progress task may be put on hold.');
            $this->assertSame('Concurrent hold', $task->fresh()->hold_reason);

            [$resume, $duplicateResume] = $this->race('resume', $task, $assignee);
            $this->assertRaceResult($resume, $duplicateResume, 'Only an on-hold task may be resumed.');

            $resumed = $task->fresh();
            $this->assertSame($taskId, $resumed->id);
            $this->assertSame($taskUid, $resumed->task_uid);
            $this->assertSame(TaskState::InProgress, $resumed->machineState());
            $this->assertSame($startedAt, $resumed->started_at?->toAtomString());
            $this->assertNull($resumed->held_at);
            $this->assertNull($resumed->held_by);
            $this->assertNull($resumed->hold_reason);
            $this->assertSame($deadline, $resumed->execution_due_date->toDateString());

            foreach ([
                TaskEventRecorder::STARTED => 'started',
                TaskEventRecorder::HELD => 'held',
                TaskEventRecorder::RESUMED => 'resumed',
            ] as $eventType => $historyAction) {
                $this->assertSame(1, TaskEvent::query()
                    ->where('task_id', $taskId)
                    ->where('event_type', $eventType)
                    ->count());
                $this->assertSame(1, TaskHistory::query()
                    ->where('task_id', $taskId)
                    ->where('action', $historyAction)
                    ->count());
            }

            $this->assertSame($notificationCount + 8, DB::table('notifications')->count());
        } finally {
            $this->cleanup(
                $taskId,
                $project,
                [$manager, $projectManager, $assignee],
                $createdRoles,
            );
        }
    }

    private function requireDisposableMariaDb(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Row-lock execution concurrency requires an isolated MySQL/MariaDB database.');
        }

        if (preg_match('/^task_management_phase23_[a-z0-9_]+$/', DB::getDatabaseName()) !== 1) {
            $this->markTestSkipped('Execution concurrency is restricted to a disposable Phase 2.3 QA database.');
        }
    }

    private function fixtures(): array
    {
        $createdRoles = [];
        $managerRole = $this->role('manager', 'Manager', $createdRoles);
        $projectManagerRole = $this->role('project_manager', 'Project Manager', $createdRoles);
        $memberRole = $this->role('team_member', 'Team Member', $createdRoles);
        $manager = User::factory()->create(['role_id' => $managerRole->id]);
        $projectManager = User::factory()->create(['role_id' => $projectManagerRole->id]);
        $assignee = User::factory()->create(['role_id' => $memberRole->id]);
        $project = Project::factory()->create(['project_manager_id' => $projectManager->id]);
        $project->members()->attach($assignee->id);
        $task = Task::query()->create([
            'project_id' => $project->id,
            'title' => 'MariaDB execution concurrency task',
            'assignee_id' => $assignee->id,
            'created_by' => $manager->id,
            'priority' => 'High',
            'status' => TaskState::NotStarted,
            'progress' => 20,
            'due_date' => now()->addDays(4)->toDateString(),
        ]);
        $task->forceFill([
            'reviewer_id' => $projectManager->id,
            'execution_due_date' => now()->addDays(4)->toDateString(),
        ])->save();

        return [$manager, $projectManager, $assignee, $project, $task->fresh(), $createdRoles];
    }

    private function role(string $name, string $label, array &$createdRoles): Role
    {
        $role = Role::query()->where('name', $name)->first();

        if (! $role) {
            $role = Role::query()->create(compact('name', 'label'));
            $createdRoles[] = $role;
        }

        return $role;
    }

    private function race(string $operation, Task $task, User $actor): array
    {
        $readyFile = tempnam(sys_get_temp_dir(), 'task-execution-lock-');

        if ($readyFile === false) {
            $this->fail('Unable to allocate the execution concurrency barrier file.');
        }

        unlink($readyFile);

        try {
            $first = $this->worker($operation, $task, $actor, '1', 1200, $readyFile);
            $first->start();
            $this->waitForLock($first, $readyFile);

            $second = $this->worker($operation, $task, $actor, '2');
            $second->start();
            usleep(200_000);
            $this->assertTrue($second->isRunning(), 'The second connection should wait for the task row lock.');

            $first->wait();
            $second->wait();
            $this->assertTrue($first->isSuccessful(), $first->getErrorOutput().$first->getOutput());
            $this->assertTrue($second->isSuccessful(), $second->getErrorOutput().$second->getOutput());

            return [$this->decodeWorkerResult($first), $this->decodeWorkerResult($second)];
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
        }
    }

    private function worker(
        string $operation,
        Task $task,
        User $actor,
        string $worker,
        int $holdMilliseconds = 0,
        ?string $readyFile = null,
    ): Process {
        return new Process([
            PHP_BINARY,
            base_path('tests/Support/transition_task.php'),
            $operation,
            (string) $task->id,
            (string) $actor->id,
            $worker,
            (string) $holdMilliseconds,
            $readyFile ?? '',
        ], base_path(), timeout: 20);
    }

    private function waitForLock(Process $worker, string $readyFile): void
    {
        $deadline = microtime(true) + 5;

        while (! file_exists($readyFile) && microtime(true) < $deadline) {
            if (! $worker->isRunning()) {
                $this->fail('The first execution worker exited before acquiring its row lock: '
                    .$worker->getErrorOutput().$worker->getOutput());
            }

            usleep(20_000);
        }

        $this->assertFileExists($readyFile, 'The first worker did not acquire its task row lock in time.');
    }

    private function decodeWorkerResult(Process $worker): array
    {
        $output = trim($worker->getOutput());

        if (preg_match('/(\{[^\r\n]+\})$/', $output, $matches) !== 1) {
            $this->fail('Execution worker did not return a JSON result: '.json_encode($output));
        }

        return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    }

    private function assertRaceResult(array $success, array $conflict, string $message): void
    {
        $this->assertSame('transitioned', $success['result']);
        $this->assertSame('conflict', $conflict['result']);
        $this->assertSame(422, $conflict['status_code']);
        $this->assertSame($message, $conflict['message']);
        $this->assertSame(DB::getDatabaseName(), $success['database']);
        $this->assertSame(DB::getDatabaseName(), $conflict['database']);
    }

    private function cleanup(int $taskId, Project $project, array $users, array $createdRoles): void
    {
        DB::table('notifications')->whereIn('notifiable_id', collect($users)->pluck('id'))->delete();
        TaskEvent::query()->where('task_id', $taskId)->delete();
        TaskHistory::query()->where('task_id', $taskId)->delete();
        Task::withTrashed()->whereKey($taskId)->forceDelete();
        $project->members()->detach();
        $project->delete();

        foreach ($users as $user) {
            $user->forceDelete();
        }

        foreach ($createdRoles as $role) {
            $role->delete();
        }
    }
}
