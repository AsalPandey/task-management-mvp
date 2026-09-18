<?php

namespace Tests\Feature;

use App\Enums\TaskState;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskRevisionCycle;
use App\Models\User;
use App\Notifications\TaskDeadlineReminderNotification;
use App\Notifications\TaskOverdueNotification;
use App\Services\TaskDeadlineOwnerResolver;
use App\Services\TaskLifecycleService;
use App\Services\TaskNotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TaskDeadlineOwnerRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Notification::fake();
    }

    public static function activeOwnerStates(): array
    {
        return [
            'not started execution' => [TaskState::NotStarted, false, 'assignee'],
            'in progress execution' => [TaskState::InProgress, false, 'assignee'],
            'on hold execution' => [TaskState::OnHold, false, 'assignee'],
            'submitted review' => [TaskState::Submitted, false, 'reviewer'],
            'in review' => [TaskState::InReview, false, 'reviewer'],
            'revision requested' => [TaskState::RevisionRequested, false, 'assignee'],
            'revision execution' => [TaskState::InProgress, true, 'assignee'],
        ];
    }

    #[DataProvider('activeOwnerStates')]
    public function test_scheduled_reminder_targets_authoritative_owner(
        TaskState $state,
        bool $revisionExecution,
        string $ownerKey,
    ): void {
        $f = $this->fixtures($state, 1, $revisionExecution);
        $expected = $f[$ownerKey];
        $other = $ownerKey === 'assignee' ? $f['reviewer'] : $f['assignee'];

        $ownership = app(TaskDeadlineOwnerResolver::class)->resolve($f['task']);
        $this->assertTrue($ownership->isDeliverable());
        $this->assertSame($expected->id, $ownership->owner?->id);
        $this->assertSame($expected->id, $ownership->generation?->responsibleUserId);

        $this->artisan('app:send-task-deadline-reminders')->assertSuccessful();

        Notification::assertSentTo($expected, TaskDeadlineReminderNotification::class);
        Notification::assertNotSentTo($other, TaskDeadlineReminderNotification::class);
    }

    public static function overdueOwnerStates(): array
    {
        return [
            'execution overdue' => [TaskState::InProgress, false, 'assignee'],
            'submitted overdue' => [TaskState::Submitted, false, 'reviewer'],
            'review overdue' => [TaskState::InReview, false, 'reviewer'],
            'revision overdue' => [TaskState::RevisionRequested, false, 'assignee'],
        ];
    }

    #[DataProvider('overdueOwnerStates')]
    public function test_scheduled_overdue_targets_authoritative_owner(
        TaskState $state,
        bool $revisionExecution,
        string $ownerKey,
    ): void {
        $f = $this->fixtures($state, -1, $revisionExecution);
        $expected = $f[$ownerKey];
        $other = $ownerKey === 'assignee' ? $f['reviewer'] : $f['assignee'];

        $this->artisan('app:send-overdue-task-notifications')->assertSuccessful();

        Notification::assertSentTo($expected, TaskOverdueNotification::class);
        Notification::assertNotSentTo($other, TaskOverdueNotification::class);
    }

    public function test_reviewer_reassignment_routes_future_review_reminder_only_to_new_reviewer(): void
    {
        $f = $this->fixtures(TaskState::Submitted, 1);
        $oldGeneration = $f['task']->activeDeadlineGeneration()?->fingerprint();

        $this->actingAs($f['manager'])->postJson(route('tasks.reviewer.reassign', $f['task']), [
            'reviewer_id' => $f['other_reviewer']->id,
            'reason' => 'Reviewer availability changed.',
        ])->assertOk();
        Notification::fake();

        $task = $f['task']->fresh();
        $this->assertNotSame($oldGeneration, $task->activeDeadlineGeneration()?->fingerprint());
        $this->artisan('app:send-task-deadline-reminders')->assertSuccessful();
        Notification::assertSentTo($f['other_reviewer'], TaskDeadlineReminderNotification::class);
        Notification::assertNotSentTo($f['reviewer'], TaskDeadlineReminderNotification::class);
        Notification::assertNotSentTo($f['assignee'], TaskDeadlineReminderNotification::class);
    }

    public function test_assignee_reassignment_routes_future_execution_reminder_only_to_new_assignee(): void
    {
        $f = $this->fixtures(TaskState::NotStarted, 1);
        $oldGeneration = $f['task']->activeDeadlineGeneration()?->fingerprint();

        app(TaskLifecycleService::class)->update(
            $f['task'],
            ['assignee_id' => $f['other_assignee']->id],
            $f['manager'],
        );
        Notification::fake();

        $task = $f['task']->fresh();
        $this->assertNotSame($oldGeneration, $task->activeDeadlineGeneration()?->fingerprint());
        $this->artisan('app:send-task-deadline-reminders')->assertSuccessful();
        Notification::assertSentTo($f['other_assignee'], TaskDeadlineReminderNotification::class);
        Notification::assertNotSentTo($f['assignee'], TaskDeadlineReminderNotification::class);
    }

    public function test_reopened_revision_and_resubmitted_review_use_assignee_then_reviewer(): void
    {
        $revision = $this->fixtures(TaskState::RevisionRequested, 1);
        $this->assertSame($revision['assignee']->id, app(TaskDeadlineOwnerResolver::class)
            ->resolve($revision['task'])->owner?->id);

        $review = $this->fixtures(TaskState::Submitted, 1, false, true);
        $this->assertSame($review['reviewer']->id, app(TaskDeadlineOwnerResolver::class)
            ->resolve($review['task'])->owner?->id);

        $this->artisan('app:send-task-deadline-reminders')->assertSuccessful();
        Notification::assertSentTo($revision['assignee'], TaskDeadlineReminderNotification::class);
        Notification::assertSentTo($review['reviewer'], TaskDeadlineReminderNotification::class);
    }

    public function test_invalid_or_missing_owner_is_suppressed_without_fallback(): void
    {
        $missingReviewer = $this->fixtures(TaskState::Submitted, 1);
        $missingReviewer['task']->forceFill(['reviewer_id' => null])->save();

        $inactiveReviewer = $this->fixtures(TaskState::InReview, 1);
        $inactiveReviewer['reviewer']->forceFill(['active' => false])->save();

        $ineligibleReviewer = $this->fixtures(TaskState::Submitted, 1);
        $ineligibleReviewer['task']->forceFill([
            'reviewer_id' => $ineligibleReviewer['unrelated_reviewer']->id,
        ])->save();

        $missingAssignee = $this->fixtures(TaskState::InProgress, 1);
        $missingAssignee['task']->forceFill(['assignee_id' => null])->save();

        $inactiveAssignee = $this->fixtures(TaskState::RevisionRequested, 1);
        $inactiveAssignee['assignee']->forceFill(['active' => false])->save();

        $removedAssignee = $this->fixtures(TaskState::OnHold, 1);
        $removedAssignee['project']->members()->detach($removedAssignee['assignee']->id);

        foreach ([$missingReviewer, $inactiveReviewer, $ineligibleReviewer, $missingAssignee, $inactiveAssignee, $removedAssignee] as $case) {
            $ownership = app(TaskDeadlineOwnerResolver::class)->resolve($case['task']->fresh());
            $this->assertFalse($ownership->isDeliverable());
            $this->assertNotNull($ownership->suppressionReason);
        }

        $this->artisan('app:send-task-deadline-reminders')
            ->expectsOutputToContain('Suppressed 6')
            ->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_final_and_soft_deleted_tasks_have_no_deliverable_owner(): void
    {
        $completed = $this->fixtures(TaskState::Completed, 1);
        $cancelled = $this->fixtures(TaskState::Cancelled, 1);
        $deleted = $this->fixtures(TaskState::InProgress, 1);
        $deleted['task']->delete();

        $resolver = app(TaskDeadlineOwnerResolver::class);
        $this->assertFalse($resolver->resolve($completed['task'])->isDeliverable());
        $this->assertFalse($resolver->resolve($cancelled['task'])->isDeliverable());
        $this->assertFalse($resolver->resolve($deleted['task'])->isDeliverable());

        $this->artisan('app:send-task-deadline-reminders')->assertSuccessful();
        Notification::assertNothingSent();
    }

    public static function opportunisticOwnerStates(): array
    {
        return [
            'execution update' => [TaskState::InProgress, false, 'assignee'],
            'review update' => [TaskState::InReview, false, 'reviewer'],
            'revision update' => [TaskState::InProgress, true, 'assignee'],
        ];
    }

    #[DataProvider('opportunisticOwnerStates')]
    public function test_opportunistic_overdue_uses_same_authoritative_owner(
        TaskState $state,
        bool $revisionExecution,
        string $ownerKey,
    ): void {
        $f = $this->fixtures($state, -1, $revisionExecution);
        $expected = $f[$ownerKey];
        $other = $ownerKey === 'assignee' ? $f['reviewer'] : $f['assignee'];

        DB::transaction(function () use ($f): void {
            app(TaskNotificationDispatcher::class)->taskUpdated(
                $f['task'],
                ['comments' => 'Trigger authoritative overdue evaluation.'],
                $f['manager'],
            );
        });

        Notification::assertSentTo($expected, TaskOverdueNotification::class);
        Notification::assertNotSentTo($other, TaskOverdueNotification::class);
        $this->assertSame(
            $expected->id,
            $f['task']->fresh()->activeDeadlineGeneration()?->responsibleUserId,
        );
    }

    public function test_same_owner_state_and_deadline_retains_generation(): void
    {
        $f = $this->fixtures(TaskState::InReview, 1);
        $before = $f['task']->activeDeadlineGeneration()?->fingerprint();

        $f['task']->forceFill(['progress' => 51])->save();

        $this->assertSame($before, $f['task']->fresh()->activeDeadlineGeneration()?->fingerprint());
        $this->assertSame(
            $f['reviewer']->id,
            app(TaskDeadlineOwnerResolver::class)->resolve($f['task']->fresh())->owner?->id,
        );
    }

    public function test_owner_resolution_reloads_task_before_delivery(): void
    {
        $f = $this->fixtures(TaskState::Submitted, 1);
        $staleTask = $f['task'];

        DB::table('tasks')->where('id', $staleTask->id)->update([
            'reviewer_id' => $f['other_reviewer']->id,
        ]);

        $ownership = app(TaskDeadlineOwnerResolver::class)->resolve($staleTask);
        $this->assertTrue($ownership->isDeliverable());
        $this->assertSame($f['other_reviewer']->id, $ownership->owner?->id);
        $this->assertSame($f['other_reviewer']->id, $ownership->generation?->responsibleUserId);
    }

    private function fixtures(
        TaskState $state,
        int $deadlineOffset,
        bool $revisionExecution = false,
        bool $resubmittedReview = false,
    ): array {
        $manager = $this->user('manager');
        $reviewer = $this->user('project_manager');
        $otherReviewer = $this->user('manager');
        $unrelatedReviewer = $this->user('project_manager');
        $assignee = $this->user('team_member');
        $otherAssignee = $this->user('team_member');
        $project = Project::factory()->create(['project_manager_id' => $reviewer->id]);
        $project->members()->attach([$assignee->id, $otherAssignee->id]);
        $deadline = today(config('app.timezone'))->addDays($deadlineOffset)->toDateString();

        $task = Task::query()->create([
            'project_id' => $project->id,
            'title' => "Deadline owner {$state->value}",
            'assignee_id' => $assignee->id,
            'created_by' => $manager->id,
            'assigned_by' => $manager->id,
            'priority' => 'Medium',
            'status' => $state,
            'progress' => $state === TaskState::NotStarted ? 0 : 50,
            'due_date' => $deadline,
        ]);
        $task->forceFill([
            'reviewer_id' => $reviewer->id,
            'execution_due_date' => $deadline,
            'review_due_date' => in_array($state, [TaskState::Submitted, TaskState::InReview], true)
                ? $deadline
                : null,
            'submitted_at' => in_array($state, [TaskState::Submitted, TaskState::InReview], true)
                ? now()->subHour()
                : null,
            'review_started_at' => $state === TaskState::InReview ? now()->subMinutes(30) : null,
            'completed_at' => $state === TaskState::Completed ? now() : null,
            'cancelled_at' => $state === TaskState::Cancelled ? now() : null,
        ])->save();

        if ($state === TaskState::RevisionRequested || $revisionExecution || $resubmittedReview) {
            $cycle = TaskRevisionCycle::query()->create([
                'task_id' => $task->id,
                'cycle_number' => 1,
                'requested_by' => $reviewer->id,
                'requested_at' => now()->subDays(2),
                'revision_due_date' => $deadline,
                'started_at' => $revisionExecution ? now()->subDay() : null,
                'resubmitted_at' => $resubmittedReview ? now()->subHour() : null,
                'origin' => $resubmittedReview ? 'review_revision' : 'completed_reopen',
            ]);
            $task->forceFill([
                'active_revision_cycle_id' => $cycle->id,
                'revision_due_date' => $resubmittedReview ? null : $deadline,
            ])->save();
        }

        return [
            'manager' => $manager,
            'reviewer' => $reviewer,
            'other_reviewer' => $otherReviewer,
            'unrelated_reviewer' => $unrelatedReviewer,
            'assignee' => $assignee,
            'other_assignee' => $otherAssignee,
            'project' => $project,
            'task' => $task->fresh(),
        ];
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('name', $role)->value('id'),
        ]);
    }
}
