<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkflowNotificationIntent;
use App\Services\TaskLifecycleService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class R52EligibilityMariaDbConcurrencyTest extends TestCase
{
    private array $workers = [];

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql' || ! str_starts_with(DB::getDatabaseName(), 'task_management_r52_concurrency_')) {
            $this->markTestSkipped('Requires disposable R52 MariaDB schema.');
        }
        $this->seed();
        $this->directory = sys_get_temp_dir().'/r52-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
        if (isset($this->directory)) {
            foreach (glob($this->directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->value('id')]);
    }

    private function start(array $spec): array
    {
        $name = $this->directory.'/'.count($this->workers);
        $spec += ['ready' => $name.'-ready', 'release' => $name.'-release', 'result' => $name.'-result'];
        file_put_contents($name.'-spec', json_encode($spec));
        $worker = new Process([PHP_BINARY, base_path('tests/Support/r52_writer.php'), $name.'-spec'], base_path(), timeout: 40);
        $worker->start();
        $this->workers[] = $worker;

        return [$worker, $spec];
    }

    private function ready(array $job): void
    {
        $until = microtime(true) + 15;
        while (! file_exists($job[1]['ready']) && $job[0]->isRunning() && microtime(true) < $until) {
            usleep(10000);
        }
        $this->assertFileExists($job[1]['ready'], $job[0]->getOutput().$job[0]->getErrorOutput());
    }

    private function finish(array $job): array
    {
        $job[0]->wait();
        $this->assertTrue($job[0]->isSuccessful(), $job[0]->getOutput().$job[0]->getErrorOutput());

        return json_decode(file_get_contents($job[1]['result']), true, flags: JSON_THROW_ON_ERROR);
    }

    private function waiting(): void
    {
        $until = microtime(true) + 15;
        do {
            if (str_contains(DB::select('SHOW ENGINE INNODB STATUS')[0]->Status, 'LOCK WAIT')) {
                $this->addToAssertionCount(1);

                return;
            }
            usleep(10000);
        } while (microtime(true) < $until);
        $this->fail('No actual InnoDB lock wait observed.');
    }

    public function test_stale_create_rejects_deactivated_reviewer(): void
    {
        $this->reviewerRace(false, false, false);
    }

    public function test_stale_reassignment_rejects_deactivated_reviewer(): void
    {
        $this->reviewerRace(true, false, false);
    }

    public function test_stale_create_rejects_demoted_reviewer(): void
    {
        $this->reviewerRace(false, false, true);
    }

    public function test_stale_reassignment_rejects_demoted_reviewer(): void
    {
        $this->reviewerRace(true, false, true);
    }

    public function test_create_lock_serializes_deactivation(): void
    {
        $this->reviewerRace(false, true, false);
    }

    public function test_reassignment_lock_serializes_deactivation(): void
    {
        $this->reviewerRace(true, true, false);
    }

    private function reviewerRace(bool $reassign, bool $writerFirst, bool $demote): void
    {
        $manager = $this->user('project_manager');
        $lifecycleActor = $this->user('manager');
        $reviewer = $this->user('manager');
        $member = $this->user('team_member');
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $project->members()->attach($member->id);
        $task = $reassign ? Task::query()->create(['title' => 'Reassignment race', 'priority' => 'Medium', 'project_id' => $project->id, 'assignee_id' => $member->id, 'status' => 'not_started']) : null;
        $task?->forceFill(['reviewer_id' => $manager->id])->save();
        $writer = ['actor' => $manager->id, 'method' => 'POST', 'path' => $reassign ? "/tasks/{$task->id}/reviewer/reassign" : '/tasks',
            'data' => $reassign ? ['reviewer_id' => $reviewer->id, 'expected_version' => $task->lock_version] : ['title' => 'Reviewer race', 'project_id' => $project->id, 'assignee_id' => $member->id, 'reviewer_id' => $reviewer->id, 'priority' => 'Medium']];
        $lifecycle = ['actor' => $lifecycleActor->id, 'method' => $demote ? 'PUT' : 'POST', 'path' => "/team-management/{$reviewer->id}".($demote ? '' : '/deactivate'),
            'data' => $demote ? ['name' => $reviewer->name, 'email' => $reviewer->email, 'role_id' => Role::where('name', 'team_member')->value('id')] : []];
        $first = $this->start($writer + ['pause' => $writerFirst ? 'locked-accounts' : 'before-accounts']);
        $this->ready($first);
        $second = $this->start($lifecycle);
        if ($writerFirst) {
            $this->waiting();
            file_put_contents($first[1]['release'], 'go');
            $this->assertSame(200, $this->finish($first)['status']);
            $this->assertSame(409, $this->finish($second)['status']);
            $this->assertTrue($reviewer->fresh()->isActive());
        } else {
            $this->assertSame(200, $this->finish($second)['status']);
            file_put_contents($first[1]['release'], 'go');
            $this->assertSame(422, $this->finish($first)['status']);
            $this->assertSame(0, Task::where('reviewer_id', $reviewer->id)->count());
        }
    }

    public function test_concurrent_project_identity_has_one_winner(): void
    {
        $a = $this->user('manager');
        $b = $this->user('manager');
        $first = $this->start(['actor' => $a->id, 'method' => 'POST', 'path' => '/projects', 'pause' => 'unique', 'data' => ['name' => 'R52 Same Name', 'status' => 'active']]);
        $this->ready($first);
        $this->assertSame(200, $this->finish($this->start(['actor' => $b->id, 'method' => 'POST', 'path' => '/projects', 'data' => ['name' => 'r52 same name', 'status' => 'active']]))['status']);
        file_put_contents($first[1]['release'], 'go');
        $this->assertSame(422, $this->finish($first)['status']);
        $this->assertSame(1, Project::where('name', 'R52 Same Name')->count());
    }

    public function test_reopen_rejects_deactivated_retained_reviewer(): void
    {
        $this->adjacentReviewerRace(true, false);
    }

    public function test_reopen_serializes_retained_reviewer_deactivation(): void
    {
        $this->adjacentReviewerRace(true, true);
    }

    public function test_project_manager_replacement_rejects_deactivated_reviewer(): void
    {
        $this->adjacentReviewerRace(false, false);
    }

    public function test_project_manager_replacement_serializes_reviewer_deactivation(): void
    {
        $this->adjacentReviewerRace(false, true);
    }

    private function adjacentReviewerRace(bool $reopen, bool $writerFirst): void
    {
        $actor = $this->user($reopen ? 'project_manager' : 'manager');
        $oldPm = $reopen ? $actor : $this->user('project_manager');
        $reviewer = $this->user($reopen ? 'manager' : 'project_manager');
        $lifecycleActor = $this->user('manager');
        $member = $this->user('team_member');
        $project = Project::factory()->create(['project_manager_id' => $oldPm->id]);
        $project->members()->attach($member->id);
        $task = app(TaskLifecycleService::class)->create(['title' => 'Adjacent reviewer race', 'priority' => 'Medium',
            'project_id' => $project->id, 'assignee_id' => $member->id, 'reviewer_id' => $reopen ? $reviewer->id : $oldPm->id], $actor);
        if ($reopen) {
            $this->actingAs($member)->postTaskTransitionJson("/tasks/{$task->id}/start")->assertOk();
            $this->postTaskTransitionJson("/tasks/{$task->id}/submit", ['submission_note' => 'Ready'])->assertOk();
            $this->actingAs($reviewer)->postTaskTransitionJson("/tasks/{$task->id}/review/start")->assertOk();
            $this->postTaskTransitionJson("/tasks/{$task->id}/approve")->assertOk();
        }
        $writer = ['actor' => $actor->id, 'method' => $reopen ? 'POST' : 'PUT',
            'path' => $reopen ? "/tasks/{$task->id}/reopen" : "/projects/{$project->id}",
            'data' => $reopen ? ['expected_version' => $task->fresh()->lock_version, 'reopen_reason' => 'Correct approved result', 'revision_due_date' => now()->addDays(4)->toDateString()]
                : ['name' => $project->name, 'status' => 'active', 'project_manager_id' => $reviewer->id]];
        $first = $this->start($writer + ['pause' => $writerFirst ? 'locked-accounts' : 'before-accounts']);
        $this->ready($first);
        $second = $this->start(['actor' => $lifecycleActor->id, 'method' => 'POST', 'path' => "/team-management/{$reviewer->id}/deactivate", 'data' => []]);
        if ($writerFirst) {
            $this->waiting();
            file_put_contents($first[1]['release'], 'go');
            $this->assertSame(200, $this->finish($first)['status']);
            $this->assertSame(409, $this->finish($second)['status']);
            $this->assertTrue($reviewer->fresh()->isActive());
        } else {
            $this->assertSame(200, $this->finish($second)['status']);
            file_put_contents($first[1]['release'], 'go');
            $this->assertSame(422, $this->finish($first)['status']);
            $this->assertSame($oldPm->id, $project->fresh()->project_manager_id);
            $this->assertSame($reopen ? 'Completed' : 'Not Started', $task->fresh()->status);
        }
    }

    public function test_process_crash_after_commit_is_recovered_by_competing_consumers_once(): void
    {
        $manager = $this->user('manager');
        $member = $this->user('team_member');
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $project->members()->attach($member->id);
        $creator = $this->start(['actor' => $manager->id, 'method' => 'POST', 'path' => '/tasks', 'pause' => 'before-delivery',
            'data' => ['title' => 'Crash recovery', 'project_id' => $project->id, 'assignee_id' => $member->id, 'reviewer_id' => $manager->id, 'priority' => 'Medium']]);
        $this->ready($creator);
        $task = Task::where('project_id', $project->id)->where('title', 'Crash recovery')->firstOrFail();
        $intent = WorkflowNotificationIntent::where('task_id', $task->id)->firstOrFail();
        $this->assertSame('pending', $intent->status);
        $this->assertFalse(DB::table('notifications')->where('id', $intent->id)->exists());
        $this->assertTrue($creator[0]->isRunning());
        if (PHP_OS_FAMILY === 'Windows') {
            (new Process(['taskkill', '/PID', (string) $creator[0]->getPid(), '/T', '/F']))->mustRun();
        } else {
            $creator[0]->signal(9);
        }
        $creator[0]->wait();
        $first = $this->start(['actor' => $manager->id, 'retry' => $intent->id, 'pause' => 'locked-intent']);
        $this->ready($first);
        $second = $this->start(['actor' => $manager->id, 'retry' => $intent->id]);
        $this->waiting();
        file_put_contents($first[1]['release'], 'go');
        $this->assertSame(200, $this->finish($first)['status']);
        $this->assertSame(200, $this->finish($second)['status']);
        $this->assertSame(1, DB::table('notifications')->where('id', $intent->id)->count());
        $this->assertSame('delivered', $intent->fresh()->status);
    }
}
