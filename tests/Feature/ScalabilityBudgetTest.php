<?php

namespace Tests\Feature;

use App\Enums\TaskState;
use App\Http\Controllers\NotificationController;
use App\Models\AuthorizedDatabaseNotification;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScalabilityBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->travelTo(now()->setDate(2026, 10, 3)->startOfDay());
    }

    public function test_aggregate_report_has_independent_totals_without_task_model_hydration(): void
    {
        [$manager] = $this->fixture();
        $hydrated = 0;
        Task::retrieved(function () use (&$hydrated): void {
            $hydrated++;
        });
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $report = app(TaskAnalyticsService::class)->report($manager);
        $this->assertSame(200, $report['totalTasks']);
        $this->assertSame(150, $report['totalActiveTasks']);
        $this->assertSame(25, $report['totalCompletedTasks']);
        $this->assertSame(25, $report['cancelledTasks']);
        $this->assertSame(25, $report['inProgressTasks']);
        $this->assertSame(150, $report['overdueTasks']);
        $this->assertSame(14.3, $report['completionRate']);
        $this->assertSame(0, $hydrated, 'Aggregate reports must not hydrate Task models.');
        $this->assertLessThanOrEqual(12, $queries);
    }

    public function test_manager_dashboard_bounds_task_rows_and_html_while_totals_remain_complete(): void
    {
        [$manager] = $this->fixture();
        $hydrated = 0;
        Task::retrieved(function () use (&$hydrated): void {
            $hydrated++;
        });
        $response = $this->actingAs($manager)->get('/manager')->assertOk();
        $response->assertViewHas('currentActiveCount', 150);
        $this->assertLessThanOrEqual(20, $hydrated);
        $this->assertLessThanOrEqual(10, substr_count($response->getContent(), 'class="overdue-task"'));
        $this->assertLessThanOrEqual(250000, strlen($response->getContent()));
    }

    public function test_dense_project_card_does_not_load_child_tasks_or_role_n_plus_one(): void
    {
        [$manager] = $this->fixture();
        $hydrated = 0;
        $queries = 0;
        Task::retrieved(function () use (&$hydrated): void {
            $hydrated++;
        });
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $this->actingAs($manager)->get('/projects')->assertOk()->assertSee('Dense budget project');
        $this->assertSame(0, $hydrated);
        $this->assertLessThanOrEqual(25, $queries);
    }

    public function test_bulk_read_has_one_owner_scoped_update_and_preserves_existing_read_times(): void
    {
        [$manager, $member] = $this->fixture(0);
        $oldRead = '2026-09-01 08:00:00';
        $rows = [];
        for ($i = 0; $i < 1000; $i++) {
            $rows[] = $this->notification($manager->id);
        }
        $alreadyRead = $this->notification($manager->id) + ['read_at' => $oldRead];
        $other = $this->notification($member->id);
        DB::table('notifications')->insert($rows);
        DB::table('notifications')->insert($alreadyRead);
        DB::table('notifications')->insert($other);
        $this->actingAs($manager);
        $updates = 0;
        DB::listen(function ($query) use (&$updates): void {
            if (str_starts_with(strtolower($query->sql), 'update')) {
                $updates++;
            }
        });
        app(NotificationController::class)->markAllAsRead();
        $this->assertSame(0, $manager->unreadNotifications()->count());
        $this->assertSame(1, $member->unreadNotifications()->count());
        $this->assertSame($oldRead, DB::table('notifications')->where('id', $alreadyRead['id'])->value('read_at'));
        $this->assertSame(1, $updates);
        app(NotificationController::class)->markAllAsRead();
        $this->assertSame($oldRead, DB::table('notifications')->where('id', $alreadyRead['id'])->value('read_at'));
    }

    public function test_team_search_form_reaches_off_page_authorized_records_and_retains_pagination(): void
    {
        [$manager, $member] = $this->fixture(0);
        $member->forceFill(['name' => 'पुरानो Offpage Target', 'email' => 'offpage@r43.test', 'created_at' => '2020-01-01'])->save();
        User::factory()->count(25)->create(['role_id' => $member->role_id]);
        $this->actingAs($manager)->get('/team-management')->assertOk()->assertDontSee('offpage@r43.test');
        $response = $this->get('/team-management?search=OFFPAGE')->assertOk()->assertSee('offpage@r43.test');
        $this->assertStringContainsString('name="search"', $response->getContent());
        $this->get('/team-management?search=पुरानो')->assertOk()->assertSee('offpage@r43.test');
        $this->get('/team-management?search=not-present')->assertOk()->assertDontSee('offpage@r43.test');
        $this->actingAs($member)->get('/team-management?search=Offpage')->assertForbidden();
    }

    public function test_notification_history_and_shared_navigation_hydrate_only_one_page_and_eight_previews(): void
    {
        [$manager] = $this->fixture(0);
        $rows = [];
        for ($i = 0; $i < 1000; $i++) {
            $rows[] = $this->notification($manager->id);
        }
        DB::table('notifications')->insert($rows);
        $hydrated = 0;
        AuthorizedDatabaseNotification::retrieved(function () use (&$hydrated): void {
            $hydrated++;
        });
        $response = $this->actingAs($manager)->get('/notifications/all')->assertOk();
        $this->assertSame(20, $response->viewData('notifications')->count());
        $this->assertSame(1000, $response->viewData('notifications')->total());
        $this->assertSame(28, $hydrated);
        $response->assertSee('class="notification-badge" data-unread-count="1000">99+</span>', false);
    }

    public function test_member_dashboard_bounds_all_previews_and_preserves_complete_totals(): void
    {
        [, $member] = $this->fixture();
        DB::table('tasks')->where('status', 'completed')->update(['completed_at' => now()]);
        $hydrated = 0;
        Task::retrieved(function () use (&$hydrated): void {
            $hydrated++;
        });
        $response = $this->actingAs($member)->get('/team-dashboard')->assertOk();
        $response->assertViewHas('currentTotalTasks', 150)->assertViewHas('todayCompletedTasks', 25)->assertViewHas('todayOverdueTasks', 150);
        $this->assertCount(10, $response->viewData('todayOverdueList'));
        $this->assertCount(10, $response->viewData('recentCompletedHistory'));
        $this->assertLessThanOrEqual(35, $hydrated);
        $this->assertLessThanOrEqual(100000, strlen($response->getContent()));
    }

    public function test_member_report_loads_only_ten_recent_rows_and_does_not_repeat_monthly_reports(): void
    {
        [$manager, $member] = $this->fixture();
        $hydrated = 0;
        $queries = 0;
        Task::retrieved(function () use (&$hydrated): void {
            $hydrated++;
        });
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $response = $this->actingAs($manager)->get('/team-management/'.$member->id.'/analytics')->assertOk();
        $response->assertViewHas('totalTasks', 200)->assertViewHas('totalCompletedTasks', 25);
        $this->assertCount(10, $response->viewData('recentActivity'));
        $this->assertSame(10, $hydrated);
        $this->assertLessThanOrEqual(55, $queries);
    }

    public function test_exports_are_aggregate_only_even_when_the_creation_cohort_grows(): void
    {
        [$manager] = $this->fixture();
        $hydrated = 0;
        Task::retrieved(function () use (&$hydrated): void {
            $hydrated++;
        });
        $this->actingAs($manager)->get('/analytics/export/csv')->assertOk()->assertDownload('task-management-analytics.csv');
        $this->get('/analytics/print')->assertOk()->assertViewHas('totalTasks', 200);
        $this->assertSame(0, $hydrated);
    }

    public function test_new_search_resets_page_and_links_preserve_the_search_across_results(): void
    {
        [$manager, $member] = $this->fixture(0);
        User::factory()->count(25)->create(['name' => 'Budget Search Match', 'role_id' => $member->role_id]);
        $response = $this->actingAs($manager)->get('/team-management?search=Budget+Search')->assertOk();
        $this->assertSame(25, $response->viewData('users')->total());
        $this->assertSame(12, $response->viewData('users')->count());
        $this->assertStringContainsString('search=Budget', $response->viewData('users')->url(2));
        $this->assertStringNotContainsString('name="page"', $response->getContent());
        $second = $this->get('/team-management?search=Budget+Search&page=2')->assertOk();
        $this->assertSame(12, $second->viewData('users')->count());
        $this->assertSame([], array_intersect($response->viewData('users')->pluck('id')->all(), $second->viewData('users')->pluck('id')->all()));
    }

    public function test_search_treats_wildcards_as_literal_text_and_trims_whitespace(): void
    {
        [$manager, $member] = $this->fixture(0);
        $member->update(['name' => 'Literal 50%_! Target']);
        $response = $this->actingAs($manager)->get('/team-management?search='.urlencode('  50%_!  '))->assertOk();
        $this->assertSame([$member->id], $response->viewData('users')->pluck('id')->all());
        $this->get('/team-management?search[]=invalid')->assertRedirect()->assertSessionHasErrors('search');
    }

    public function test_task_page_stays_at_twenty_four_rows_with_complete_pagination_totals(): void
    {
        [$manager] = $this->fixture();
        $hydrated = 0;
        Task::retrieved(function () use (&$hydrated): void {
            $hydrated++;
        });
        $response = $this->actingAs($manager)->get('/tasks')->assertOk();
        $this->assertCount(24, $response->viewData('tasks'));
        $this->assertSame(150, $response->viewData('tasks')->total());
        $this->assertSame(24, $hydrated);
    }

    private function notification(int $owner): array
    {
        return ['id' => (string) Str::uuid(), 'type' => 'SyntheticBudgetNotice', 'notifiable_type' => User::class, 'notifiable_id' => $owner, 'data' => '{"message":"Budget business notice"}', 'created_at' => now(), 'updated_at' => now()];
    }

    private function fixture(int $tasks = 200): array
    {
        $manager = User::factory()->create(['role_id' => Role::where('name', 'manager')->value('id')]);
        $member = User::factory()->create(['role_id' => Role::where('name', 'team_member')->value('id')]);
        $project = Project::factory()->create(['name' => 'Dense budget project', 'project_manager_id' => $manager->id]);
        $project->members()->attach($member);
        foreach (range(0, max(0, $tasks - 1)) as $i) {
            if ($tasks === 0) {
                break;
            }
            $state = TaskState::cases()[$i % 8];
            $task = Task::query()->create(['title' => 'Budget '.$i, 'project_id' => $project->id, 'assignee_id' => $member->id, 'reviewer_id' => $manager->id, 'status' => $state, 'priority' => 'Medium', 'due_date' => '2026-10-02']);
            $task->forceFill(['execution_due_date' => '2026-10-02', 'review_due_date' => '2026-10-02', 'revision_due_date' => '2026-10-02'])->save();
        }

        return [$manager, $member, $project];
    }
}
