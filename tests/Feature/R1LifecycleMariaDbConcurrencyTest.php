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

class R1LifecycleMariaDbConcurrencyTest extends TestCase
{
    public function test_concurrent_last_manager_deletion_preserves_at_least_one_manager(): void
    {
        $this->requireDisposableMariaDb();
        [$manager1, $manager2] = $this->setupTwoManagers();

        $readyFile = tempnam(sys_get_temp_dir(), 'r1-mgr-del-');
        if ($readyFile === false) {
            $this->fail('Unable to allocate concurrency barrier file.');
        }
        unlink($readyFile);

        try {
            // Manager 1 deletes Manager 2 (with 1200ms lock hold)
            $w1 = $this->worker('delete_user', $manager2->id, $manager1->id, '1', 1200, $readyFile);
            $w1->start();
            $this->waitForLock($w1, $readyFile);

            // Manager 2 concurrently tries to delete Manager 1
            $w2 = $this->worker('delete_user', $manager1->id, $manager2->id, '2');
            $w2->start();

            $w1->wait();
            $w2->wait();

            $this->assertTrue($w1->isSuccessful(), $w1->getErrorOutput().$w1->getOutput());
            $this->assertTrue($w2->isSuccessful(), $w2->getErrorOutput().$w2->getOutput());

            $r1 = $this->decodeWorkerResult($w1);
            $r2 = $this->decodeWorkerResult($w2);

            $results = [$r1['result'], $r2['result']];
            $this->assertContains('success', $results);
            $this->assertContains('conflict', $results);

            $conflict = $r1['result'] === 'conflict' ? $r1 : $r2;
            $this->assertSame(409, $conflict['status_code']);
            $this->assertStringContainsString('last active manager', $conflict['message']);

            // Invariant: Exactly 1 viable active manager remains
            $activeManagersCount = User::query()
                ->whereHas('role', fn ($q) => $q->where('name', 'manager'))
                ->where('active', true)
                ->whereNull('deleted_at')
                ->count();

            $this->assertSame(1, $activeManagersCount);
        } finally {
            if (isset($w1) && $w1->isRunning()) {
                $w1->stop();
            }
            if (isset($w2) && $w2->isRunning()) {
                $w2->stop();
            }
            if (file_exists($readyFile)) {
                unlink($readyFile);
            }
            $this->cleanupUsers([$manager1, $manager2]);
        }
    }

    public function test_concurrent_last_manager_deactivation_preserves_at_least_one_manager(): void
    {
        $this->requireDisposableMariaDb();
        [$manager1, $manager2] = $this->setupTwoManagers();

        $readyFile = tempnam(sys_get_temp_dir(), 'r1-mgr-deact-');
        if ($readyFile === false) {
            $this->fail('Unable to allocate concurrency barrier file.');
        }
        unlink($readyFile);

        try {
            // Manager 1 deactivates Manager 2 (with 1200ms lock hold)
            $w1 = $this->worker('deactivate_user', $manager2->id, $manager1->id, '1', 1200, $readyFile);
            $w1->start();
            $this->waitForLock($w1, $readyFile);

            // Manager 2 concurrently tries to deactivate Manager 1
            $w2 = $this->worker('deactivate_user', $manager1->id, $manager2->id, '2');
            $w2->start();

            $w1->wait();
            $w2->wait();

            $this->assertTrue($w1->isSuccessful(), $w1->getErrorOutput().$w1->getOutput());
            $this->assertTrue($w2->isSuccessful(), $w2->getErrorOutput().$w2->getOutput());

            $r1 = $this->decodeWorkerResult($w1);
            $r2 = $this->decodeWorkerResult($w2);

            $results = [$r1['result'], $r2['result']];
            $this->assertContains('success', $results);
            $this->assertContains('conflict', $results);

            $conflict = $r1['result'] === 'conflict' ? $r1 : $r2;
            $this->assertSame(409, $conflict['status_code']);
            $this->assertStringContainsString('last active manager', $conflict['message']);

            // Invariant: Exactly 1 viable active manager remains
            $activeManagersCount = User::query()
                ->whereHas('role', fn ($q) => $q->where('name', 'manager'))
                ->where('active', true)
                ->whereNull('deleted_at')
                ->count();

            $this->assertSame(1, $activeManagersCount);
        } finally {
            if (isset($w1) && $w1->isRunning()) {
                $w1->stop();
            }
            if (isset($w2) && $w2->isRunning()) {
                $w2->stop();
            }
            if (file_exists($readyFile)) {
                unlink($readyFile);
            }
            $this->cleanupUsers([$manager1, $manager2]);
        }
    }

    public function test_concurrent_last_manager_delete_and_demote_preserves_at_least_one_manager(): void
    {
        $this->requireDisposableMariaDb();
        [$manager1, $manager2] = $this->setupTwoManagers();

        $readyFile = tempnam(sys_get_temp_dir(), 'r1-mgr-del-demote-');
        if ($readyFile === false) {
            $this->fail('Unable to allocate concurrency barrier file.');
        }
        unlink($readyFile);

        try {
            // Manager 1 deletes Manager 2 (with 1200ms lock hold)
            $w1 = $this->worker('delete_user', $manager2->id, $manager1->id, '1', 1200, $readyFile);
            $w1->start();
            $this->waitForLock($w1, $readyFile);

            // Manager 2 concurrently tries to demote Manager 1 to team member
            $w2 = $this->worker('demote_user', $manager1->id, $manager2->id, '2');
            $w2->start();

            $w1->wait();
            $w2->wait();

            $this->assertTrue($w1->isSuccessful(), $w1->getErrorOutput().$w1->getOutput());
            $this->assertTrue($w2->isSuccessful(), $w2->getErrorOutput().$w2->getOutput());

            $r1 = $this->decodeWorkerResult($w1);
            $r2 = $this->decodeWorkerResult($w2);

            $results = [$r1['result'], $r2['result']];
            $this->assertContains('success', $results);
            $this->assertContains('conflict', $results);

            $conflict = $r1['result'] === 'conflict' ? $r1 : $r2;
            $this->assertSame(409, $conflict['status_code']);
            $this->assertStringContainsString('last active manager', $conflict['message']);

            // Invariant: Exactly 1 viable active manager remains
            $activeManagersCount = User::query()
                ->whereHas('role', fn ($q) => $q->where('name', 'manager'))
                ->where('active', true)
                ->whereNull('deleted_at')
                ->count();

            $this->assertSame(1, $activeManagersCount);
        } finally {
            if (isset($w1) && $w1->isRunning()) {
                $w1->stop();
            }
            if (isset($w2) && $w2->isRunning()) {
                $w2->stop();
            }
            if (file_exists($readyFile)) {
                unlink($readyFile);
            }
            $this->cleanupUsers([$manager1, $manager2]);
        }
    }

    public function test_concurrent_last_manager_deactivate_and_demote_preserves_at_least_one_manager(): void
    {
        $this->requireDisposableMariaDb();
        [$manager1, $manager2] = $this->setupTwoManagers();

        $readyFile = tempnam(sys_get_temp_dir(), 'r1-mgr-deact-demote-');
        if ($readyFile === false) {
            $this->fail('Unable to allocate concurrency barrier file.');
        }
        unlink($readyFile);

        try {
            // Manager 1 deactivates Manager 2 (with 1200ms lock hold)
            $w1 = $this->worker('deactivate_user', $manager2->id, $manager1->id, '1', 1200, $readyFile);
            $w1->start();
            $this->waitForLock($w1, $readyFile);

            // Manager 2 concurrently tries to demote Manager 1 to team member
            $w2 = $this->worker('demote_user', $manager1->id, $manager2->id, '2');
            $w2->start();

            $w1->wait();
            $w2->wait();

            $this->assertTrue($w1->isSuccessful(), $w1->getErrorOutput().$w1->getOutput());
            $this->assertTrue($w2->isSuccessful(), $w2->getErrorOutput().$w2->getOutput());

            $r1 = $this->decodeWorkerResult($w1);
            $r2 = $this->decodeWorkerResult($w2);

            $results = [$r1['result'], $r2['result']];
            $this->assertContains('success', $results);
            $this->assertContains('conflict', $results);

            $conflict = $r1['result'] === 'conflict' ? $r1 : $r2;
            $this->assertSame(409, $conflict['status_code']);
            $this->assertStringContainsString('last active manager', $conflict['message']);

            // Invariant: Exactly 1 viable active manager remains
            $activeManagersCount = User::query()
                ->whereHas('role', fn ($q) => $q->where('name', 'manager'))
                ->where('active', true)
                ->whereNull('deleted_at')
                ->count();

            $this->assertSame(1, $activeManagersCount);
        } finally {
            if (isset($w1) && $w1->isRunning()) {
                $w1->stop();
            }
            if (isset($w2) && $w2->isRunning()) {
                $w2->stop();
            }
            if (file_exists($readyFile)) {
                unlink($readyFile);
            }
            $this->cleanupUsers([$manager1, $manager2]);
        }
    }

    public function test_concurrent_project_manager_replacement_is_deterministic_and_preserves_reviewer_integrity(): void
    {
        $this->requireDisposableMariaDb();
        $pmRole = Role::firstOrCreate(['name' => 'project_manager'], ['label' => 'Project Manager']);
        $memberRole = Role::firstOrCreate(['name' => 'team_member'], ['label' => 'Team Member']);

        $pm1 = User::factory()->create(['role_id' => $pmRole->id]);
        $pm2 = User::factory()->create(['role_id' => $pmRole->id]);
        $pm3 = User::factory()->create(['role_id' => $pmRole->id]);
        $member = User::factory()->create(['role_id' => $memberRole->id]);

        $project = Project::factory()->create(['project_manager_id' => $pm1->id]);
        $project->members()->attach([$pm1->id, $pm2->id, $pm3->id, $member->id]);

        $task = Task::query()->create([
            'project_id' => $project->id,
            'title' => 'PM Replacement Concurrency Task',
            'assignee_id' => $member->id,
            'priority' => 'High',
            'status' => TaskState::InReview->value,
            'progress' => 80,
        ]);
        $task->forceFill(['reviewer_id' => $pm1->id])->save();

        $readyFile = tempnam(sys_get_temp_dir(), 'r1-pm-replace-');
        if ($readyFile === false) {
            $this->fail('Unable to allocate concurrency barrier file.');
        }
        unlink($readyFile);

        try {
            // Worker 1 replaces PM with PM2 (holds project lock for 1200ms)
            $w1 = $this->worker('replace_pm', $project->id, $pm1->id, '1', 1200, $readyFile, ['new_pm_id' => $pm2->id]);
            $w1->start();
            $this->waitForLock($w1, $readyFile);

            // Worker 2 replaces PM with PM3 concurrently
            $w2 = $this->worker('replace_pm', $project->id, $pm1->id, '2', 0, null, ['new_pm_id' => $pm3->id]);
            $w2->start();

            $w1->wait();
            $w2->wait();

            $this->assertTrue($w1->isSuccessful(), $w1->getErrorOutput().$w1->getOutput());
            $this->assertTrue($w2->isSuccessful(), $w2->getErrorOutput().$w2->getOutput());

            $r1 = $this->decodeWorkerResult($w1);
            $r2 = $this->decodeWorkerResult($w2);

            $this->assertSame('success', $r1['result']);
            $this->assertSame('success', $r2['result']);

            // The final state must be PM3, and reviewer_id must match PM3
            $freshProject = $project->fresh();
            $freshTask = $task->fresh();

            $this->assertSame($pm3->id, $freshProject->project_manager_id);
            $this->assertSame($pm3->id, $freshTask->reviewer_id);

            // Verify task event history
            $reassignedEvents = TaskEvent::query()
                ->where('task_id', $task->id)
                ->where('event_type', TaskEventRecorder::REVIEWER_REASSIGNED)
                ->get();

            // Both transitions ran sequentially without corruption
            $this->assertCount(2, $reassignedEvents);

            // Verify self-review conflict rejection:
            // If we attempt to replace PM with $member (who is the task's assignee), it must fail with 422
            $wSelf = $this->worker('replace_pm', $project->id, $pm3->id, 'self', 0, null, ['new_pm_id' => $member->id]);
            $wSelf->run();
            $rSelf = $this->decodeWorkerResult($wSelf);

            $this->assertSame('conflict', $rSelf['result']);
            $this->assertSame(422, $rSelf['status_code']);
            $this->assertStringContainsString('prohibits self-review', $rSelf['message']);

            // State must be completely uncorrupted
            $this->assertSame($pm3->id, $project->fresh()->project_manager_id);
            $this->assertSame($pm3->id, $task->fresh()->reviewer_id);
        } finally {
            if (isset($w1) && $w1->isRunning()) {
                $w1->stop();
            }
            if (isset($w2) && $w2->isRunning()) {
                $w2->stop();
            }
            if (file_exists($readyFile)) {
                unlink($readyFile);
            }
            TaskHistory::query()->where('task_id', $task->id)->delete();
            TaskEvent::query()->where('task_id', $task->id)->delete();
            $task->forceDelete();
            $project->members()->detach();
            $project->forceDelete();
            $this->cleanupUsers([$pm1, $pm2, $pm3, $member]);
        }
    }

    public function test_concurrent_user_deactivation_and_task_assignment_race_prevents_inactive_assignment(): void
    {
        $this->requireDisposableMariaDb();
        $managerRole = Role::firstOrCreate(['name' => 'manager'], ['label' => 'Manager']);
        $memberRole = Role::firstOrCreate(['name' => 'team_member'], ['label' => 'Team Member']);

        $manager = User::factory()->create(['role_id' => $managerRole->id]);
        $member = User::factory()->create(['role_id' => $memberRole->id, 'active' => true]);

        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $project->members()->attach([$manager->id, $member->id]);

        $readyFile = tempnam(sys_get_temp_dir(), 'r1-deact-race-');
        if ($readyFile === false) {
            $this->fail('Unable to allocate concurrency barrier file.');
        }
        unlink($readyFile);

        try {
            // Case A: Deactivation holds user lock, concurrent task creation tries to assign to member
            $w1 = $this->worker('deactivate_user_with_user_lock', $member->id, $manager->id, '1', 1200, $readyFile);
            $w1->start();
            $this->waitForLock($w1, $readyFile);

            $w2 = $this->worker('create_task', $member->id, $manager->id, '2', 0, null, ['project_id' => $project->id]);
            $w2->start();

            $w1->wait();
            $w2->wait();

            $this->assertTrue($w1->isSuccessful(), $w1->getErrorOutput().$w1->getOutput());
            $this->assertTrue($w2->isSuccessful(), $w2->getErrorOutput().$w2->getOutput());

            $r1 = $this->decodeWorkerResult($w1);
            $r2 = $this->decodeWorkerResult($w2);

            $this->assertSame('success', $r1['result']);
            $this->assertSame('conflict', $r2['result']);
            $this->assertSame(422, $r2['status_code']);
            $this->assertStringContainsString('active member', $r2['message']);

            // User must be inactive and have ZERO tasks assigned
            $this->assertFalse($member->fresh()->isActive());
            $this->assertSame(0, Task::query()->where('assignee_id', $member->id)->count());

            // Case B: Reverse race - reactivate member, create_task holds user lock, concurrent deactivation tries to deactivate
            User::query()->whereKey($member->id)->update(['active' => true]);
            $member->refresh();
            if (file_exists($readyFile)) {
                unlink($readyFile);
            }

            $wCreate = $this->worker('create_task', $member->id, $manager->id, 'create_hold', 1200, $readyFile, ['project_id' => $project->id]);
            $wCreate->start();
            $this->waitForLock($wCreate, $readyFile);

            $wDeact = $this->worker('deactivate_user_with_user_lock', $member->id, $manager->id, 'deact_wait');
            $wDeact->start();

            $wCreate->wait();
            $wDeact->wait();

            $this->assertTrue($wCreate->isSuccessful(), $wCreate->getErrorOutput().$wCreate->getOutput());
            $this->assertTrue($wDeact->isSuccessful(), $wDeact->getErrorOutput().$wDeact->getOutput());

            $rCreate = $this->decodeWorkerResult($wCreate);
            $rDeact = $this->decodeWorkerResult($wDeact);

            $this->assertSame('success', $rCreate['result']);
            $this->assertSame('conflict', $rDeact['result']);
            $this->assertSame(409, $rDeact['status_code']);
            $this->assertStringContainsString('active task assignments', $rDeact['message']);

            // The user must remain ACTIVE because they hold an active task assignment
            $this->assertTrue($member->fresh()->isActive());
            $this->assertSame(1, Task::query()->where('assignee_id', $member->id)->count());

        } finally {
            if (isset($w1) && $w1->isRunning()) {
                $w1->stop();
            }
            if (isset($w2) && $w2->isRunning()) {
                $w2->stop();
            }
            if (isset($wCreate) && $wCreate->isRunning()) {
                $wCreate->stop();
            }
            if (isset($wDeact) && $wDeact->isRunning()) {
                $wDeact->stop();
            }
            if (file_exists($readyFile)) {
                unlink($readyFile);
            }
            $taskIds = Task::query()->where('assignee_id', $member->id)->pluck('id');
            TaskHistory::query()->whereIn('task_id', $taskIds)->delete();
            TaskEvent::query()->whereIn('task_id', $taskIds)->delete();
            Task::query()->whereIn('id', $taskIds)->forceDelete();
            $project->members()->detach();
            $project->forceDelete();
            $this->cleanupUsers([$manager, $member]);
        }
    }

    private function setupTwoManagers(): array
    {
        $managerRole = Role::firstOrCreate(['name' => 'manager'], ['label' => 'Manager']);

        // Clean any existing active managers to ensure exactly 2 viable managers
        $existing = User::query()
            ->whereHas('role', fn ($q) => $q->where('name', 'manager'))
            ->where('active', true)
            ->whereNull('deleted_at')
            ->get();

        foreach ($existing as $ex) {
            DB::table('notifications')->where('notifiable_id', $ex->id)->delete();
            $ex->forceDelete();
        }

        $manager1 = User::factory()->create(['role_id' => $managerRole->id, 'active' => true]);
        $manager2 = User::factory()->create(['role_id' => $managerRole->id, 'active' => true]);

        return [$manager1, $manager2];
    }

    private function requireDisposableMariaDb(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Row-lock lifecycle concurrency requires an isolated MySQL/MariaDB database.');
        }

        if (preg_match('/^task_management_phase2(?:(?:d|6|7)|8)_[a-z0-9_]+$/', DB::getDatabaseName()) !== 1) {
            $this->markTestSkipped('Lifecycle concurrency is restricted to a disposable Phase 2D/2.6/2.7/28 QA database.');
        }
    }

    private function worker(
        string $operation,
        int $targetId,
        int $actorId,
        string $worker,
        int $holdMilliseconds = 0,
        ?string $readyFile = null,
        array $payload = [],
    ): Process {
        return new Process([
            PHP_BINARY,
            base_path('tests/Support/r1_lifecycle_concurrency_worker.php'),
            $operation,
            (string) $targetId,
            (string) $actorId,
            $worker,
            (string) $holdMilliseconds,
            $readyFile ?? '',
            json_encode($payload),
        ], base_path(), timeout: 20);
    }

    private function waitForLock(Process $worker, string $readyFile): void
    {
        $deadline = microtime(true) + 5;

        while (! file_exists($readyFile) && microtime(true) < $deadline) {
            if (! $worker->isRunning()) {
                $this->fail('Worker exited before acquiring lock: '.$worker->getErrorOutput().$worker->getOutput());
            }
            usleep(20_000);
        }

        $this->assertFileExists($readyFile, 'Worker did not acquire lock in time.');
    }

    private function decodeWorkerResult(Process $worker): array
    {
        $output = trim($worker->getOutput());

        if (preg_match('/(\{[^\r\n]+\})$/', $output, $matches) !== 1) {
            $this->fail('Worker did not return a JSON result: '.json_encode($output).' (err: '.$worker->getErrorOutput().')');
        }

        return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    }

    private function cleanupUsers(array $users): void
    {
        foreach ($users as $user) {
            if ($user && $user->exists) {
                DB::table('notifications')->where('notifiable_id', $user->id)->delete();
                $user->forceDelete();
            }
        }
    }
}
