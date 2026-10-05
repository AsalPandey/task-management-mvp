<?php

namespace Tests\Feature;

use App\Exceptions\AccountLifecycleException;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use App\Services\AccountLifecycleService;
use App\Services\NotificationAccess;
use App\Services\ReviewerEligibilityService;
use App\Services\TaskEventRecorder;
use App\ValueObjects\TaskOperationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class R53CapacityNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->seed();
        $manager = User::factory()->create(['role_id' => Role::where('name', 'manager')->value('id')]);
        $old = User::factory()->create(['role_id' => Role::where('name', 'project_manager')->value('id')]);
        $new = User::factory()->create(['role_id' => $old->role_id]);
        $member = User::factory()->create(['role_id' => Role::where('name', 'team_member')->value('id')]);
        $project = Project::factory()->create(['project_manager_id' => $old->id]);
        $project->members()->attach([$old->id, $new->id, $member->id]);

        return [$manager, $old, $new, $member, $project];
    }

    public function test_timeline_traverses_all_pages_with_concurrent_insert_and_private_redaction(): void
    {
        [$manager, $old, $new, $member, $project] = $this->fixture();
        $task = Task::query()->forceCreate(['title' => 'Qualification work', 'project_id' => $project->id, 'assignee_id' => $member->id]);
        for ($i = 1; $i <= 205; $i++) {
            app(TaskEventRecorder::class)->record($task, TaskEventRecorder::UPDATED,
                TaskOperationContext::test($manager->id), [], ['reason_reference' => 'private-history-reference']);
        }
        $response = $this->actingAs($member)->getJson(route('tasks.timeline', $task))->assertOk()
            ->assertJsonPath('has_more', true)->assertJsonPath('next_cursor', '106')
            ->assertDontSee('private-history-reference')->assertDontSee('Management reason recorded');
        $this->assertCount(100, $response->json('entries'));
        $pages = [$response->json('entries')];
        app(TaskEventRecorder::class)->record($task, TaskEventRecorder::UPDATED, TaskOperationContext::test($manager->id), []);
        while ($response->json('has_more')) {
            $response = $this->getJson(route('tasks.timeline', $task).'?before='.$response->json('next_cursor'))->assertOk();
            $pages[] = $response->json('entries');
        }
        $sequences = [];
        foreach (array_reverse($pages) as $page) {
            $sequences = [...$sequences, ...array_column($page, 'sequence')];
        }
        $this->assertSame(range(1, 205), $sequences);
        $response->assertJsonPath('next_cursor', null);
        $this->actingAs($manager)->getJson(route('tasks.timeline', $task))->assertJsonFragment(['details' => ['Management reason recorded']]);
        $this->actingAs($new)->getJson(route('tasks.timeline', $task).'?before=106')->assertForbidden();
        $this->actingAs($member);
        foreach (['0', '-1', '1.5', 'bad', '999999999999999999999999', ''] as $cursor) {
            $this->getJson(route('tasks.timeline', $task).'?before='.$cursor)->assertUnprocessable();
        }
    }

    public function test_late_self_review_conflict_prevents_every_reassignment(): void
    {
        [$manager, $old, $new, $member, $project] = $this->fixture();
        for ($i = 0; $i < 205; $i++) {
            Task::query()->forceCreate(['title' => 'Qualification work', 'project_id' => $project->id, 'assignee_id' => $i === 204 ? $new->id : $member->id,
                'reviewer_id' => $old->id, 'status' => 'not_started']);
        }
        $this->actingAs($manager)->putJson(route('projects.update', $project),
            ['name' => $project->name, 'status' => 'active', 'project_manager_id' => $new->id])->assertUnprocessable();
        $this->assertSame(205, Task::where('reviewer_id', $old->id)->count());
        $this->assertDatabaseCount('task_events', 0);
        $this->assertDatabaseCount('task_histories', 0);
        $this->assertDatabaseCount('workflow_notification_intents', 0);
    }

    public function test_role_validation_and_unrelated_replacement_hydrate_no_tasks(): void
    {
        [$manager, $old, $new, $member, $project] = $this->fixture();
        User::factory()->create(['role_id' => $manager->role_id]);
        for ($i = 0; $i < 205; $i++) {
            Task::query()->forceCreate(['title' => 'Qualification work', 'project_id' => $project->id, 'assignee_id' => $member->id,
                'reviewer_id' => $manager->id, 'status' => 'not_started']);
        }
        $hydrated = 0;
        Event::listen('eloquent.retrieved: '.Task::class, function () use (&$hydrated) {
            $hydrated++;
        });
        $this->actingAs($manager)->putJson(route('projects.update', $project),
            ['name' => $project->name, 'status' => 'active', 'project_manager_id' => $new->id])->assertOk();
        try {
            app(AccountLifecycleService::class)->assertCanChangeRole($manager, $new->role_id, $manager);
            $this->fail('Foreign project duties must block demotion.');
        } catch (AccountLifecycleException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame(0, $hydrated);
    }

    public function test_notification_presentation_memoization_expires_on_next_request(): void
    {
        [$manager, $old, $new, $member, $project] = $this->fixture();
        $id = (string) Str::uuid();
        DB::table('notifications')->insert(['id' => $id, 'type' => 'Qualification', 'notifiable_type' => User::class,
            'notifiable_id' => $old->id, 'data' => json_encode(['project_id' => $project->id, 'message' => 'Protected notice']),
            'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($old)->get('/notifications/all')->assertOk()->assertSee('Protected notice');
        $project->update(['project_manager_id' => $new->id]);
        $this->assertFalse(app(NotificationAccess::class)->allows($old->fresh(), ['project_id' => $project->id]));
        $this->get('/notifications/all')->assertOk()->assertDontSee('Protected notice')->assertSee('no longer available');
    }

    public function test_selective_reconciliation_matches_reviewer_eligibility_for_retained_rows(): void
    {
        [$manager, $old, $new, $member, $project] = $this->fixture();
        $inactive = User::factory()->create(['role_id' => $manager->role_id, 'active' => false]);
        $foreignPm = User::factory()->create(['role_id' => $old->role_id]);
        $future = clone $project;
        $future->project_manager_id = $new->id;
        $expected = [];
        foreach ([$old, $new, $manager, $inactive, $foreignPm, $member, null] as $reviewer) {
            foreach (['not_started', 'completed', 'cancelled'] as $state) {
                $task = Task::query()->forceCreate(['title' => 'Eligibility parity', 'project_id' => $project->id,
                    'assignee_id' => $member->id, 'reviewer_id' => $reviewer?->id, 'status' => $state]);
                $changes = $state === 'not_started' && $reviewer && ($reviewer->is($old)
                    || ! app(ReviewerEligibilityService::class)->isEligible($reviewer, $future, $member->id));
                $expected[$task->id] = $changes ? $new->id : $reviewer?->id;
            }
        }
        $this->actingAs($manager)->putJson(route('projects.update', $project),
            ['name' => $project->name, 'status' => 'active', 'project_manager_id' => $new->id])->assertOk();
        foreach ($expected as $id => $reviewerId) {
            $this->assertSame($reviewerId, Task::findOrFail($id)->reviewer_id);
        }
    }

    public function test_bulk_reconciliation_keeps_transaction_records_bounded_and_rolls_back_a_late_failure(): void
    {
        [$manager, $old, $new, $member, $project] = $this->fixture();
        for ($i = 0; $i < 205; $i++) {
            Task::query()->forceCreate(['title' => 'Late rollback work', 'project_id' => $project->id,
                'assignee_id' => $member->id, 'reviewer_id' => $old->id, 'status' => 'not_started']);
        }
        $baseline = app('db.transactions')->getCommittedTransactions()->count();
        $seen = 0;
        $maximum = $baseline;
        Event::listen('eloquent.created: '.TaskEvent::class, function () use (&$seen, &$maximum) {
            $maximum = max($maximum, app('db.transactions')->getCommittedTransactions()->count());
            if (++$seen === 101) {
                throw new \RuntimeException('Controlled second-chunk failure.');
            }
        });
        $this->actingAs($manager)->putJson(route('projects.update', $project),
            ['name' => $project->name, 'status' => 'active', 'project_manager_id' => $new->id])->assertServerError();
        $this->assertSame(101, $seen);
        $this->assertLessThanOrEqual($baseline + 1, $maximum);
        $this->assertSame(205, Task::where('reviewer_id', $old->id)->where('lock_version', 1)->count());
        $this->assertDatabaseCount('task_events', 0);
        $this->assertDatabaseCount('task_histories', 0);
        $this->assertDatabaseCount('workflow_notification_intents', 0);
    }
}
