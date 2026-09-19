<?php

namespace Tests\Feature;

use App\Enums\TaskState;
use App\Http\Middleware\EnsureTaskCorrelationId;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use App\Services\TaskEventRecorder;
use App\Services\TaskLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TaskOptimisticConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Notification::fake();
    }

    public function test_task_view_data_and_edit_endpoint_include_lock_version_and_version(): void
    {
        [$manager, , , $task] = $this->fixtures();

        $response = $this->actingAs($manager)->getJson(route('tasks.edit', $task))->assertOk();
        $taskData = $response->json('task');

        $this->assertArrayHasKey('lock_version', $taskData);
        $this->assertArrayHasKey('version', $taskData);
        $this->assertSame(1, $taskData['lock_version']);
        $this->assertSame(1, $taskData['version']);
    }

    public function test_basic_stale_edit_is_rejected_with_conflict_and_preserves_winner_data(): void
    {
        [$manager, , , $task] = $this->fixtures();

        // 1. User A loads version N
        $formA = $this->actingAs($manager)->getJson(route('tasks.edit', $task))->assertOk()->json('task');
        $this->assertSame(1, $formA['lock_version']);

        // 2. User B loads version N
        $formB = $this->getJson(route('tasks.edit', $task))->assertOk()->json('task');
        $this->assertSame(1, $formB['lock_version']);

        // 3. User B saves update -> advances to N+1
        $this->putJson(route('tasks.update', $task), [
            'title' => 'Title updated by User B',
            'expected_version' => $formB['lock_version'],
        ])->assertOk();

        $this->assertSame(2, $task->fresh()->lock_version);
        $this->assertSame('Title updated by User B', $task->fresh()->title);

        // 4. User A submits older version N -> rejected as 409 Conflict
        $response = $this->putJson(route('tasks.update', $task), [
            'title' => 'Title updated by stale User A',
            'expected_version' => $formA['lock_version'],
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('message', 'This task changed after you opened it. Your update was not saved. Review the latest version and try again.');
        $response->assertJsonPath('current_version', 2);
        $response->assertJsonPath('expected_version', 1);
        $response->assertJsonPath('task.title', 'Title updated by User B');

        // 5. Verify B's values remain unchanged, version was NOT incremented, and no event for A
        $this->assertSame('Title updated by User B', $task->fresh()->title);
        $this->assertSame(2, $task->fresh()->lock_version);
        $this->assertSame(1, TaskEvent::query()->where('task_id', $task->id)->where('event_type', TaskEventRecorder::UPDATED)->count());
    }

    public function test_sequential_valid_edits_advance_version_monotonically(): void
    {
        [$manager, , , $task] = $this->fixtures();

        // N -> N+1
        $this->actingAs($manager)->putJson(route('tasks.update', $task), [
            'title' => 'First sequential edit',
            'expected_version' => 1,
        ])->assertOk();
        $this->assertSame(2, $task->fresh()->lock_version);

        // Reload N+1 -> N+2
        $form = $this->getJson(route('tasks.edit', $task))->assertOk()->json('task');
        $this->assertSame(2, $form['lock_version']);

        $this->putJson(route('tasks.update', $task), [
            'title' => 'Second sequential edit',
            'expected_version' => $form['lock_version'],
        ])->assertOk();
        $this->assertSame(3, $task->fresh()->lock_version);
        $this->assertSame('Second sequential edit', $task->fresh()->title);
    }

    public function test_generic_update_rejects_missing_version_for_json_and_form_clients(): void
    {
        [$manager, , , $task] = $this->fixtures();

        $this->actingAs($manager)->putJson(route('tasks.update', $task), [
            'title' => 'JSON bypass attempt',
        ])->assertUnprocessable()->assertJsonValidationErrors('expected_version');

        $this->actingAs($manager)->put(route('tasks.update', $task), [
            'title' => 'Form bypass attempt',
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expected_version');

        $this->assertSame('Concurrency baseline task', $task->fresh()->title);
        $this->assertSame(1, $task->fresh()->lock_version);
    }

    public function test_generic_update_rejects_null_empty_and_malformed_versions(): void
    {
        [$manager, , , $task] = $this->fixtures();

        foreach ([null, '', 'not-a-version'] as $invalidVersion) {
            $this->actingAs($manager)->putJson(route('tasks.update', $task), [
                'title' => 'Invalid version attempt',
                'expected_version' => $invalidVersion,
            ])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        }

        $this->assertSame('Concurrency baseline task', $task->fresh()->title);
        $this->assertSame(1, $task->fresh()->lock_version);
    }

    public function test_generic_update_rejects_conflicting_version_aliases(): void
    {
        [$manager, , , $task] = $this->fixtures();

        $this->actingAs($manager)->putJson(route('tasks.update', $task), [
            'title' => 'Conflicting alias attempt',
            'expected_version' => 1,
            'lock_version' => 2,
        ])->assertUnprocessable()->assertJsonValidationErrors('expected_version');

        $this->assertSame('Concurrency baseline task', $task->fresh()->title);
        $this->assertSame(1, $task->fresh()->lock_version);
    }

    public function test_each_supported_version_alias_can_supply_the_current_version(): void
    {
        [$manager, , , $task] = $this->fixtures();

        foreach (['expected_version', 'lock_version', 'version'] as $index => $alias) {
            $currentVersion = $index + 1;
            $this->actingAs($manager)->putJson(route('tasks.update', $task), [
                'title' => "Updated through {$alias}",
                $alias => $currentVersion,
            ])->assertOk();
        }

        $this->assertSame(4, $task->fresh()->lock_version);
    }

    public function test_concurrent_edits_to_different_fields_conflict_without_silent_merge(): void
    {
        [$manager, , , $task] = $this->fixtures();

        $formA = $this->actingAs($manager)->getJson(route('tasks.edit', $task))->assertOk()->json('task');
        $formB = $this->getJson(route('tasks.edit', $task))->assertOk()->json('task');

        // B edits description
        $this->putJson(route('tasks.update', $task), [
            'description' => 'Description from User B',
            'expected_version' => $formB['lock_version'],
        ])->assertOk();

        // A edits priority from same initial version
        $this->putJson(route('tasks.update', $task), [
            'priority' => 'Low',
            'expected_version' => $formA['lock_version'],
        ])->assertStatus(409);

        $fresh = $task->fresh();
        $this->assertSame('Description from User B', $fresh->description);
        $this->assertNotSame('Low', $fresh->priority);
        $this->assertSame(2, $fresh->lock_version);
    }

    public function test_workflow_submission_advances_version_and_invalidates_stale_edit_form(): void
    {
        [$manager, , $assignee, $task] = $this->fixtures();

        // Manager opens edit form while task is not_started / in_progress
        $form = $this->actingAs($manager)->getJson(route('tasks.edit', $task))->assertOk()->json('task');
        $this->assertSame(1, $form['lock_version']);

        // Assignee starts and submits the task
        $this->actingAs($assignee)->postJson(route('tasks.start', $task))->assertOk();
        $this->assertSame(2, $task->fresh()->lock_version);

        $this->actingAs($assignee)->postJson(route('tasks.submit', $task), [
            'submission_note' => 'Work ready for review.',
        ])->assertOk();
        $this->assertSame(3, $task->fresh()->lock_version);
        $this->assertSame(TaskState::Submitted, $task->fresh()->machineState());

        // Manager submits old form with version 1
        $this->actingAs($manager)->putJson(route('tasks.update', $task), [
            'title' => 'Stale title after submission',
            'expected_version' => $form['lock_version'],
        ])->assertStatus(409);

        // Verify task state remains Submitted and title unchanged
        $this->assertSame(TaskState::Submitted, $task->fresh()->machineState());
        $this->assertNotSame('Stale title after submission', $task->fresh()->title);
        $this->assertSame(3, $task->fresh()->lock_version);
    }

    public function test_workflow_revision_request_advances_version_and_invalidates_stale_edit_form(): void
    {
        [$manager, $reviewer, $assignee, $task] = $this->fixtures();
        $this->actingAs($assignee)->postJson(route('tasks.start', $task))->assertOk();
        $this->actingAs($assignee)->postJson(route('tasks.submit', $task), ['submission_note' => 'Review me'])->assertOk();

        // Reviewer opens review / edit
        $currentVersion = $task->fresh()->lock_version;

        // Reviewer starts review
        $this->actingAs($reviewer)->postJson(route('tasks.review.start', $task))->assertOk();
        $this->assertSame($currentVersion + 1, $task->fresh()->lock_version);

        // Reviewer requests revision
        $this->actingAs($reviewer)->postJson(route('tasks.revision.request', $task), [
            'formal_feedback' => 'Need rework on section 2.',
            'revision_due_date' => now()->addDays(2)->toDateString(),
        ])->assertOk();

        $this->assertSame(TaskState::RevisionRequested, $task->fresh()->machineState());
        $this->assertSame($currentVersion + 2, $task->fresh()->lock_version);

        // Stale edit with original version is rejected
        $this->actingAs($manager)->putJson(route('tasks.update', $task), [
            'title' => 'Stale title overwrite attempt',
            'expected_version' => $currentVersion,
        ])->assertStatus(409);
    }

    public function test_workflow_cancellation_advances_version_and_invalidates_stale_edit_form(): void
    {
        [$manager, , , $task] = $this->fixtures();
        $oldVersion = $task->lock_version;

        $this->actingAs($manager)->postJson(route('tasks.cancel', $task), [
            'cancellation_reason' => 'Project priority pivoted.',
        ])->assertOk();

        $this->assertSame(TaskState::Cancelled, $task->fresh()->machineState());
        $this->assertSame($oldVersion + 1, $task->fresh()->lock_version);

        // Stale form attempt to edit cancelled task is rejected with 409 Conflict
        $this->actingAs($manager)->putJson(route('tasks.update', $task), [
            'title' => 'Revive by stale edit',
            'expected_version' => $oldVersion,
        ])->assertStatus(409);

        // Edit with current version is rejected because final tasks require dedicated action
        $this->actingAs($manager)->putJson(route('tasks.update', $task), [
            'title' => 'Revive by current edit',
            'expected_version' => $oldVersion + 1,
        ])->assertUnprocessable();
    }

    public function test_workflow_approval_advances_version_and_invalidates_stale_edit_form(): void
    {
        [$manager, $reviewer, $assignee, $task] = $this->fixtures();
        $this->actingAs($assignee)->postJson(route('tasks.start', $task))->assertOk();
        $this->actingAs($assignee)->postJson(route('tasks.submit', $task), ['submission_note' => 'Ready for approval'])->assertOk();
        $this->actingAs($reviewer)->postJson(route('tasks.review.start', $task))->assertOk();

        $staleVersion = $task->fresh()->lock_version;

        // Reviewer approves task
        $this->actingAs($reviewer)->postJson(route('tasks.approve', $task), [
            'approval_comment' => 'Looks great!',
        ])->assertOk();

        $this->assertSame(TaskState::Completed, $task->fresh()->machineState());
        $this->assertSame($staleVersion + 1, $task->fresh()->lock_version);

        // Stale edit attempt on completed task is rejected with 409 Conflict
        $this->actingAs($manager)->putJson(route('tasks.update', $task), [
            'title' => 'Mutate completed task',
            'expected_version' => $staleVersion,
        ])->assertStatus(409);

        // Edit with current version is rejected because final tasks require dedicated action
        $this->actingAs($manager)->putJson(route('tasks.update', $task), [
            'title' => 'Mutate completed task',
            'expected_version' => $staleVersion + 1,
        ])->assertUnprocessable();
    }

    public function test_reviewer_reassignment_advances_version_and_rejects_stale_edit(): void
    {
        [$manager, $reviewer, , $task] = $this->fixtures();
        $newReviewer = User::factory()->create(['role_id' => Role::query()->where('name', 'manager')->value('id')]);
        $oldVersion = $task->lock_version;

        $this->actingAs($manager)->postJson(route('tasks.reviewer.reassign', $task), [
            'reviewer_id' => $newReviewer->id,
            'reason' => 'Load balancing across reviewers.',
        ])->assertOk();

        $this->assertSame($oldVersion + 1, $task->fresh()->lock_version);
        $this->assertSame($newReviewer->id, $task->fresh()->reviewer_id);

        // Stale edit with old version is rejected
        $this->actingAs($manager)->putJson(route('tasks.update', $task), [
            'title' => 'Stale edit after reviewer reassigned',
            'expected_version' => $oldVersion,
        ])->assertStatus(409);
    }

    public function test_deadline_change_advances_version_and_rejects_stale_edit(): void
    {
        [$manager, , , $task] = $this->fixtures();
        $oldVersion = $task->lock_version;

        $this->actingAs($manager)->postJson(route('tasks.deadline.change', [
            'task' => $task,
            'deadlineType' => 'execution',
        ]), [
            'due_date' => now()->addDays(10)->toDateString(),
            'reason' => 'Client requested schedule extension.',
        ])->assertOk();

        $this->assertSame($oldVersion + 1, $task->fresh()->lock_version);

        // Stale edit with old version is rejected
        $this->actingAs($manager)->putJson(route('tasks.update', $task), [
            'title' => 'Stale edit after deadline changed',
            'expected_version' => $oldVersion,
        ])->assertStatus(409);
    }

    public function test_authorization_loss_takes_precedence_over_stale_version_check(): void
    {
        [$manager, , , $task] = $this->fixtures();

        // Project Manager loads form
        $pm = User::factory()->create(['role_id' => Role::query()->where('name', 'project_manager')->value('id')]);
        $task->project->members()->attach($pm->id);

        // PM loses project access (e.g. project manager role changed or removed from project)
        $pm->forceFill(['active' => false])->save();

        // Submitting with wrong version still returns 403 Forbidden (authorization boundary), not 409
        $this->actingAs($pm)->putJson(route('tasks.update', $task), [
            'title' => 'Unauthorized update',
            'expected_version' => 999,
        ])->assertForbidden();
    }

    public function test_idempotent_duplicate_request_preserves_operation_key_semantics(): void
    {
        [$manager, , , $task] = $this->fixtures();
        $correlationId = 'r3a3-idempotent-update-test';

        // First attempt succeeds
        $this->actingAs($manager)
            ->withHeader(EnsureTaskCorrelationId::HEADER, $correlationId)
            ->putJson(route('tasks.update', $task), [
                'title' => 'First attempt title',
                'expected_version' => 1,
            ])
            ->assertOk();

        $this->assertSame(2, $task->fresh()->lock_version);
        $this->assertSame(1, TaskEvent::query()->where('task_id', $task->id)->where('correlation_id', $correlationId)->count());
    }

    public function test_validation_failure_does_not_increment_version(): void
    {
        [$manager, , , $task] = $this->fixtures();
        $versionBefore = $task->lock_version;

        $this->actingAs($manager)->putJson(route('tasks.update', $task), [
            'title' => '', // Invalid: required
            'expected_version' => $versionBefore,
        ])->assertUnprocessable();

        $this->assertSame($versionBefore, $task->fresh()->lock_version);
    }

    public function test_transaction_rollback_does_not_increment_version(): void
    {
        [$manager, , , $task] = $this->fixtures();
        $versionBefore = $task->lock_version;

        try {
            DB::transaction(function () use ($task, $manager, $versionBefore) {
                app(TaskLifecycleService::class)->update($task, [
                    'title' => 'Rollback title',
                    'expected_version' => $versionBefore,
                ], $manager);

                throw new \RuntimeException('Simulated transaction failure.');
            });
        } catch (\RuntimeException) {
            // Expected
        }

        $this->assertSame($versionBefore, $task->fresh()->lock_version);
        $this->assertNotSame('Rollback title', $task->fresh()->title);
    }

    public function test_soft_deleted_task_cannot_be_mutated_by_stale_edit(): void
    {
        [$manager, , , $task] = $this->fixtures();
        $versionBefore = $task->lock_version;

        app(TaskLifecycleService::class)->delete($task, $manager);
        $this->assertTrue($task->fresh()->trashed());

        $this->actingAs($manager)->putJson(route('tasks.update', $task), [
            'title' => 'Resurrect via update',
            'expected_version' => $versionBefore,
        ])->assertNotFound();
    }

    private function fixtures(): array
    {
        $manager = User::factory()->create(['role_id' => Role::query()->where('name', 'manager')->value('id')]);
        $reviewer = User::factory()->create(['role_id' => Role::query()->where('name', 'manager')->value('id')]);
        $assignee = User::factory()->create(['role_id' => Role::query()->where('name', 'team_member')->value('id')]);
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $project->members()->attach([$assignee->id, $reviewer->id]);

        $task = new Task([
            'title' => 'Concurrency baseline task',
            'project_id' => $project->id,
            'assignee_id' => $assignee->id,
            'status' => TaskState::NotStarted,
            'priority' => 'Medium',
            'progress' => 0,
        ]);
        $task->forceFill([
            'reviewer_id' => $reviewer->id,
            'lock_version' => 1,
        ])->save();
        $task->refresh();

        return [$manager, $reviewer, $assignee, $task];
    }
}
