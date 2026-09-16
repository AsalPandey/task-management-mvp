<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskLifecycleService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CanonicalTaskReadPathTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Notification::fake();
    }

    public function test_completed_page_reads_canonical_tasks_and_does_not_require_legacy_rows(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $canonical = $this->completedTask($project, $assignee, $manager, [
            'title' => 'Canonical completed page task',
        ]);
        $this->legacyCompletion($project, $assignee, $manager, [
            'title' => 'Legacy row must not render',
        ]);

        $response = $this->actingAs($manager)->get(route('completed-tasks'))->assertOk();
        $completed = $response->viewData('completed');

        $this->assertCount(1, $completed);
        $this->assertTrue($completed->first()->is($canonical));
        $this->assertInstanceOf(Task::class, $completed->first());
        $response
            ->assertSee('Canonical completed page task')
            ->assertDontSee('Legacy row must not render')
            ->assertSee(route('tasks.reopen', $canonical), false);
        $this->assertTrue(Route::has('completed-tasks'));
        $this->assertTrue(Route::has('completed-tasks.revert'));
    }

    public function test_completed_page_includes_tasks_completed_more_than_seven_days_ago(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $olderCompletion = $this->completedTask($project, $assignee, $manager, [
            'title' => 'Older canonical completion',
            'completed_at' => now()->subMonths(3),
        ]);

        $response = $this->actingAs($manager)
            ->get(route('completed-tasks'))
            ->assertOk()
            ->assertSee('Older canonical completion');

        $this->assertTrue($response->viewData('completed')->contains(
            fn (Task $task): bool => $task->is($olderCompletion),
        ));
    }

    public function test_completed_page_contains_its_wide_table_on_mobile_viewports(): void
    {
        [$manager] = $this->managedProject();

        $this->actingAs($manager)
            ->get(route('completed-tasks'))
            ->assertOk()
            ->assertSee('class="completed-table-wrapper"', false)
            ->assertSee('role="region" aria-label="Completed task history" tabindex="0"', false)
            ->assertSee('max-width: 100%', false)
            ->assertSee('overflow-x: auto', false)
            ->assertSee('class="empty-state-cell"', false);
    }

    public function test_new_completion_appears_and_reopen_immediately_disappears(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $task = $this->activeTask($project, $assignee, ['title' => 'Immediate canonical transition']);
        $service = app(TaskLifecycleService::class);

        $this->actingAs($manager)
            ->get(route('completed-tasks'))
            ->assertOk()
            ->assertDontSee($task->title);

        $completed = $this->approveTask($task, $manager);

        $this->actingAs($manager)
            ->get(route('completed-tasks'))
            ->assertOk()
            ->assertSee($task->title);

        $this->reopenApprovedTask($completed, $manager);

        $this->actingAs($manager)
            ->get(route('completed-tasks'))
            ->assertOk()
            ->assertDontSee($task->title);
        $this->assertDatabaseCount('completed_tasks', 0);
    }

    public function test_completed_page_preserves_newest_first_pagination(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $ids = collect(range(1, 17))->map(function (int $position) use ($project, $assignee, $manager): int {
            return $this->completedTask($project, $assignee, $manager, [
                'title' => "Paginated completion {$position}",
                'completed_at' => now()->subMinutes($position),
            ])->id;
        });

        $firstPage = $this->actingAs($manager)->get(route('completed-tasks'))->assertOk()->viewData('completed');
        $secondPage = $this->actingAs($manager)->get(route('completed-tasks', ['page' => 2]))->assertOk()->viewData('completed');

        $this->assertSame(15, $firstPage->count());
        $this->assertSame($ids->take(15)->all(), $firstPage->pluck('id')->all());
        $this->assertSame($ids->skip(15)->values()->all(), $secondPage->pluck('id')->all());
    }

    public function test_completed_search_and_filters_apply_to_the_complete_authorized_dataset(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $otherProjectManager = $this->userWithRole('project_manager');
        $otherProject = Project::factory()->create(['project_manager_id' => $otherProjectManager->id]);
        $otherAssignee = $this->userWithRole('team_member');
        $otherProject->members()->attach($otherAssignee->id);
        $target = $this->completedTask($project, $assignee, $manager, [
            'title' => 'Searchable canonical completion',
            'priority' => 'High',
        ]);
        $this->completedTask($otherProject, $otherAssignee, $manager, [
            'title' => 'Unrelated canonical completion',
            'priority' => 'Low',
        ]);

        $search = $this->actingAs($manager)
            ->get(route('completed-tasks', ['search' => 'searchable canonical']))
            ->assertOk()
            ->viewData('completed');
        $filtered = $this->actingAs($manager)
            ->get(route('completed-tasks', [
                'priority' => 'High',
                'project' => $project->id,
                'assignee' => $assignee->id,
            ]))
            ->assertOk()
            ->viewData('completed');

        $this->assertSame([$target->id], $search->pluck('id')->all());
        $this->assertSame([$target->id], $filtered->pluck('id')->all());
        $this->assertStringContainsString('priority=High', $filtered->url(1));
        $this->assertStringContainsString('project='.$project->id, $filtered->url(1));
    }

    public function test_completed_page_visibility_matches_existing_role_boundaries(): void
    {
        [$manager, $managedProject, $member, , $projectManager] = $this->managedProject(withCoworker: true);
        $otherProjectManager = $this->userWithRole('project_manager');
        $otherProject = Project::factory()->create(['project_manager_id' => $otherProjectManager->id]);
        $outsideMember = $this->userWithRole('team_member');
        $otherProject->members()->attach($outsideMember->id);
        $managedOwn = $this->completedTask($managedProject, $member, $manager, ['title' => 'Managed own completion']);
        $managedCoworker = $this->completedTask($managedProject, $outsideMember, $manager, ['title' => 'Managed coworker completion']);
        $outside = $this->completedTask($otherProject, $outsideMember, $manager, ['title' => 'Outside completion']);

        $managerIds = $this->actingAs($manager)->get(route('completed-tasks'))->assertOk()
            ->viewData('completed')->pluck('id')->all();
        $projectManagerIds = $this->actingAs($projectManager)->get(route('completed-tasks'))->assertOk()
            ->viewData('completed')->pluck('id')->all();
        $memberIds = $this->actingAs($member)->get(route('completed-tasks'))->assertOk()
            ->viewData('completed')->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$managedOwn->id, $managedCoworker->id, $outside->id], $managerIds);
        $this->assertEqualsCanonicalizing([$managedOwn->id, $managedCoworker->id], $projectManagerIds);
        $this->assertSame([$managedOwn->id], $memberIds);

        $inactive = $this->userWithRole('team_member', ['active' => false]);
        $this->actingAs($inactive)
            ->get(route('completed-tasks'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $scopedFilter = $this->actingAs($projectManager)
            ->get(route('completed-tasks', ['project' => $otherProject->id]))
            ->assertOk()
            ->viewData('completed');
        $this->assertCount(0, $scopedFilter);
    }

    public function test_manager_and_team_dashboards_use_canonical_counts_and_collections(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $active = $this->activeTask($project, $assignee);
        $completed = $this->completedTask($project, $assignee, $manager);
        $this->legacyCompletion($project, $assignee, $manager);

        $managerResponse = $this->actingAs($manager)->get(route('manager.dashboard'))->assertOk();
        $this->assertSame(1, $managerResponse->viewData('currentActiveCount'));
        $this->assertSame(1, $managerResponse->viewData('todayCompletedCount'));
        $this->assertSame([$completed->id], $managerResponse->viewData('todayCompletedTasks')->pluck('id')->all());
        $this->assertSame([$active->id], $managerResponse->viewData('currentActiveTasks')->pluck('id')->all());

        $teamResponse = $this->actingAs($assignee)->get(route('team-dashboard'))->assertOk();
        $this->assertSame(1, $teamResponse->viewData('currentTotalTasks'));
        $this->assertSame(1, $teamResponse->viewData('todayCompletedTasks'));
        $this->assertSame([$completed->id], $teamResponse->viewData('recentCompletedHistory')->pluck('id')->all());
        $this->assertSame(1, $teamResponse->viewData('todayStatusCounts')['Completed']);
    }

    public function test_analytics_and_member_analytics_use_canonical_task_partitions(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $active = $this->activeTask($project, $assignee, ['priority' => 'High']);
        $completed = $this->completedTask($project, $assignee, $manager, ['priority' => 'Low']);
        $this->legacyCompletion($project, $assignee, $manager);

        $analytics = $this->actingAs($manager)->get(route('analytics'))->assertOk();
        $this->assertSame(1, $analytics->viewData('totalActiveTasks'));
        $this->assertSame(1, $analytics->viewData('totalCompletedTasks'));
        $this->assertSame(50.0, $analytics->viewData('completionRate'));
        $this->assertSame([$active->id], $analytics->viewData('activeTasks')->pluck('id')->all());
        $this->assertSame([$completed->id], $analytics->viewData('completedTasks')->pluck('id')->all());

        $memberAnalytics = $this->actingAs($manager)
            ->get(route('team-management.analytics', $assignee))
            ->assertOk();
        $this->assertSame(1, $memberAnalytics->viewData('totalTasks'));
        $this->assertSame(1, $memberAnalytics->viewData('totalCompletedTasks'));
        $this->assertSame(50.0, $memberAnalytics->viewData('overallCompletionRate'));
        $this->assertSame(1, $memberAnalytics->viewData('statusCounts')['Completed']);
        $this->assertEqualsCanonicalizing(
            [$active->id, $completed->id],
            $memberAnalytics->viewData('recentActivity')->pluck('id')->all(),
        );
    }

    public function test_canonical_completed_assignments_still_block_account_deletion(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $this->completedTask($project, $assignee, $manager);

        $this->actingAs($manager)
            ->deleteJson(route('team-management.destroy', $assignee))
            ->assertConflict()
            ->assertJson(['success' => false]);

        $this->assertNotSoftDeleted($assignee);
    }

    public function test_normal_runtime_read_routes_never_query_completed_tasks(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $this->activeTask($project, $assignee);
        $this->completedTask($project, $assignee, $manager);
        $this->legacyCompletion($project, $assignee, $manager);
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = mb_strtolower($query->sql);
        });

        $this->actingAs($manager)->get(route('completed-tasks'))->assertOk();
        $this->actingAs($manager)->get(route('manager.dashboard'))->assertOk();
        $this->actingAs($manager)->get(route('analytics'))->assertOk();
        $this->actingAs($manager)->get(route('team-management.analytics', $assignee))->assertOk();
        $this->actingAs($assignee)->get(route('team-dashboard'))->assertOk();

        $this->assertFalse(
            collect($queries)->contains(fn (string $query): bool => str_contains($query, 'completed_tasks')),
            'A normal runtime read queried the transitional completed_tasks table.',
        );
    }

    private function managedProject(bool $withCoworker = false): array
    {
        $manager = $this->userWithRole('manager');
        $projectManager = $this->userWithRole('project_manager');
        $project = Project::factory()->create(['project_manager_id' => $projectManager->id]);
        $assignee = $this->userWithRole('team_member');
        $members = [$assignee->id];
        $coworker = null;

        if ($withCoworker) {
            $coworker = $this->userWithRole('team_member');
            $members[] = $coworker->id;
        }

        $project->members()->attach($members);

        return [$manager, $project, $assignee, $coworker, $projectManager];
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role_id' => Role::query()->where('name', $role)->value('id'),
        ], $attributes));
    }

    private function activeTask(Project $project, User $assignee, array $attributes = []): Task
    {
        return Task::query()->create(array_merge([
            'project_id' => $project->id,
            'title' => 'Canonical active task',
            'description' => 'Active read-path fixture',
            'assignee_id' => $assignee->id,
            'priority' => 'Medium',
            'status' => 'In Progress',
            'progress' => 40,
        ], $attributes));
    }

    private function completedTask(Project $project, User $assignee, User $manager, array $attributes = []): Task
    {
        $completedAt = $attributes['completed_at'] ?? now();
        unset($attributes['completed_at']);
        $task = $this->activeTask($project, $assignee, array_merge([
            'title' => 'Canonical completed task',
            'status' => 'Completed',
            'progress' => 100,
        ], $attributes));

        $task->forceFill([
            'completed_at' => $completedAt,
            'completed_by' => $manager->id,
        ])->save();

        return $task->fresh();
    }

    private function legacyCompletion(Project $project, User $assignee, User $manager, array $attributes = []): int
    {
        return (int) DB::table('completed_tasks')->insertGetId(array_merge([
            'project_id' => $project->id,
            'title' => 'Legacy completed row',
            'description' => 'Transitional fixture',
            'assignee_id' => $assignee->id,
            'priority' => 'Medium',
            'status' => 'Completed',
            'progress' => 100,
            'completed_at' => now(),
            'completed_by' => $manager->id,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }
}
