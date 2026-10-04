<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskLifecycleService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class R51ProjectWriterMariaDbConcurrencyTest extends TestCase
{
    private string $directory;

    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql' || ! str_starts_with(DB::getDatabaseName(), 'task_management_r51_concurrency_')) {
            $this->markTestSkipped('Requires an isolated R5.1 MariaDB schema.');
        }
        $this->seed();
        $this->directory = sys_get_temp_dir().'/r51-'.bin2hex(random_bytes(8));
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

    private function actor(string $role): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->value('id')]);
    }

    private function start(array $spec): array
    {
        $name = $this->directory.'/'.count($this->workers);
        $spec += ['ready' => $name.'-ready', 'release' => $name.'-release', 'result' => $name.'-result'];
        file_put_contents($name.'-spec', json_encode($spec, JSON_THROW_ON_ERROR));
        $worker = new Process([PHP_BINARY, base_path('tests/Support/r51_project_writer.php'), $name.'-spec'], base_path(), timeout: 30);
        $worker->start();
        $this->workers[] = $worker;

        return [$worker, $spec];
    }

    private function ready(array $job): void
    {
        $until = microtime(true) + 12;
        while (! file_exists($job[1]['ready']) && $job[0]->isRunning() && microtime(true) < $until) {
            usleep(10000);
        }
        $this->assertFileExists($job[1]['ready'], $job[0]->getErrorOutput().$job[0]->getOutput());
    }

    private function finish(array $job): array
    {
        $job[0]->wait();
        $this->assertTrue($job[0]->isSuccessful(), $job[0]->getErrorOutput().$job[0]->getOutput());

        $result = json_decode(file_get_contents($job[1]['result']), true, flags: JSON_THROW_ON_ERROR);
        $this->assertLessThanOrEqual(1, $result['transaction_attempts'], 'Coordinated writers must not rely on deadlock retries.');

        return $result;
    }

    private function waiting(): void
    {
        $until = microtime(true) + 12;
        do {
            if (str_contains(DB::select('SHOW ENGINE INNODB STATUS')[0]->Status, 'LOCK WAIT')) {
                return;
            }
            usleep(10000);
        } while (microtime(true) < $until);
        file_put_contents(base_path('output/r5-1/wait-diagnostic.json'), json_encode(['processes' => DB::select('SHOW PROCESSLIST'), 'locks' => DB::select('SHOW ENGINE INNODB STATUS'), 'results' => array_map(fn ($w) => [$w->getOutput(), $w->getErrorOutput()], $this->workers)]));
        $this->fail('The competing connection never reached an actual InnoDB lock wait.');
    }

    public function test_stale_pm_cannot_restore_ownership(): void
    {
        $manager = $this->actor('manager');
        $old = $this->actor('project_manager');
        $new = $this->actor('project_manager');
        $project = Project::factory()->create(['project_manager_id' => $old->id]);
        $stale = $this->start(['actor' => $old->id, 'project' => $project->id, 'pause' => 'binding', 'method' => 'PUT', 'path' => "/projects/{$project->id}", 'data' => ['name' => 'Obsolete overwrite', 'status' => 'active', 'project_manager_id' => $old->id]]);
        $this->ready($stale);
        $replacement = $this->finish($this->start(['actor' => $manager->id, 'method' => 'PUT', 'path' => "/projects/{$project->id}", 'data' => ['name' => $project->name, 'status' => 'active', 'project_manager_id' => $new->id]]));
        $this->assertSame(200, $replacement['status']);
        file_put_contents($stale[1]['release'], 'go');
        $this->assertSame(403, $this->finish($stale)['status']);
        $this->assertSame($new->id, $project->fresh()->project_manager_id);
        $this->assertSame($project->name, $project->fresh()->name);
        $this->assertSame(1, $project->histories()->count());
    }

    public function test_replacement_lock_blocks_pm_then_rejects_obsolete_owner(): void
    {
        $manager = $this->actor('manager');
        $old = $this->actor('project_manager');
        $new = $this->actor('project_manager');
        $project = Project::factory()->create(['project_manager_id' => $old->id]);
        $replacement = $this->start(['actor' => $manager->id, 'pause' => 'locked-project', 'method' => 'PUT', 'path' => "/projects/{$project->id}", 'data' => ['name' => $project->name, 'status' => 'active', 'project_manager_id' => $new->id]]);
        $this->ready($replacement);
        $stale = $this->start(['actor' => $old->id, 'method' => 'PUT', 'path' => "/projects/{$project->id}", 'data' => ['name' => 'Blocked overwrite', 'status' => 'active', 'project_manager_id' => $old->id]]);
        $this->waiting();
        file_put_contents($replacement[1]['release'], 'go');
        $this->assertSame(200, $this->finish($replacement)['status']);
        $this->assertSame(403, $this->finish($stale)['status']);
        $this->assertSame($new->id, $project->fresh()->project_manager_id);
    }

    public function test_deactivated_actor_cannot_commit_stale_project_edit(): void
    {
        $this->revokedActor(false);
    }

    public function test_role_revocation_cannot_commit_stale_project_edit(): void
    {
        $this->revokedActor(true);
    }

    private function revokedActor(bool $role): void
    {
        $manager = $this->actor('manager');
        $pm = $this->actor('project_manager');
        $project = Project::factory()->create(['project_manager_id' => $pm->id, 'status' => 'archived']);
        $stale = $this->start(['actor' => $pm->id, 'project' => $project->id, 'pause' => 'binding', 'method' => 'PUT', 'path' => "/projects/{$project->id}", 'data' => ['name' => 'Revoked overwrite', 'status' => 'archived', 'project_manager_id' => $pm->id]]);
        $this->ready($stale);
        $path = "/team-management/{$pm->id}".($role ? '' : '/deactivate');
        $data = $role ? ['name' => $pm->name, 'email' => $pm->email, 'role_id' => Role::where('name', 'team_member')->value('id')] : [];
        $result = $this->finish($this->start(['actor' => $manager->id, 'method' => $role ? 'PUT' : 'POST', 'path' => $path, 'data' => $data]));
        $this->assertSame(200, $result['status']);
        file_put_contents($stale[1]['release'], 'go');
        $this->assertSame(403, $this->finish($stale)['status']);
        $this->assertSame($project->name, $project->fresh()->name);
        $this->assertSame(0, $project->histories()->count());
    }

    public function test_delete_first_rejects_concurrent_create(): void
    {
        $this->deleteCreate(true);
    }

    public function test_create_first_rejects_concurrent_delete(): void
    {
        $this->deleteCreate(false);
    }

    private function deleteCreate(bool $deleteFirst): void
    {
        $manager = $this->actor('manager');
        $creator = $this->actor('manager');
        $pm = $this->actor('project_manager');
        $member = $this->actor('team_member');
        $project = Project::factory()->create(['project_manager_id' => $pm->id]);
        $project->members()->attach($member->id);
        $delete = ['actor' => $manager->id, 'method' => 'DELETE', 'path' => "/projects/{$project->id}", 'data' => []];
        $create = ['actor' => $creator->id, 'method' => 'POST', 'path' => '/tasks', 'data' => ['title' => 'Race work', 'project_id' => $project->id, 'assignee_id' => $member->id, 'reviewer_id' => $pm->id, 'priority' => 'Medium']];
        $first = $this->start(($deleteFirst ? $delete : $create) + ['pause' => 'locked-project']);
        $this->ready($first);
        $second = $this->start($deleteFirst ? $create : $delete);
        $this->waiting();
        file_put_contents($first[1]['release'], 'go');
        $this->assertSame(200, $this->finish($first)['status']);
        $this->assertSame($deleteFirst ? 404 : 409, $this->finish($second)['status']);
        $this->assertSame($deleteFirst, Project::withTrashed()->findOrFail($project->id)->trashed());
        $this->assertSame($deleteFirst ? 0 : 1, Task::where('project_id', $project->id)->count());
        $this->assertSame($deleteFirst ? 1 : 0, $project->histories()->where('action', 'deleted')->count());
    }

    public function test_delete_first_rejects_move_into_deleted_destination(): void
    {
        $this->deleteMove(true);
    }

    public function test_move_first_rejects_destination_deletion(): void
    {
        $this->deleteMove(false);
    }

    private function deleteMove(bool $deleteFirst): void
    {
        $deleter = $this->actor('manager');
        $mover = $this->actor('manager');
        $pm = $this->actor('project_manager');
        $member = $this->actor('team_member');
        $source = Project::factory()->create(['project_manager_id' => $pm->id]);
        $destination = Project::factory()->create(['project_manager_id' => $pm->id]);
        $source->members()->attach($member->id);
        $destination->members()->attach($member->id);
        $task = app(TaskLifecycleService::class)->create(['title' => 'Move race', 'priority' => 'Medium', 'project_id' => $source->id, 'assignee_id' => $member->id, 'reviewer_id' => $pm->id], $mover);
        $delete = ['actor' => $deleter->id, 'method' => 'DELETE', 'path' => "/projects/{$destination->id}", 'data' => []];
        $move = ['actor' => $mover->id, 'method' => 'PUT', 'path' => "/tasks/{$task->id}", 'data' => ['project_id' => $destination->id, 'expected_version' => $task->lock_version]];
        $first = $this->start(($deleteFirst ? $delete : $move) + ['pause' => 'locked-project']);
        $this->ready($first);
        $second = $this->start($deleteFirst ? $move : $delete);
        $this->waiting();
        file_put_contents($first[1]['release'], 'go');
        $this->assertSame(200, $this->finish($first)['status']);
        $this->assertSame($deleteFirst ? 404 : 409, $this->finish($second)['status']);
        $this->assertSame($deleteFirst ? $source->id : $destination->id, $task->fresh()->project_id);
        $this->assertSame($deleteFirst, Project::withTrashed()->findOrFail($destination->id)->trashed());
    }

    public function test_delete_failure_rolls_back_history_and_preserves_membership(): void
    {
        $manager = $this->actor('manager');
        $member = $this->actor('team_member');
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $project->members()->attach($member->id);
        $result = $this->finish($this->start(['actor' => $manager->id, 'fail_delete' => true, 'method' => 'DELETE', 'path' => "/projects/{$project->id}", 'data' => []]));
        $this->assertSame(500, $result['status']);
        $this->assertNotNull($project->fresh());
        $this->assertSame(0, $project->histories()->where('action', 'deleted')->count());
        $this->assertTrue($project->members()->whereKey($member->id)->exists());
    }
}
