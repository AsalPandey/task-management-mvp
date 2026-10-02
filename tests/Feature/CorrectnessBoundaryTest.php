<?php

namespace Tests\Feature;

use App\Enums\TaskState;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Notifications\ProjectMemberAdded;
use App\Notifications\ProjectMemberRemoved;
use App\Services\CompanySetupService;
use App\Services\TaskAnalyticsService;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

class CorrectnessBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_malformed_resource_shapes_are_rejected_without_coercion_or_writes(): void
    {
        [$manager, $member, $project, $payload] = $this->fixtures();
        $this->actingAs($manager);
        foreach (['project_id', 'assignee_id', 'reviewer_id'] as $field) {
            foreach ([true, false, 0, -1, 1.5, null, '', ' ', [1], [[1]], ['id' => 1], '999999999999999999999999', '1e0'] as $value) {
                Cache::flush();
                $this->postJson('/tasks', array_replace($payload, [$field => $value]))
                    ->assertUnprocessable()->assertJsonValidationErrors($field);
                $this->assertDatabaseCount('tasks', 0);
                $this->assertDatabaseCount('task_events', 0);
            }
        }
        foreach ([true, [1], ['id' => 1]] as $value) {
            $this->postJson("/projects/{$project->id}/add-member", ['user_id' => $value])->assertUnprocessable();
            $this->postJson('/team-management', ['name' => 'Bad shape', 'email' => 'bad@shape.test', 'password' => 'safe-password', 'role_id' => $value])->assertUnprocessable();
            $this->getJson('/analytics?'.http_build_query(['assignee' => is_bool($value) ? 'true' : $value]))->assertUnprocessable();
        }
        $this->postJson('/tasks', array_map(fn ($v) => is_int($v) ? (string) $v : $v, $payload))->assertOk();
    }

    public function test_email_storage_and_historical_identity_policy_match_create_and_update(): void
    {
        [$manager, $member] = $this->fixtures();
        $this->actingAs($manager);
        $long = str_repeat('a', 256).'@example.test';
        $account = ['name' => 'Boundary', 'email' => $long, 'password' => 'safe-password', 'role_id' => $member->role_id];
        $this->postJson('/team-management', $account)->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->putJson("/team-management/{$member->id}", $account)->assertUnprocessable()->assertJsonValidationErrors('email');
        $deleted = User::factory()->create(['email' => 'former@boundary.test', 'role_id' => $member->role_id]);
        $deleted->delete();
        $this->postJson('/team-management', array_replace($account, ['email' => ' FORMER@BOUNDARY.TEST ']))
            ->assertUnprocessable()->assertJsonValidationErrors('email')->assertSee('prior account');
        $this->assertDatabaseCount('users', 3);
        $this->postJson('/team-management', array_replace($account, ['email' => ' NORMAL@BOUNDARY.TEST ']))->assertOk();
        $this->assertDatabaseHas('users', ['email' => 'normal@boundary.test']);
    }

    public function test_multibyte_text_limits_reject_before_persistence(): void
    {
        [$manager, $member, $project, $payload] = $this->fixtures();
        $this->actingAs($manager);
        foreach (['description', 'comments'] as $field) {
            foreach ([str_repeat('a', 20001), str_repeat('😀', 20001), ['nested' => 'text']] as $value) {
                $this->postJson('/tasks', $payload + [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
                $this->assertDatabaseCount('tasks', 0);
            }
        }
        $this->postJson('/projects', ['name' => 'Oversized', 'status' => 'active', 'description' => str_repeat('😀', 20001)])->assertUnprocessable();
        $this->postJson('/tasks', $payload + ['description' => str_repeat('नेपाल😀é', 1000)])->assertOk();
        $task = Task::query()->sole();
        $this->putJson("/tasks/{$task->id}", ['description' => str_repeat('a', 20001), 'expected_version' => $task->lock_version])->assertUnprocessable();
        $this->assertSame(1, $task->fresh()->lock_version);
    }

    public function test_reports_match_an_independent_machine_state_oracle(): void
    {
        [$manager, $member, $project] = $this->fixtures();
        $this->travelTo(now()->setDate(2026, 10, 3)->startOfDay());
        foreach (TaskState::cases() as $state) {
            $task = Task::query()->create(['title' => $state->value, 'project_id' => $project->id, 'assignee_id' => $member->id, 'status' => $state, 'priority' => 'Medium', 'due_date' => '2026-10-02']);
            if ($state->isReviewState()) {
                $task->forceFill(['review_due_date' => '2026-10-02'])->save();
            }
            if ($state === TaskState::RevisionRequested) {
                $task->forceFill(['revision_due_date' => '2026-10-02'])->save();
            }
        }
        $report = app(TaskAnalyticsService::class)->report($manager);
        $this->assertSame(8, $report['totalTasks']);
        $this->assertSame(6, $report['totalActiveTasks']);
        $this->assertSame(1, $report['inProgressTasks']);
        $this->assertSame(1, $report['totalCompletedTasks']);
        $this->assertSame(6, $report['overdueTasks']);
        $this->assertSame(14.3, $report['completionRate']);
        $this->assertCount(30, $report['creationTrend']);
        $this->assertSame('Sep 04', $report['creationTrend']->keys()->first());
        $this->assertSame('Oct 03', $report['creationTrend']->keys()->last());
        $this->assertSame(8, $report['creationTrend']->sum());
        $this->assertCount(30, $report['completionTrend']);
        $this->assertSame(array_fill_keys(array_map(fn ($s) => $s->label(), TaskState::cases()), 1), $report['statusCounts']->all());
        $this->actingAs($manager)->get('/manager')->assertOk()->assertViewHas('statusCounts', fn ($counts) => $counts['In Progress'] === 1);
        $query = '?dateFrom=2026-10-03&dateTo=2026-10-03';
        $this->get('/analytics'.$query)->assertOk()->assertViewHas('inProgressTasks', 1)->assertViewHas('completionRate', 14.3);
        $this->get('/analytics/print'.$query)->assertOk()->assertViewHas('inProgressTasks', 1);
        $csv = $this->get('/analytics/export/csv'.$query)->assertOk()->streamedContent();
        $this->assertStringContainsString('"In Progress Tasks",1', $csv);
    }

    public function test_member_range_uses_creation_cohort_and_rejects_invalid_or_partial_ranges(): void
    {
        [$manager, $member, $project] = $this->fixtures();
        foreach (['2026-01-01 00:00:00', '2026-01-01 23:59:59', '2026-02-01 00:00:00'] as $stamp) {
            Task::query()->create(['title' => $stamp, 'project_id' => $project->id, 'assignee_id' => $member->id, 'status' => TaskState::InProgress, 'priority' => 'Medium', 'created_at' => $stamp])->forceFill(['created_at' => $stamp])->save();
        }
        $url = "/team-management/{$member->id}/analytics";
        $this->actingAs($manager)->get($url.'?dateFrom=2026-01-01&dateTo=2026-01-01')->assertOk()->assertViewHas('totalTasks', 2)->assertSee('2026-01-01');
        $this->get($url.'?dateFrom=2026-02-01&dateTo=2026-02-01')->assertOk()->assertViewHas('totalTasks', 1);
        foreach (['dateFrom=bad&dateTo=2026-01-01', 'dateFrom=2026-02-01&dateTo=2026-01-01', 'dateFrom=2026-01-01'] as $query) {
            $this->getJson($url.'?'.$query)->assertUnprocessable();
        }
        $outsider = User::factory()->create(['role_id' => $member->role_id]);
        $this->actingAs($outsider)->get($url)->assertForbidden();
    }

    public function test_duplicate_membership_has_no_duplicate_effects_but_readd_is_a_new_event(): void
    {
        Notification::fake();
        [$manager, $member, $project] = $this->fixtures();
        $project->members()->detach($member->id);
        $this->actingAs($manager);
        for ($i = 0; $i < 3; $i++) {
            $this->postJson("/projects/{$project->id}/add-member", ['user_id' => $member->id])->assertOk();
        }
        $this->assertDatabaseCount('project_user', 1);
        $this->assertSame(1, $project->histories()->where('action', 'member_added')->count());
        Notification::assertSentToTimes($member, ProjectMemberAdded::class, 1);
        for ($i = 0; $i < 2; $i++) {
            $this->deleteJson("/projects/{$project->id}/remove-member", ['user_id' => $member->id])->assertOk();
        }
        Notification::assertSentToTimes($member, ProjectMemberRemoved::class, 1);
        $this->postJson("/projects/{$project->id}/add-member", ['user_id' => $member->id])->assertOk();
        Notification::assertSentToTimes($member, ProjectMemberAdded::class, 2);
        $this->assertSame(2, $project->histories()->where('action', 'member_added')->count());
    }

    public function test_task_and_export_budgets_are_independent_and_still_enforced(): void
    {
        [$manager] = $this->fixtures();
        $this->actingAs($manager);
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/tasks', [])->assertUnprocessable();
        }
        $this->postJson('/tasks', [])->assertStatus(429);
        for ($i = 0; $i < 10; $i++) {
            $this->get('/analytics/export/csv')->assertOk();
        }
        $this->get('/analytics/export/csv')->assertStatus(429);
        $this->get('/analytics/print')->assertStatus(429); // Intentionally shared export surface.
        $this->postJson('/settings/profile', ['name' => 'Safe', 'email' => $manager->email])->assertOk();
        Cache::flush();
        for ($i = 0; $i < 10; $i++) {
            $this->get('/analytics/export/csv')->assertOk();
        }
        $this->get('/analytics/export/csv')->assertStatus(429);
        $this->postJson('/tasks', [])->assertUnprocessable();
        auth()->logout();
        Cache::flush();
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/forgot-password', ['email' => []])->assertUnprocessable();
        }
        $this->postJson('/forgot-password', ['email' => []])->assertStatus(429);
        $this->postJson('/reset-password', [])->assertUnprocessable();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.2'])->postJson('/forgot-password', ['email' => []])->assertUnprocessable();
    }

    public function test_query_failure_logging_keeps_diagnostics_without_bindings_or_secrets(): void
    {
        $handler = new TestHandler;
        Log::channel()->getLogger()->pushHandler($handler);
        $secret = '$2y$04$dummy-sensitive-password-hash-for-r42-test';
        $exception = new QueryException('mysql', 'insert into users (password, email) values (?, ?)', [$secret, 'dummy@r42.test'], new \PDOException('controlled storage failure', 23000));
        app(ExceptionHandler::class)->report($exception);
        $records = $handler->getRecords();
        $this->assertNotEmpty($records);
        $formatter = new JsonFormatter;
        $captured = implode('', array_map(fn ($record) => $formatter->format($record), $records));
        $this->assertStringNotContainsString($secret, $captured);
        $this->assertStringNotContainsString('dummy@r42.test', $captured);
        $this->assertStringContainsString('23000', $captured);
        $this->assertStringContainsString('mysql', $captured);
    }

    public function test_setup_cannot_claim_a_timezone_different_from_deployment(): void
    {
        config(['app.timezone' => 'Asia/Kathmandu']);
        $this->expectException(ValidationException::class);
        app(CompanySetupService::class)->setup(['company_name' => 'Mismatch', 'name' => 'Manager', 'email' => 'timezone@r42.test', 'password' => 'safe-password', 'timezone' => 'America/New_York']);
    }

    private function fixtures(): array
    {
        $manager = User::factory()->create(['role_id' => Role::where('name', 'manager')->value('id')]);
        $member = User::factory()->create(['role_id' => Role::where('name', 'team_member')->value('id')]);
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $project->members()->attach($member->id);

        return [$manager, $member, $project, ['title' => 'Boundary', 'project_id' => $project->id, 'assignee_id' => $member->id, 'reviewer_id' => $manager->id, 'priority' => 'Medium']];
    }
}
