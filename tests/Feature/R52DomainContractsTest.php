<?php

namespace Tests\Feature;

use App\Enums\TaskState;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskRevisionCycle;
use App\Models\User;
use App\Notifications\ProjectMemberRemoved;
use App\Services\TaskAssignmentCandidateService;
use App\Services\TaskDeadlineCandidates;
use App\Services\TaskDeadlineNotificationDelivery;
use App\Support\TaskDeadlineRules;
use App\Support\TaskDeadlineSql;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class R52DomainContractsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->value('id')]);
    }

    private function fixture(): array
    {
        $manager = $this->user('manager');
        $pm = $this->user('project_manager');
        $member = $this->user('team_member');
        $project = Project::factory()->create(['project_manager_id' => $pm->id]);
        $project->members()->attach([$pm->id, $member->id]);
        $response = $this->actingAs($manager)->postJson('/tasks', $this->payload($project, $member, $pm))->assertOk();

        return [$manager, $pm, $member, $project, Task::findOrFail($response->json('task.id'))];
    }

    private function payload(Project $project, User $member, User $reviewer): array
    {
        return ['title' => 'R52 contract', 'project_id' => $project->id, 'assignee_id' => $member->id,
            'reviewer_id' => $reviewer->id, 'priority' => 'High', 'due_date' => today()->subDays(3)->toDateString()];
    }

    public function test_held_revision_preserves_generation_sql_events_and_allowed_actions(): void
    {
        [$manager, $pm, $member, $project, $task] = $this->fixture();
        $this->actingAs($member)->postTaskTransitionJson("/tasks/{$task->id}/start")->assertOk();
        $this->postTaskTransitionJson("/tasks/{$task->id}/submit", ['submission_note' => 'Initial'])->assertOk();
        $this->actingAs($pm)->postTaskTransitionJson("/tasks/{$task->id}/review/start")->assertOk();
        $this->postTaskTransitionJson("/tasks/{$task->id}/revision-request", ['formal_feedback' => 'Correct', 'revision_due_date' => today()->addDays(6)->toDateString()])->assertOk();
        $this->actingAs($member)->postTaskTransitionJson("/tasks/{$task->id}/revision/start")->assertOk();
        $before = $task->fresh()->activeDeadlineGeneration();
        $this->postTaskTransitionJson("/tasks/{$task->id}/hold", ['reason' => 'Waiting'])->assertOk();
        $held = $task->fresh();
        $this->assertSame('revision', $held->activeDeadlineKind());
        $this->assertSame($before->fingerprint(), $held->activeDeadlineGeneration()->fingerprint());
        $this->assertFalse($held->activeDeadlineGeneration()->isOverdue());
        $sql = Task::whereKey($task->id)->selectRaw(TaskDeadlineSql::kind().' as kind, '.TaskDeadlineSql::date().' as deadline')->first();
        $this->assertSame('revision', $sql->kind);
        $this->assertSame($before->deadline->toDateString(), $sql->deadline);
        $event = $held->events()->where('event_type', 'task.held')->firstOrFail();
        $this->assertSame($before->deadline->toDateString(), $event->changed_fields['active_deadline']['after']);
        $this->assertTrue(TaskDeadlineRules::allows($held, 'revision'));
        $this->assertFalse(TaskDeadlineRules::allows($held, 'execution'));
        $this->postTaskTransitionJson("/tasks/{$task->id}/resume")->assertOk();
        $this->assertSame($before->fingerprint(), $task->fresh()->activeDeadlineGeneration()->fingerprint());
    }

    public function test_partial_start_update_rejects_effective_inverted_schedule(): void
    {
        [$manager, $pm, $member, $project, $task] = $this->fixture();
        $this->actingAs($manager)->putJson("/tasks/{$task->id}", ['expected_version' => $task->lock_version,
            'start_date' => today()->addDays(2)->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('start_date');
        $this->assertNull($task->fresh()->start_date);
    }

    public function test_unrelated_pm_membership_is_not_an_executable_assignment(): void
    {
        [$manager, $pm, $member, $project, $task] = $this->fixture();
        $other = $this->user('project_manager');
        $project->members()->attach($other->id);
        $this->assertFalse(app(TaskAssignmentCandidateService::class)->forProjects(Project::whereKey($project->id)->get())->whereKey($other->id)->exists());
        $this->actingAs($manager)->postJson('/tasks', $this->payload($project, $other, $pm))->assertUnprocessable()->assertJsonValidationErrors('assignee_id');
        $this->putJson("/tasks/{$task->id}", ['expected_version' => $task->lock_version, 'assignee_id' => $other->id])->assertUnprocessable();
    }

    public function test_removed_member_receives_safe_notice_and_access_stays_revoked(): void
    {
        $manager = $this->user('manager');
        $member = $this->user('team_member');
        $project = Project::factory()->create(['name' => 'PRIVATE SECRET', 'project_manager_id' => $manager->id]);
        $project->members()->attach($member->id);
        $this->actingAs($manager)->deleteJson("/projects/{$project->id}/remove-member", ['user_id' => $member->id])->assertOk();
        $notice = $member->notifications()->where('type', ProjectMemberRemoved::class)->first();
        $this->assertNotNull($notice);
        $this->assertSame('project_member_removed', $notice->data['type']);
        $this->assertArrayNotHasKey('project_id', $notice->data);
        $this->assertArrayNotHasKey('link', $notice->data);
        $this->assertStringNotContainsString('PRIVATE SECRET', json_encode($notice->data));
        $this->assertFalse($member->can('view', $project->fresh()));
        $this->deleteJson("/projects/{$project->id}/remove-member", ['user_id' => $member->id])->assertOk();
        $this->assertSame(1, $member->notifications()->where('type', ProjectMemberRemoved::class)->count());
        $this->postJson("/projects/{$project->id}/add-member", ['user_id' => $member->id])->assertOk();
        $this->deleteJson("/projects/{$project->id}/remove-member", ['user_id' => $member->id])->assertOk();
        $this->assertSame(2, $member->notifications()->where('type', ProjectMemberRemoved::class)->count());
    }

    public function test_project_dates_reject_noncanonical_input_before_normalization(): void
    {
        $manager = $this->user('manager');
        foreach (['10000-01-01', '999-01-01', '2025-02-29', '2024-02-31', '2024-00-01', '2024-01-00', '2024-13-01', '2024-01-01T00:00:00Z'] as $date) {
            $this->actingAs($manager)->postJson('/projects', ['name' => 'Date '.$date, 'status' => 'active', 'start_date' => $date])
                ->assertUnprocessable()->assertJsonValidationErrors('start_date');
        }
        $this->postJson('/projects', ['name' => 'Leap date', 'status' => 'active', 'start_date' => '2024-02-29'])->assertOk();
    }

    public function test_rendered_dashboards_have_one_top_level_main(): void
    {
        $manager = $this->user('manager');
        $member = $this->user('team_member');
        foreach ([[$manager, '/manager'], [$member, '/team-dashboard']] as [$user, $path]) {
            $html = $this->actingAs($user)->get($path)->assertOk()->getContent();
            $this->assertSame(1, preg_match_all('/<main\b/i', $html));
            $this->assertStringContainsString('id="main-content"', $html);
        }
    }

    public function test_date_only_whitespace_timezone_boundary_and_effective_create_pair(): void
    {
        [$manager, $pm, $member, $project, $task] = $this->fixture();
        $response = $this->actingAs($manager)->postJson('/projects', ['name' => 'Trimmed date contract', 'status' => 'active',
            'start_date' => ' 2024-02-29 ', 'end_date' => '2024-03-01 '])->assertOk();
        $this->assertSame('2024-02-29', CarbonImmutable::parse($response->json('project.start_date'))->setTimezone(config('app.timezone'))->toDateString());
        $this->travelTo(CarbonImmutable::parse('2026-10-05 00:05:00', config('app.timezone')));
        try {
            $this->assertSame('2026-10-05', today()->toDateString());
            $payload = $this->payload($project, $member, $pm);
            $payload['start_date'] = today()->toDateString();
            $payload['due_date'] = today()->toDateString();
            $this->postJson('/tasks', $payload)->assertOk();
            $payload['start_date'] = today()->addDay()->toDateString();
            $this->postJson('/tasks', $payload)->assertUnprocessable()->assertJsonValidationErrors('due_date');
        } finally {
            $this->travelBack();
        }
    }

    public function test_deadline_truth_table_has_model_sql_generation_and_reminder_parity(): void
    {
        [$manager, $pm, $member, $project, $task] = $this->fixture();
        $revision = TaskRevisionCycle::create(['task_id' => $task->id, 'cycle_number' => 1,
            'requested_by' => $pm->id, 'requested_at' => now(), 'revision_due_date' => today()->addDay(), 'origin' => 'review_revision']);
        foreach ([false, true] as $hasRevision) {
            foreach (TaskState::cases() as $state) {
                $kind = match ($state) {
                    TaskState::Completed, TaskState::Cancelled => null,
                    TaskState::Submitted, TaskState::InReview => 'review',
                    TaskState::RevisionRequested => 'revision',
                    TaskState::InProgress, TaskState::OnHold => $hasRevision ? 'revision' : 'execution',
                    default => 'execution',
                };
                $task->forceFill(['status' => $state, 'active_revision_cycle_id' => $hasRevision ? $revision->id : null,
                    'execution_due_date' => today()->subDay(), 'due_date' => today()->subDay(),
                    'review_due_date' => today()->addDays(2), 'revision_due_date' => today()->addDay()])->save();
                $task = $task->fresh();
                $sql = Task::whereKey($task->id)->selectRaw(TaskDeadlineSql::kind().' as kind, '.TaskDeadlineSql::date().' as deadline')->first();
                $this->assertSame($kind, $task->activeDeadlineKind());
                $this->assertSame($kind, $sql->kind);
                $this->assertSame($task->activeDeadline()?->toDateString(), $sql->deadline);
                $reminders = [];
                app(TaskDeadlineCandidates::class)->each(TaskDeadlineNotificationDelivery::DEADLINE_REMINDER, function ($id) use (&$reminders) {
                    $reminders[] = $id;
                });
                $this->assertSame($kind === 'revision', in_array($task->id, $reminders, true));
                if ($kind === 'revision') {
                    $task->markDeadlineReminderSentForActiveGeneration();
                    $this->assertTrue($task->fresh()->deadlineReminderWasSentForActiveGeneration());
                }
                $task->forceFill(['deadline_reminder_generation' => null])->save();
            }
        }
    }

    public function test_schedule_edits_and_deadline_command_validate_existing_other_side(): void
    {
        [$manager, $pm, $member, $project, $task] = $this->fixture();
        $task->forceFill(['execution_due_date' => today()->addDays(10), 'due_date' => today()->addDays(10)])->save();
        foreach ([today()->addDays(9), today()->addDays(10)] as $start) {
            $this->actingAs($manager)->putJson("/tasks/{$task->id}", ['expected_version' => $task->fresh()->lock_version, 'start_date' => $start->toDateString()])->assertOk();
        }
        $this->postTaskTransitionJson("/tasks/{$task->id}/deadline/execution", ['due_date' => today()->addDays(9)->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('due_date');
        $this->postTaskTransitionJson("/tasks/{$task->id}/deadline/execution", ['due_date' => today()->addDays(10)->toDateString()])->assertOk();
        $this->putJson("/tasks/{$task->id}", ['expected_version' => $task->fresh()->lock_version, 'start_date' => '10000-01-01'])->assertUnprocessable();
    }

    public function test_own_pm_and_member_assignments_can_read_and_start_but_inactive_and_self_review_are_rejected(): void
    {
        [$manager, $pm, $member, $project, $task] = $this->fixture();
        foreach ([[$pm, $manager], [$member, $pm]] as [$assignee, $reviewer]) {
            $created = $this->actingAs($manager)->postJson('/tasks', $this->payload($project, $assignee, $reviewer))->assertOk()->json('task.id');
            $this->actingAs($assignee)->getJson("/tasks/{$created}/edit")->assertOk();
            $this->postTaskTransitionJson("/tasks/{$created}/start")->assertOk();
        }
        $member->update(['active' => false]);
        $this->actingAs($manager)->postJson('/tasks', $this->payload($project, $member, $pm))->assertUnprocessable();
        $this->postJson('/tasks', $this->payload($project, $pm, $pm))->assertUnprocessable();
    }
}
