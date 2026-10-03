<?php

namespace Tests\Feature;

use App\Enums\TaskState;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskRevisionCycle;
use App\Models\User;
use App\Services\TaskAnalyticsService;
use App\Services\TaskDeadlineCandidates;
use App\Services\TaskDeadlineNotificationDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PerformanceReadCorrectnessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Notification::fake();
        $this->travelTo(now()->setDate(2026, 10, 3)->setTime(12, 0));
    }

    public static function deadlineCases(): array
    {
        return [
            'execution fallback yesterday' => ['not_started', null, '2026-10-02', null, null, false, 1, 0],
            'execution overrides retained date' => ['on_hold', '2026-10-05', '2026-10-02', null, null, false, 0, 0],
            'due today at noon' => ['in_progress', '2026-10-03', null, null, null, false, 0, 0],
            'tomorrow' => ['not_started', '2026-10-04', null, null, null, false, 0, 1],
            'review ignores old execution' => ['submitted', '2026-10-02', null, '2026-10-05', null, false, 0, 0],
            'review without due date' => ['in_review', '2026-10-02', null, null, null, false, 0, 0],
            'review overdue' => ['in_review', '2026-10-05', null, '2026-10-02', null, false, 1, 0],
            'revision overdue' => ['revision_requested', '2026-10-05', null, null, '2026-10-02', false, 1, 0],
            'revision without date' => ['revision_requested', '2026-10-02', null, null, null, false, 0, 0],
            'running revision retains revision deadline' => ['in_progress', '2026-10-02', null, null, '2026-10-05', true, 0, 0],
            'revision field without cycle stays execution' => ['in_progress', '2026-10-05', null, null, '2026-10-02', false, 0, 0],
            'completed ignores deadlines' => ['completed', '2026-10-02', null, '2026-10-02', '2026-10-02', false, 0, 0],
            'cancelled ignores deadlines' => ['cancelled', '2026-10-02', null, '2026-10-02', '2026-10-02', false, 0, 0],
            'no execution date' => ['not_started', null, null, null, null, false, 0, 0],
        ];
    }

    #[DataProvider('deadlineCases')]
    public function test_deadline_sql_respects_independent_stage_and_local_date_expectations(string $state, ?string $execution, ?string $legacy, ?string $review, ?string $revision, bool $hasCycle, int $overdue, int $soon): void
    {
        [$manager, $member, $project] = $this->fixture();
        $task = $this->task($project, $member, $manager, $state);
        $cycle = $hasCycle ? TaskRevisionCycle::query()->create(['task_id' => $task->id, 'cycle_number' => 1]) : null;
        $task->forceFill(['execution_due_date' => $execution, 'due_date' => $legacy, 'review_due_date' => $review,
            'revision_due_date' => $revision, 'active_revision_cycle_id' => $cycle?->id])->save();
        $report = app(TaskAnalyticsService::class)->report($manager);
        $this->assertSame($overdue, $report['overdueTasks']);
        $this->assertSame($soon, $report['dueSoonTasks']);
        $this->assertSame($overdue, $report['overdueTrend']['Oct 03']);
        $this->assertSame((bool) $overdue, $task->activeDeadlineGeneration()?->isOverdue() ?? false);
        $ids = [];
        app(TaskDeadlineCandidates::class)->each(TaskDeadlineNotificationDelivery::OVERDUE, function ($id) use (&$ids): void {
            $ids[] = $id;
        });
        $this->assertCount($overdue, $ids);
        $ids = [];
        app(TaskDeadlineCandidates::class)->each(TaskDeadlineNotificationDelivery::DEADLINE_REMINDER, function ($id) use (&$ids): void {
            $ids[] = $id;
        });
        $this->assertCount($soon, $ids);
    }

    public function test_creation_chart_is_creation_data_and_due_today_is_not_overdue_at_noon(): void
    {
        [$manager, $member, $project] = $this->fixture();
        $task = $this->task($project, $member, $manager, 'not_started');
        $task->forceFill(['execution_due_date' => '2026-10-03'])->save();
        $response = $this->actingAs($manager)->get('/analytics')->assertOk();
        $this->assertSame(1, $response->viewData('productivity')['Oct 03']);
        $this->assertSame(0, $response->viewData('completionTrend')['Oct 03']);
        $this->assertSame(0, $response->viewData('overdueTrend')['Oct 03']);
        $this->assertSame(0, $response->viewData('overdueTasks'));
    }

    public function test_member_upcoming_preview_uses_current_review_deadline_not_retained_execution_date(): void
    {
        [$manager, $member, $project] = $this->fixture();
        $task = $this->task($project, $member, $manager, 'submitted');
        $task->forceFill(['execution_due_date' => '2026-10-02', 'due_date' => '2026-10-02', 'review_due_date' => '2026-10-05'])->save();
        $response = $this->actingAs($member)->get('/team-dashboard')->assertOk();
        $this->assertSame([$task->id], $response->viewData('upcoming')->pluck('id')->all());
        $response->assertSee('10/05/2026');
    }

    public function test_consumed_candidate_does_not_retrieve_task_models_and_new_generation_is_rearmed(): void
    {
        [$manager, $member, $project] = $this->fixture();
        $task = $this->task($project, $member, $manager, 'not_started');
        $task->forceFill(['execution_due_date' => '2026-10-02'])->save();
        $task->markOverdueNotificationSentForActiveGeneration();
        $hydrated = 0;
        Task::retrieved(function () use (&$hydrated): void {
            $hydrated++;
        });
        $ids = [];
        $scan = function () use (&$ids): void {
            app(TaskDeadlineCandidates::class)->each(TaskDeadlineNotificationDelivery::OVERDUE, function ($id) use (&$ids): void {
                $ids[] = $id;
            });
        };
        $scan();
        $this->assertSame([], $ids);
        $this->assertSame(0, $hydrated);
        $task->forceFill(['execution_due_date' => '2026-10-01'])->save();
        $scan();
        $this->assertSame([$task->id], $ids);
        $this->assertSame(0, $hydrated);
    }

    public function test_candidate_snapshot_cannot_deliver_after_owner_or_state_changes(): void
    {
        [$manager, $member, $project] = $this->fixture();
        $task = $this->task($project, $member, $manager, 'not_started');
        $task->forceFill(['execution_due_date' => '2026-10-02'])->save();
        app(TaskDeadlineCandidates::class)->each(TaskDeadlineNotificationDelivery::OVERDUE, function ($id) use ($task): void {
            $task->forceFill(['status' => TaskState::Cancelled])->save();
            $result = app(TaskDeadlineNotificationDelivery::class)->deliver($id, TaskDeadlineNotificationDelivery::OVERDUE);
            $this->assertFalse($result->delivered());
        });
        Notification::assertNothingSent();
        $this->assertDatabaseCount('task_notification_deliveries', 0);
    }

    public function test_search_pm_scope_cannot_reveal_a_global_account_or_an_unmanaged_member(): void
    {
        [$manager, $member, $project] = $this->fixture();
        $pm = User::factory()->create(['role_id' => Role::where('name', 'project_manager')->value('id')]);
        $project->update(['project_manager_id' => $pm->id]);
        $outside = User::factory()->create(['name' => 'Outside Secret', 'role_id' => $member->role_id]);
        $this->actingAs($pm)->get('/team-management?search=Outside')->assertOk()->assertDontSee($outside->email);
        $this->get('/team-management?search='.urlencode($member->email))->assertOk()->assertSee($member->email)->assertDontSee('class="action-btn edit-btn"', false);
        $response = $this->get('/team-management?search='.urlencode($manager->email))->assertOk();
        $this->assertSame(0, $response->viewData('users')->total());
    }

    private function fixture(): array
    {
        $manager = User::factory()->create(['role_id' => Role::where('name', 'manager')->value('id')]);
        $member = User::factory()->create(['role_id' => Role::where('name', 'team_member')->value('id')]);
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $project->members()->attach($member);

        return [$manager, $member, $project];
    }

    private function task(Project $project, User $member, User $manager, string $state): Task
    {
        return Task::query()->create(['title' => 'Current deadline oracle', 'project_id' => $project->id,
            'assignee_id' => $member->id, 'reviewer_id' => $manager->id, 'priority' => 'Medium', 'status' => $state]);
    }
}
