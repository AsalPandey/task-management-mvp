<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkflowNotificationIntent;
use App\Notifications\TaskReviewWorkflowNotification;
use App\Services\RequiredWorkflowNotifications;
use App\Support\WorkflowNoticeSemantics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class R61NotificationGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_delayed_generations_remain_historical_private_and_deduplicated_through_reopen(): void
    {
        $this->seed();
        $m = User::factory()->create(['role_id' => Role::where('name', 'manager')->value('id')]);
        $a = User::factory()->create(['role_id' => Role::where('name', 'team_member')->value('id')]);
        $p = Project::factory()->create(['project_manager_id' => $m->id]);
        $p->members()->attach($a->id);
        $r = $this->actingAs($m)->postJson('/tasks', ['title' => 'R61 generations', 'project_id' => $p->id, 'assignee_id' => $a->id, 'reviewer_id' => $m->id, 'priority' => 'High'])->assertOk();
        $t = Task::findOrFail($r->json('task.id'));
        $fail = true;
        Event::listen(NotificationSending::class, function ($event) use (&$fail) {
            if ($fail && $event->notification instanceof TaskReviewWorkflowNotification) {
                throw new \RuntimeException('R61 notification outage');
            }
        });
        $this->actingAs($a)->postTaskTransitionJson('/tasks/'.$t->id.'/start')->assertOk();
        $this->postTaskTransitionJson('/tasks/'.$t->id.'/submit')->assertOk();
        $this->actingAs($m)->postTaskTransitionJson('/tasks/'.$t->id.'/review/start')->assertOk();
        $this->postTaskTransitionJson('/tasks/'.$t->id.'/revision-request', ['formal_feedback' => 'Public revision', 'revision_due_date' => today()->addDays(3)->toDateString()])->assertOk();
        $this->actingAs($a)->postTaskTransitionJson('/tasks/'.$t->id.'/revision/start')->assertOk();
        $this->postTaskTransitionJson('/tasks/'.$t->id.'/hold', ['reason' => 'Waiting'])->assertOk();
        $this->postTaskTransitionJson('/tasks/'.$t->id.'/resume')->assertOk();
        $this->postTaskTransitionJson('/tasks/'.$t->id.'/resubmit')->assertOk();
        $this->assertNull($t->fresh()->review_started_at);
        $this->actingAs($m)->postTaskTransitionJson('/tasks/'.$t->id.'/review/start')->assertOk();
        $this->postTaskTransitionJson('/tasks/'.$t->id.'/deadline/review', ['due_date' => today()->addDays(5)->toDateString(), 'reason' => 'R61_PRIVATE'])->assertOk();
        $this->postTaskTransitionJson('/tasks/'.$t->id.'/deadline/review', ['due_date' => today()->addDays(6)->toDateString(), 'reason' => 'R61_PRIVATE'])->assertOk();
        $this->postTaskTransitionJson('/tasks/'.$t->id.'/approve')->assertOk();
        // Completion is preference-controlled, not a required durable intent.
        $completed = (new TaskReviewWorkflowNotification($t->fresh(), $m, 'approved_completed', 'None'))->toArray($a);
        $completedVersion = $t->fresh()->lock_version;
        $this->postTaskTransitionJson('/tasks/'.$t->id.'/reopen', ['reopen_reason' => 'R61_PRIVATE', 'rework_instructions' => 'Public follow-up', 'revision_due_date' => today()->addDays(7)->toDateString()])->assertOk();
        $fail = false;
        $transitions = [];
        foreach (WorkflowNotificationIntent::where('task_id', $t->id)->where('status', 'pending')->get() as $intent) {
            app(RequiredWorkflowNotifications::class)->deliver($intent->id);
            app(RequiredWorkflowNotifications::class)->deliver($intent->id);
            $row = DB::table('notifications')->where('id', $intent->id)->firstOrFail();
            $payload = json_decode($row->data, true);
            $this->assertSame(1, DB::table('notifications')->where('id', $intent->id)->count());
            $this->assertArrayHasKey('event_at', $payload);
            $this->assertSame((int) $intent->task_version, $payload['event_task_version']);
            if ((int) $intent->task_version !== (int) $t->fresh()->lock_version) {
                $this->assertTrue($payload['historical']);
                $this->assertFalse($payload['actionable']);
                $this->assertStringContainsString('Historical notice', $payload['message']);
                $this->assertStringNotContainsString('R61_PRIVATE', json_encode($payload));
            }
            $transitions[] = $intent->transition;
        }
        foreach (['submitted', 'revision_requested', 'resubmitted', 'deadline_changed'] as $transition) {
            $this->assertContains($transition, $transitions);
        }
        $historicalCompletion = WorkflowNoticeSemantics::interpret($completed, $t->fresh(), $completedVersion, 'approved_completed', $completed['approved_at']);
        $this->assertTrue($historicalCompletion['historical']);
        $this->assertFalse($historicalCompletion['actionable']);
        // A delivered notice also becomes historical on a later authorized read.
        $this->actingAs($a)->postTaskTransitionJson('/tasks/'.$t->id.'/revision/start')->assertOk();
        $notice = $a->fresh()->notifications()->where('data', 'like', '%reopened_revision_required%')->firstOrFail();
        $this->assertTrue($notice->data['historical']);
        $this->assertFalse($notice->data['actionable']);
        $this->assertStringNotContainsString('R61_PRIVATE', json_encode($notice->data));
    }
}
