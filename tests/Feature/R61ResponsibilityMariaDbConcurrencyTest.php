<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Services\TaskAssignmentCandidateService;
use App\Services\TaskLifecycleService;
use Illuminate\Support\Facades\DB;
use Tests\Support\R61ProcessHarness;
use Tests\TestCase;

class R61ResponsibilityMariaDbConcurrencyTest extends TestCase
{
    use R61ProcessHarness;

    public function test_role_and_pm_changes_serialize_with_assignment_start_and_submit_in_both_orders(): void
    {
        foreach (['role', 'pm'] as $administration) {
            foreach (['reassign', 'start', 'submit'] as $operation) {
                foreach ([false, true] as $adminFirst) {
                    $manager = $this->user('manager');
                    $pm = $this->user('project_manager');
                    $assignee = $administration === 'pm' ? $pm : $this->user('team_member');
                    $other = $this->user('team_member');
                    $replacement = $this->user('project_manager');
                    $project = Project::factory()->create(['project_manager_id' => $pm->id]);
                    $project->members()->attach(array_unique([$pm->id, $assignee->id, $other->id]));
                    $task = app(TaskLifecycleService::class)->create(['title' => 'Concurrent responsibility', 'project_id' => $project->id,
                        'assignee_id' => $assignee->id, 'reviewer_id' => $manager->id, 'priority' => 'High'], $manager);
                    if ($operation === 'submit') {
                        $this->assertSame(200, $this->finish($this->start(['actor' => $assignee->id, 'method' => 'POST',
                            'path' => '/tasks/'.$task->id.'/start', 'data' => ['expected_version' => $task->lock_version]]))['status']);
                    }
                    $task->refresh();
                    $admin = $administration === 'role'
                        ? ['actor' => $manager->id, 'method' => 'PUT', 'path' => '/team-management/'.$assignee->id,
                            'data' => ['name' => $assignee->name, 'email' => $assignee->email,
                                'role_id' => Role::where('name', 'project_manager')->value('id')]]
                        : ['actor' => $manager->id, 'method' => 'PUT', 'path' => '/projects/'.$project->id,
                            'data' => ['name' => $project->name, 'status' => 'active', 'project_manager_id' => $replacement->id]];
                    $work = ['actor' => $operation === 'reassign' ? $manager->id : $assignee->id,
                        'method' => $operation === 'reassign' ? 'PUT' : 'POST',
                        'path' => '/tasks/'.$task->id.($operation === 'reassign' ? '' : '/'.$operation),
                        'data' => ['expected_version' => $task->lock_version] + ($operation === 'reassign' ? ['assignee_id' => $other->id] : [])];
                    $first = $this->start(($adminFirst ? $admin : $work) + ['pause' => 'locked-accounts']);
                    $this->ready($first);
                    $second = $this->start($adminFirst ? $work : $admin);
                    $until = microtime(true) + 15;
                    do {
                        $wait = str_contains(DB::select('SHOW ENGINE INNODB STATUS')[0]->Status, 'LOCK WAIT');
                        if ($wait) {
                            break;
                        }
                        usleep(10000);
                    } while (microtime(true) < $until);
                    $this->assertTrue($wait, 'Actual account lock contention required.');
                    file_put_contents($first[1]['release'], 'go');
                    $one = $this->finish($first)['status'];
                    $two = $this->finish($second)['status'];
                    $adminStatus = $adminFirst ? $one : $two;
                    $workStatus = $adminFirst ? $two : $one;
                    $this->assertSame(200, $workStatus);
                    $this->assertSame($operation === 'reassign' && ! $adminFirst ? 200 : 409, $adminStatus);
                    $fresh = Task::with(['assignee.role', 'project'])->findOrFail($task->id);
                    $this->assertTrue(app(TaskAssignmentCandidateService::class)->canExecuteInProject($fresh->assignee, $fresh->project));
                    $this->assertSame((int) $task->lock_version + 1, (int) $fresh->lock_version);
                }
            }
        }
    }
}
