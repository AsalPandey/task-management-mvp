<?php

namespace Tests\Feature;

use App\Enums\TaskState;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use App\Services\TaskAnalyticsService;
use App\Services\TaskEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnalyticsMetricContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Carbon::setTestNow(Carbon::parse('2026-09-18 12:00:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_all_states_form_disjoint_snapshot_partitions_and_rate_is_bounded(): void
    {
        [$manager, $project, $member] = $this->fixture();
        foreach (TaskState::cases() as $state) {
            $this->task($project, $member, $state, now());
        }

        $report = app(TaskAnalyticsService::class)->report($manager);

        $this->assertSame(8, $report['totalTasks']);
        $this->assertSame(6, $report['totalActiveTasks']);
        $this->assertSame(1, $report['totalCompletedTasks']);
        $this->assertSame(1, $report['cancelledTasks']);
        $this->assertSame(14.3, $report['completionRate']);
        $this->assertLessThanOrEqual(100, $report['completionRate']);
        foreach (TaskState::cases() as $state) {
            $this->assertSame(1, $report['statusCounts'][$state->label()]);
        }
    }

    public function test_zero_denominator_and_dashboard_rates_never_exceed_one_hundred(): void
    {
        [$manager, $project, $member] = $this->fixture();
        $this->assertSame(0.0, app(TaskAnalyticsService::class)->boundedCompletionRate(0, 0));
        $this->assertSame(100.0, app(TaskAnalyticsService::class)->boundedCompletionRate(3, 0));

        $this->task($project, $member, TaskState::Completed, now(), completedAt: now());
        $response = $this->actingAs($manager)->get(route('manager.dashboard'))->assertOk();
        $this->assertSame(100.0, $response->viewData('todayProgress'));

        $team = $this->actingAs($member)->get(route('team-dashboard'))->assertOk();
        $this->assertSame(100.0, $team->viewData('todayCompletionRate'));
    }

    public function test_creation_and_completion_history_survive_completion_cancellation_and_reopen_cycles(): void
    {
        [$manager, $project, $member] = $this->fixture();
        $created = Carbon::parse('2026-08-10 09:00:00', config('app.timezone'));
        $task = $this->task($project, $member, TaskState::RevisionRequested, $created);
        $cancelled = $this->task($project, $member, TaskState::Cancelled, $created);
        $this->event($task, TaskEventRecorder::COMPLETED, '2026-08-20 10:00:00', 1);
        $this->event($task, TaskEventRecorder::REOPENED, '2026-09-01 10:00:00', 2);
        $this->event($task, TaskEventRecorder::COMPLETED, '2026-09-18 10:00:00', 3);
        $this->event($cancelled, TaskEventRecorder::CANCELLED, '2026-09-02 10:00:00', 1);

        $august = app(TaskAnalyticsService::class)->report($manager, '2026-08-01', '2026-08-31');
        $september = app(TaskAnalyticsService::class)->report($manager, '2026-09-01', '2026-09-30');

        $this->assertSame(2, $august['tasksCreated']);
        $this->assertSame(1, $august['completionEvents']);
        $this->assertSame(0, $september['tasksCreated']);
        $this->assertSame(1, $september['completionEvents']);
        $this->assertSame(1, $september['reopenEvents']);
        $this->assertSame(1, $september['cancellationEvents']);
    }

    public function test_date_range_is_inclusive_and_uses_application_timezone(): void
    {
        [$manager, $project, $member] = $this->fixture();
        $this->task($project, $member, TaskState::NotStarted, Carbon::parse('2025-12-31 23:59:59', config('app.timezone')));
        $this->task($project, $member, TaskState::InProgress, Carbon::parse('2026-01-01 00:00:00', config('app.timezone')));
        $this->task($project, $member, TaskState::OnHold, Carbon::parse('2026-01-01 23:59:59', config('app.timezone')));
        $this->task($project, $member, TaskState::Submitted, Carbon::parse('2026-01-02 00:00:00', config('app.timezone')));

        $sameDay = app(TaskAnalyticsService::class)->report($manager, '2026-01-01', '2026-01-01');
        $this->assertSame(2, $sameDay['tasksCreated']);
    }

    public function test_project_manager_filters_and_exports_cannot_escape_managed_projects(): void
    {
        [$manager, $managedProject, $member] = $this->fixture();
        $outsideManager = $this->user('project_manager');
        $outsideMember = $this->user('team_member');
        $outsideProject = Project::factory()->create(['project_manager_id' => $outsideManager->id]);
        $outsideProject->members()->attach($outsideMember->id);
        $this->task($managedProject, $member, TaskState::InProgress, now());
        $this->task($outsideProject, $outsideMember, TaskState::Completed, now(), completedAt: now());

        $projectManager = $managedProject->projectManager;
        $response = $this->actingAs($projectManager)->get(route('analytics', [
            'project' => $outsideProject->id,
            'assignee' => $outsideMember->id,
            'dateFrom' => '2026-01-01',
            'dateTo' => '2026-12-31',
        ]))->assertOk();
        $this->assertSame(0, $response->viewData('totalTasks'));

        $csv = $this->actingAs($projectManager)->get(route('analytics.export.csv', [
            'project' => $outsideProject->id,
            'assignee' => $outsideMember->id,
            'dateFrom' => '2026-01-01',
            'dateTo' => '2026-12-31',
        ]))->assertOk()->streamedContent();
        $this->assertStringNotContainsString($outsideMember->name, $csv);
        $this->assertNotEmpty($manager);
    }

    private function fixture(): array
    {
        $manager = $this->user('manager');
        $projectManager = $this->user('project_manager');
        $member = $this->user('team_member');
        $project = Project::factory()->create(['project_manager_id' => $projectManager->id]);
        $project->members()->attach($member->id);

        return [$manager, $project, $member];
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('name', $role)->value('id')]);
    }

    private function task(Project $project, User $member, TaskState $state, Carbon $createdAt, ?Carbon $completedAt = null): Task
    {
        $task = Task::query()->create([
            'project_id' => $project->id,
            'title' => $state->label().' analytics fixture',
            'assignee_id' => $member->id,
            'created_by' => $project->project_manager_id,
            'assigned_by' => $project->project_manager_id,
            'priority' => 'Medium',
            'status' => $state,
            'progress' => $state === TaskState::Completed ? 100 : 40,
            'due_date' => now()->addDay()->toDateString(),
        ]);
        $task->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'completed_at' => $completedAt,
            'completed_by' => $completedAt ? $project->project_manager_id : null,
            'cancelled_at' => $state === TaskState::Cancelled ? now() : null,
            'cancelled_by' => $state === TaskState::Cancelled ? $project->project_manager_id : null,
            'cancellation_reason' => $state === TaskState::Cancelled ? 'Analytics fixture' : null,
        ])->save();

        return $task->fresh();
    }

    private function event(Task $task, string $type, string $occurredAt, int $sequence): void
    {
        $event = new TaskEvent;
        $event->forceFill([
            'event_uid' => (string) Str::ulid(),
            'task_id' => $task->id,
            'sequence' => $sequence,
            'event_type' => $type,
            'actor_id' => $task->created_by,
            'source' => 'analytics-test',
            'correlation_id' => (string) Str::uuid(),
            'changed_fields' => [],
            'metadata' => [],
            'occurred_at' => Carbon::parse($occurredAt, config('app.timezone')),
        ])->save();
    }
}
