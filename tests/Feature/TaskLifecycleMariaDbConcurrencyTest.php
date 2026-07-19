<?php

namespace Tests\Feature;

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

class TaskLifecycleMariaDbConcurrencyTest extends TestCase
{
    public function test_two_connections_create_one_canonical_completion(): void
    {
        $this->requireDisposableMariaDb();
        [$manager, $project, $assignee, $createdRoles] = $this->fixtures();
        $task = $this->task($project, $assignee);
        $taskId = $task->id;
        $taskUid = $task->task_uid;
        $completedTaskCount = DB::table('completed_tasks')->count();
        $notificationCount = DB::table('notifications')->count();

        try {
            [$first, $second] = $this->race('complete', $task, $manager);

            $this->assertSame(['transitioned', 'conflict'], [$first['result'], $second['result']]);
            $this->assertSame('Task is already completed.', $second['message']);
            $this->assertSame($taskId, $first['task_id']);
            $this->assertSame($taskUid, $first['task_uid']);

            $completed = Task::query()->findOrFail($taskId);
            $this->assertSame($taskUid, $completed->task_uid);
            $this->assertSame('Completed', $completed->status);
            $this->assertSame(100, $completed->progress);
            $this->assertNotNull($completed->completed_at);
            $this->assertSame($manager->id, $completed->completed_by);
            $this->assertSame(1, Task::withTrashed()->whereKey($taskId)->count());
            $this->assertSame($completedTaskCount, DB::table('completed_tasks')->count());
            $this->assertSame(1, TaskEvent::query()->where('task_id', $taskId)
                ->where('event_type', TaskEventRecorder::COMPLETED)->count());
            $this->assertSame(1, TaskHistory::query()->where('task_id', $taskId)
                ->where('action', 'completed')->count());
            $this->assertSame($notificationCount + 1, DB::table('notifications')->count());
        } finally {
            $this->cleanup($taskId, $project, [$manager, $assignee], $createdRoles);
        }
    }

    public function test_two_connections_create_one_canonical_reopen(): void
    {
        $this->requireDisposableMariaDb();
        [$manager, $project, $assignee, $createdRoles] = $this->fixtures();
        $task = $this->task($project, $assignee);
        $task->forceFill([
            'status' => 'Completed',
            'progress' => 100,
            'completed_at' => now(),
            'completed_by' => $manager->id,
        ])->save();
        $taskId = $task->id;
        $taskUid = $task->task_uid;
        $taskCount = Task::withTrashed()->count();
        $completedTaskCount = DB::table('completed_tasks')->count();
        $notificationCount = DB::table('notifications')->count();

        try {
            [$first, $second] = $this->race('reopen', $task, $manager);

            $this->assertSame(['transitioned', 'conflict'], [$first['result'], $second['result']]);
            $this->assertSame('Task is already active.', $second['message']);
            $this->assertSame($taskId, $first['task_id']);
            $this->assertSame($taskUid, $first['task_uid']);

            $reopened = Task::query()->findOrFail($taskId);
            $this->assertSame($taskUid, $reopened->task_uid);
            $this->assertSame('In Progress', $reopened->status);
            $this->assertSame(99, $reopened->progress);
            $this->assertNull($reopened->completed_at);
            $this->assertNull($reopened->completed_by);
            $this->assertSame($taskCount, Task::withTrashed()->count());
            $this->assertSame($completedTaskCount, DB::table('completed_tasks')->count());
            $this->assertSame(1, TaskEvent::query()->where('task_id', $taskId)
                ->where('event_type', TaskEventRecorder::REOPENED)->count());
            $this->assertSame(1, TaskHistory::query()->where('task_id', $taskId)
                ->where('action', 'reverted')->count());
            $this->assertSame($notificationCount + 1, DB::table('notifications')->count());
        } finally {
            $this->cleanup($taskId, $project, [$manager, $assignee], $createdRoles);
        }
    }

    private function requireDisposableMariaDb(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Row-lock lifecycle concurrency requires an isolated MySQL/MariaDB database.');
        }

        if (preg_match('/^task_management_phase2d_[a-z0-9_]+$/', DB::getDatabaseName()) !== 1) {
            $this->markTestSkipped('Lifecycle concurrency is restricted to a disposable Phase 2D QA database.');
        }
    }

    private function fixtures(): array
    {
        $createdRoles = [];
        $managerRole = Role::query()->where('name', 'manager')->first();

        if (! $managerRole) {
            $managerRole = Role::query()->create(['name' => 'manager', 'label' => 'Manager']);
            $createdRoles[] = $managerRole;
        }

        $memberRole = Role::query()->where('name', 'team_member')->first();

        if (! $memberRole) {
            $memberRole = Role::query()->create(['name' => 'team_member', 'label' => 'Team Member']);
            $createdRoles[] = $memberRole;
        }

        $manager = User::factory()->create(['role_id' => $managerRole->id]);
        $assignee = User::factory()->create(['role_id' => $memberRole->id]);
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $project->members()->attach($assignee->id);

        return [$manager, $project, $assignee, $createdRoles];
    }

    private function task(Project $project, User $assignee): Task
    {
        return Task::query()->create([
            'project_id' => $project->id,
            'title' => 'MariaDB canonical lifecycle task',
            'assignee_id' => $assignee->id,
            'priority' => 'High',
            'status' => 'In Progress',
            'progress' => 70,
        ]);
    }

    private function race(string $operation, Task $task, User $actor): array
    {
        $readyFile = tempnam(sys_get_temp_dir(), 'task-lifecycle-lock-');

        if ($readyFile === false) {
            $this->fail('Unable to allocate the lifecycle concurrency barrier file.');
        }

        unlink($readyFile);

        try {
            $first = $this->worker($operation, $task, $actor, '1', 1500, $readyFile);
            $first->start();
            $this->waitForLock($first, $readyFile);

            $second = $this->worker($operation, $task, $actor, '2');
            $second->start();
            usleep(200_000);
            $this->assertTrue($second->isRunning(), 'The second connection should wait for the canonical task row lock.');

            $first->wait();
            $second->wait();
            $this->assertTrue($first->isSuccessful(), $first->getErrorOutput().$first->getOutput());
            $this->assertTrue($second->isSuccessful(), $second->getErrorOutput().$second->getOutput());
            $firstResult = $this->decodeWorkerResult($first);
            $secondResult = $this->decodeWorkerResult($second);
            $this->assertSame(DB::getDatabaseName(), $firstResult['database']);
            $this->assertSame(DB::getDatabaseName(), $secondResult['database']);

            return [$firstResult, $secondResult];
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
                $this->fail('The first lifecycle worker exited before acquiring its row lock: '
                    .$worker->getErrorOutput().$worker->getOutput());
            }

            usleep(20_000);
        }

        $this->assertFileExists($readyFile, 'The first lifecycle worker did not acquire its task row lock in time.');
    }

    private function decodeWorkerResult(Process $worker): array
    {
        $output = trim($worker->getOutput());

        if (preg_match('/(\{[^\r\n]+\})$/', $output, $matches) !== 1) {
            $this->fail('Lifecycle worker did not return a JSON result: '.json_encode($output));
        }

        return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
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
