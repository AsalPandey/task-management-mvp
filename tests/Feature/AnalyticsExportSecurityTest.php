<?php

namespace Tests\Feature;

use App\Enums\TaskState;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AnalyticsExportSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_exports_canonical_organization_data_with_safe_csv_cells(): void
    {
        $this->seed();
        [$manager, $projectManager, $member, $outsideMember, $managedProject, $outsideProject] = $this->fixtures();

        $this->task($managedProject, $member, TaskState::InProgress);
        $this->task($managedProject, $member, TaskState::Completed);
        $this->task($managedProject, $member, TaskState::Cancelled);
        $this->task($outsideProject, $outsideMember, TaskState::NotStarted);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = mb_strtolower($query->sql);
        });

        $response = $this->actingAs($manager)
            ->get(route('analytics.export.csv'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('content-disposition', 'attachment; filename=task-management-analytics.csv');

        $rows = $this->csvRows($response->streamedContent());
        $this->assertSame('2', $this->metric($rows, 'Active Tasks'));
        $this->assertSame('1', $this->metric($rows, 'Completed Tasks'));
        $this->assertSame('1', $this->metric($rows, 'Cancelled Tasks'));
        $this->assertContains("'=SUM(1,1)", array_column($rows, 0));
        $this->assertContains("'+SUM(1,1)", array_column($rows, 0));
        $this->assertContains("'-10+20", array_column($rows, 0));
        $this->assertContains("'@SUM(1,1)", array_column($rows, 0));
        $this->assertContains('<svg onload=alert(1)>', array_column($rows, 0));
        $this->assertFalse($this->queriedLegacyCompletedTasks($queries));
        $this->assertNotEmpty($projectManager);
    }

    public function test_project_manager_exports_cannot_be_widened_by_crafted_filters(): void
    {
        $this->seed();
        [, $projectManager, $member, $outsideMember, $managedProject, $outsideProject] = $this->fixtures();

        $this->task($managedProject, $member, TaskState::InProgress);
        $this->task($managedProject, $member, TaskState::Completed);
        $this->task($managedProject, $member, TaskState::Cancelled);
        $this->task($outsideProject, $outsideMember, TaskState::InProgress);

        $response = $this->actingAs($projectManager)
            ->get(route('analytics.export.csv', [
                'project' => $outsideProject->id,
                'assignee' => $outsideMember->id,
                'dateFrom' => now()->subYear()->toDateString(),
                'dateTo' => now()->addYear()->toDateString(),
            ]))
            ->assertOk();

        $csv = $response->streamedContent();
        $rows = $this->csvRows($csv);
        $this->assertSame('0', $this->metric($rows, 'Active Tasks'));
        $this->assertSame('0', $this->metric($rows, 'Completed Tasks'));
        $this->assertSame('0', $this->metric($rows, 'Cancelled Tasks'));
        $this->assertStringNotContainsString($outsideMember->name, $csv);

        $unfiltered = $this->actingAs($projectManager)
            ->get(route('analytics.export.csv'))
            ->assertOk()
            ->streamedContent();
        $unfilteredRows = $this->csvRows($unfiltered);
        $this->assertSame('1', $this->metric($unfilteredRows, 'Active Tasks'));
        $this->assertSame('1', $this->metric($unfilteredRows, 'Completed Tasks'));
        $this->assertSame('1', $this->metric($unfilteredRows, 'Cancelled Tasks'));
        $this->assertStringNotContainsString($outsideMember->name, $unfiltered);
    }

    public function test_team_members_are_denied_analytics_exports_before_filters_are_applied(): void
    {
        $this->seed();
        [, , $member, $outsideMember, , $outsideProject] = $this->fixtures();

        foreach (['analytics.export.csv', 'analytics.export.print'] as $route) {
            $this->actingAs($member)
                ->get(route($route, [
                    'project' => $outsideProject->id,
                    'assignee' => $outsideMember->id,
                ]))
                ->assertForbidden();
        }
    }

    public function test_html_print_export_is_escaped_scoped_and_handles_empty_results(): void
    {
        $this->seed();
        [$manager, $projectManager, $member, $outsideMember, $managedProject, $outsideProject] = $this->fixtures();

        $this->task($managedProject, $member, TaskState::Completed);
        $this->task($outsideProject, $outsideMember, TaskState::InProgress);

        $managerExport = $this->actingAs($manager)
            ->get(route('analytics.export.print'))
            ->assertOk()
            ->assertHeader('content-type', 'text/html; charset=UTF-8')
            ->assertSee($outsideMember->name)
            ->assertDontSee($outsideMember->name, false);

        $this->assertStringNotContainsString('<script', mb_strtolower($managerExport->getContent()));

        $this->actingAs($projectManager)
            ->get(route('analytics.export.print'))
            ->assertOk()
            ->assertSee($member->name)
            ->assertDontSee($outsideMember->name);

        $empty = $this->actingAs($manager)
            ->get(route('analytics.export.print', [
                'assignee' => PHP_INT_MAX,
                'dateFrom' => now()->subDay()->toDateString(),
                'dateTo' => now()->toDateString(),
            ]))
            ->assertOk();
        $this->assertStringContainsString('<td>0</td>', $empty->getContent());
    }

    /**
     * @return array{User, User, User, User, Project, Project}
     */
    private function fixtures(): array
    {
        $manager = $this->userWithRole('manager', ['name' => 'Release Manager']);
        $projectManager = $this->userWithRole('project_manager', ['name' => 'Managed PM']);
        $outsideProjectManager = $this->userWithRole('project_manager', ['name' => 'Outside PM']);
        $member = $this->userWithRole('team_member', ['name' => '=SUM(1,1)']);
        $plusMember = $this->userWithRole('team_member', ['name' => '+SUM(1,1)']);
        $minusMember = $this->userWithRole('team_member', ['name' => '-10+20']);
        $atMember = $this->userWithRole('team_member', ['name' => '@SUM(1,1)']);
        $outsideMember = $this->userWithRole('team_member', ['name' => '<svg onload=alert(1)>']);
        $managedProject = Project::factory()->create(['project_manager_id' => $projectManager->id]);
        $outsideProject = Project::factory()->create(['project_manager_id' => $outsideProjectManager->id]);
        $managedProject->members()->attach([
            $member->id,
            $plusMember->id,
            $minusMember->id,
            $atMember->id,
        ]);
        $outsideProject->members()->attach($outsideMember->id);

        return [$manager, $projectManager, $member, $outsideMember, $managedProject, $outsideProject];
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role_id' => Role::query()->where('name', $role)->value('id'),
        ], $attributes));
    }

    private function task(Project $project, User $assignee, TaskState $state): Task
    {
        $task = Task::query()->create([
            'project_id' => $project->id,
            'title' => $state->label().' export fixture',
            'description' => 'Canonical analytics export fixture',
            'assignee_id' => $assignee->id,
            'priority' => 'Medium',
            'status' => $state,
            'progress' => $state === TaskState::Completed ? 100 : 40,
            'execution_due_date' => now()->addWeek()->toDateString(),
        ]);

        if ($state === TaskState::Completed) {
            $task->forceFill([
                'completed_at' => now(),
                'completed_by' => $project->project_manager_id,
            ])->save();
        }

        if ($state === TaskState::Cancelled) {
            $task->forceFill([
                'cancelled_at' => now(),
                'cancelled_by' => $project->project_manager_id,
                'cancellation_reason' => 'Cancelled export fixture',
            ])->save();
        }

        return $task->fresh();
    }

    /**
     * @return list<array<int, string|null>>
     */
    private function csvRows(string $csv): array
    {
        return collect(preg_split('/\r\n|\n|\r/', trim($csv)))
            ->filter(fn (string $line) => $line !== '')
            ->map(fn (string $line) => str_getcsv($line, escape: ''))
            ->values()
            ->all();
    }

    /**
     * @param  list<array<int, string|null>>  $rows
     */
    private function metric(array $rows, string $name): ?string
    {
        return collect($rows)->first(fn (array $row) => ($row[0] ?? null) === $name)[1] ?? null;
    }

    /**
     * @param  list<string>  $queries
     */
    private function queriedLegacyCompletedTasks(array $queries): bool
    {
        return collect($queries)->contains(
            fn (string $query): bool => str_contains($query, 'completed_tasks'),
        );
    }
}
