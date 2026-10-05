<?php

namespace Tests\Feature;

use App\Enums\TaskState;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkflowNotificationIntent;
use App\Notifications\TaskReviewWorkflowNotification;
use App\Services\RequiredWorkflowNotifications;
use App\Services\TaskAnalyticsService;
use App\Services\TaskAssignmentCandidateService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class R61WorkflowRegressionTest extends TestCase
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

    private function fixture(bool $pmAssignee = false): array
    {
        $m = $this->user('manager');
        $pm = $this->user('project_manager');
        $a = $pmAssignee ? $pm : $this->user('team_member');
        $p = Project::factory()->create(['project_manager_id' => $pm->id]);
        $p->members()->syncWithoutDetaching([$pm->id, $a->id]);
        $r = $this->actingAs($m)->postJson('/tasks', ['title' => 'R6 real work', 'project_id' => $p->id, 'assignee_id' => $a->id, 'reviewer_id' => $m->id, 'priority' => 'High', 'due_date' => today()->addDays(3)->toDateString()])->assertOk();

        return [$m, $pm, $a, $p, Task::findOrFail($r->json('task.id'))];
    }

    public function test_promotion_cannot_strand_a_member_assignment(): void
    {
        [$m,$pm,$a,$p,$t] = $this->fixture();
        $this->actingAs($a)->postTaskTransitionJson('/tasks/'.$t->id.'/start')->assertOk();
        $response = $this->actingAs($m)->putJson('/team-management/'.$a->id, ['name' => $a->name, 'email' => $a->email, 'role_id' => Role::where('name', 'project_manager')->value('id')]);
        $response->assertStatus(409);
        $a = $a->fresh();
        $edit = $this->actingAs($a)->getJson('/tasks/'.$t->id.'/edit');
        $submit = $this->postTaskTransitionJson('/tasks/'.$t->id.'/submit', ['submission_note' => 'Ready']);
        $this->assertSame(200, $edit->status());
        $this->assertSame(200, $submit->status());
        $this->assertTrue(app(TaskAssignmentCandidateService::class)->canExecuteInProject($a, $p));
    }

    public function test_pm_replacement_cannot_strand_old_pm_as_assignee(): void
    {
        [$m,$pm,$a,$p,$t] = $this->fixture(true);
        $new = $this->user('project_manager');
        $this->actingAs($a)->postTaskTransitionJson('/tasks/'.$t->id.'/start')->assertOk();
        $replace = $this->actingAs($m)->putJson('/projects/'.$p->id, ['name' => $p->name, 'status' => 'active', 'project_manager_id' => $new->id])->assertStatus(409);
        $submit = $this->actingAs($a->fresh())->postTaskTransitionJson('/tasks/'.$t->id.'/submit', ['submission_note' => 'Finished']);
        $submit->assertOk();
        $repair = $this->actingAs($m)->putJson('/tasks/'.$t->id, ['expected_version' => $t->fresh()->lock_version, 'assignee_id' => $m->id]);
        // Manager must be made a member before any legitimate reassignment.
        $this->assertSame($a->id, $t->fresh()->assignee_id);
    }

    public function test_twelve_revision_cycles_reopen_and_private_timeline(): void
    {
        [$m,$pm,$a,$p,$t] = $this->fixture();
        $this->actingAs($a)->postTaskTransitionJson('/tasks/'.$t->id.'/start')->assertOk();
        $this->postTaskTransitionJson('/tasks/'.$t->id.'/submit', ['submission_note' => 'Initial'])->assertOk();
        for ($i = 1; $i <= 12; $i++) {
            $this->actingAs($m)->postTaskTransitionJson('/tasks/'.$t->id.'/review/start')->assertOk();
            $this->postTaskTransitionJson('/tasks/'.$t->id.'/deadline/review', ['due_date' => today()->addDays(7)->toDateString(), 'reason' => 'Review capacity '.$i])->assertOk();
            $this->postTaskTransitionJson('/tasks/'.$t->id.'/revision-request', ['formal_feedback' => 'Cycle '.$i, 'revision_due_date' => today()->addDays(4)->toDateString()])->assertOk();
            $this->postTaskTransitionJson('/tasks/'.$t->id.'/deadline/revision', ['due_date' => today()->addDays(6)->toDateString(), 'reason' => 'Rework capacity '.$i])->assertOk();
            $this->actingAs($a)->postTaskTransitionJson('/tasks/'.$t->id.'/revision/start')->assertOk();
            $this->postTaskTransitionJson('/tasks/'.$t->id.'/hold', ['reason' => 'Waiting'])->assertOk();
            $this->assertSame('revision', $t->fresh()->activeDeadlineKind());
            $this->postTaskTransitionJson('/tasks/'.$t->id.'/resume')->assertOk();
            $this->postTaskTransitionJson('/tasks/'.$t->id.'/resubmit', ['submission_note' => 'Resubmission '.$i])->assertOk();
        }
        $this->actingAs($m)->postTaskTransitionJson('/tasks/'.$t->id.'/review/start')->assertOk();
        $this->postTaskTransitionJson('/tasks/'.$t->id.'/approve', ['approval_comment' => 'Accepted'])->assertOk();
        $approval = $t->fresh()->approval;
        $this->assertSame($t->submissions()->latest('id')->first()->id, $approval->submission_id);
        $this->postTaskTransitionJson('/tasks/'.$t->id.'/reopen', ['reopen_reason' => 'R6_PRIVATE_SECRET', 'rework_instructions' => 'Public rework', 'revision_due_date' => today()->addDays(5)->toDateString()])->assertOk();
        $this->assertNull($t->fresh()->completed_at);
        $this->assertSame(13, $t->fresh()->revision_count);
        $timeline = $this->actingAs($a)->getJson('/tasks/'.$t->id.'/timeline')->assertOk();
        $this->assertStringNotContainsString('R6_PRIVATE_SECRET', $timeline->getContent());
        $this->assertTrue($timeline->json('has_more'));
        $older = $this->getJson('/tasks/'.$t->id.'/timeline?before='.$timeline->json('next_cursor'))->assertOk();
        $this->assertStringNotContainsString('R6_PRIVATE_SECRET', $older->getContent());
        $this->assertFalse($older->json('has_more'));
    }

    public function test_delayed_notice_labels_old_transition_with_current_state(): void
    {
        [$m,$pm,$a,$p,$t] = $this->fixture();
        $fail = true;
        Event::listen(NotificationSending::class, function ($event) use (&$fail) {
            if ($fail && $event->notification instanceof TaskReviewWorkflowNotification) {
                throw new \RuntimeException('R6 delivery outage');
            }
        });
        $this->actingAs($a)->postTaskTransitionJson('/tasks/'.$t->id.'/start')->assertOk();
        $this->postTaskTransitionJson('/tasks/'.$t->id.'/submit', ['submission_note' => 'First'])->assertOk();
        $intent = WorkflowNotificationIntent::where('task_id', $t->id)->where('transition', 'submitted')->where('recipient_id', $m->id)->firstOrFail();
        $this->actingAs($m)->postTaskTransitionJson('/tasks/'.$t->id.'/cancel', ['cancellation_reason' => 'No longer needed'])->assertOk();
        $fail = false;
        // Release the controlled outage after the task has changed state.
        app(RequiredWorkflowNotifications::class)->deliver($intent->id);
        $raw = DB::table('notifications')->where('id', $intent->id)->first();
        $data = json_decode($raw->data, true);
        $this->assertSame('task_submitted', $data['type']);
        $this->assertSame('cancelled', $data['current_state']);
        $this->assertFalse($data['actionable']);
        $this->assertTrue($data['historical']);
        $this->assertStringContainsString('Historical notice', $data['message']);
        $this->assertNotSame('Review task', $data['required_action']);
    }

    public function test_removal_and_deactivation_block_live_duties(): void
    {
        [$m,$pm,$a,$p,$t] = $this->fixture();
        $this->actingAs($m)->deleteJson('/projects/'.$p->id.'/remove-member', ['user_id' => $a->id])->assertUnprocessable();
        $this->postJson('/team-management/'.$a->id.'/deactivate')->assertStatus(409);
        $this->deleteJson('/projects/'.$p->id)->assertStatus(409);
        $this->actingAs($pm)->putJson('/team-management/'.$a->id, ['name' => 'Attack', 'email' => $a->email, 'role_id' => $m->role_id])->assertForbidden();
        $other = $this->user('team_member');
        $this->actingAs($other)->getJson('/tasks/'.$t->id.'/timeline')->assertForbidden();
        $this->postTaskTransitionJson('/tasks/'.$t->id.'/start')->assertForbidden();
    }

    public function test_inactive_employee_remains_in_historical_export(): void
    {
        [$m,$pm,$a,$p,$t] = $this->fixture();
        $this->actingAs($a)->postTaskTransitionJson('/tasks/'.$t->id.'/start')->assertOk();
        $this->postTaskTransitionJson('/tasks/'.$t->id.'/submit', ['submission_note' => 'Done'])->assertOk();
        $this->actingAs($m)->postTaskTransitionJson('/tasks/'.$t->id.'/review/start')->assertOk();
        $this->postTaskTransitionJson('/tasks/'.$t->id.'/approve')->assertOk();
        $before = $this->get('/analytics/export/csv')->assertOk()->streamedContent();
        $this->assertStringContainsString($a->name, $before);
        $this->postJson('/team-management/'.$a->id.'/deactivate')->assertOk();
        $after = $this->get('/analytics/export/csv')->assertOk()->streamedContent();
        $this->assertStringContainsString($a->name, $after);
        $this->assertStringContainsString('Inactive', $after);
        $this->assertStringContainsString('"Completed Tasks",1', $after);
        $report = app(TaskAnalyticsService::class)->report($m);
        $this->assertSame(1, $report['teamPerformance']->firstWhere('id', $a->id)['completed']);
    }

    public function test_resubmitted_generation_clears_old_review_start(): void
    {
        [$m,$pm,$a,$p,$t] = $this->fixture();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'Asia/Kathmandu'));
        try {
            $this->actingAs($a)->postTaskTransitionJson('/tasks/'.$t->id.'/start')->assertOk();
            $this->postTaskTransitionJson('/tasks/'.$t->id.'/submit', ['submission_note' => 'First'])->assertOk();
            $this->actingAs($m)->postTaskTransitionJson('/tasks/'.$t->id.'/review/start')->assertOk();
            $old = $t->fresh()->review_started_at->toAtomString();
            $this->postTaskTransitionJson('/tasks/'.$t->id.'/revision-request', ['formal_feedback' => 'Fix this', 'revision_due_date' => '2026-10-08'])->assertOk();
            $this->travelTo(CarbonImmutable::parse('2026-10-06 10:00:00', 'Asia/Kathmandu'));
            $this->actingAs($a)->postTaskTransitionJson('/tasks/'.$t->id.'/revision/start')->assertOk();
            $this->postTaskTransitionJson('/tasks/'.$t->id.'/resubmit', ['submission_note' => 'Second'])->assertOk();
            $current = $this->actingAs($m)->getJson('/tasks/'.$t->id.'/edit')->assertOk();
            $this->assertSame('submitted', $t->fresh()->machineState()->value);
            $this->assertNull($t->fresh()->review_started_at);
            $this->postTaskTransitionJson('/tasks/'.$t->id.'/review/start')->assertOk();
            $this->assertNotSame($old, $t->fresh()->review_started_at->toAtomString());
        } finally {
            $this->travelBack();
        }
    }

    public function test_illegal_state_matrix_has_no_effect(): void
    {
        [$m,$pm,$a,$p,$t] = $this->fixture();
        $legal = ['start' => ['not_started'], 'hold' => ['in_progress'], 'resume' => ['on_hold'], 'submit' => ['in_progress'], 'review/start' => ['submitted'], 'revision-request' => ['in_review'], 'revision/start' => ['revision_requested'], 'resubmit' => ['in_progress'], 'approve' => ['in_review'], 'reopen' => ['completed']];
        $rows = [];
        foreach (TaskState::cases() as $state) {
            foreach ($legal as $suffix => $states) {
                if (in_array($state->value, $states, true)) {
                    continue;
                }
                $t->forceFill(['status' => $state])->save();
                $before = $t->fresh()->getAttributes();
                $events = $t->events()->count();
                $history = $t->histories()->count();
                $actor = in_array($suffix, ['review/start', 'revision-request', 'approve', 'reopen']) ? $m : $a;
                $data = ['expected_version' => $t->fresh()->lock_version, 'reason' => 'Audit', 'submission_note' => 'Audit', 'formal_feedback' => 'Audit', 'revision_due_date' => today()->addDays(4)->toDateString(), 'reopen_reason' => 'Audit'];
                $response = $this->actingAs($actor)->postJson('/tasks/'.$t->id.'/'.$suffix, $data);
                $this->assertContains($response->status(), [403, 409, 422]);
                $this->assertSame($before, $t->fresh()->getAttributes());
                $this->assertSame($events, $t->events()->count());
                $this->assertSame($history, $t->histories()->count());
                $rows[] = ['state' => $state->value, 'action' => $suffix, 'status' => $response->status()];
            }
        }
    }

    public function test_duplicate_submit_conflicts_without_duplicate_rows(): void
    {
        [$m,$pm,$a,$p,$t] = $this->fixture();
        $this->actingAs($a)->postTaskTransitionJson('/tasks/'.$t->id.'/start')->assertOk();
        $version = $t->fresh()->lock_version;
        $payload = ['expected_version' => $version, 'submission_note' => 'One delivery'];
        $this->postJson('/tasks/'.$t->id.'/submit', $payload)->assertOk();
        $before = $t->fresh()->getAttributes();
        $count = $t->events()->count();
        $second = $this->postJson('/tasks/'.$t->id.'/submit', $payload);
        $this->assertContains($second->status(), [409, 422]);
        $this->assertSame(1, $t->submissions()->count());
        $this->assertSame($before, $t->fresh()->getAttributes());
        $this->assertSame($count, $t->events()->count());
    }

    public function test_required_notice_recovers_after_five_sixty_and_four_hundred_twenty_minutes(): void
    {
        $m = $this->user('manager');
        $a = $this->user('team_member');
        $p = Project::factory()->create(['project_manager_id' => $m->id]);
        $p->members()->attach($a->id);
        $fail = true;
        Event::listen(NotificationSending::class, function ($event) use (&$fail) {
            if ($fail) {
                throw new \RuntimeException('R6 scheduler outage');
            }
        });
        $observations = [];
        try {
            foreach ([5, 60, 420] as $minutes) {
                $fail = true;
                $r = $this->actingAs($m)->postJson('/tasks', ['title' => 'R6 outage '.$minutes, 'project_id' => $p->id, 'assignee_id' => $a->id, 'reviewer_id' => $m->id, 'priority' => 'High'])->assertOk()->assertJsonPath('notification_status', 'delivery_failed');
                $intent = WorkflowNotificationIntent::where('task_id', $r->json('task.id'))->firstOrFail();
                $this->assertSame('pending', $intent->status);
                $fail = false;
                $this->travel($minutes)->minutes();
                $this->artisan('app:deliver-required-workflow-notifications')->assertSuccessful();
                $this->artisan('app:deliver-required-workflow-notifications')->assertSuccessful();
                $this->assertSame('delivered', $intent->fresh()->status);
                $this->assertSame(1, DB::table('notifications')->where('id', $intent->id)->count());
                $observations[] = ['minutes' => $minutes, 'intent' => $intent->fresh()->toArray()];
            }
        } finally {
            $this->travelBack();
        }
    }
}
