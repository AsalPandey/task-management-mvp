<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskOverdueNotification;
use App\Notifications\TaskReviewWorkflowNotification;
use App\Notifications\TaskUpdatedNotification;
use App\Services\TaskLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class TaskNotificationAfterCommitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Notification::fake();
    }

    public function test_create_notification_is_dispatched_after_commit_with_both_task_identifiers(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $created = null;

        DB::transaction(function () use ($manager, $project, $assignee, &$created): void {
            $created = app(TaskLifecycleService::class)->create([
                'project_id' => $project->id,
                'title' => 'After-commit creation',
                'assignee_id' => $assignee->id,
                'reviewer_id' => $project->project_manager_id,
                'priority' => 'High',
            ], $manager);

            Notification::assertNothingSent();
        });

        Notification::assertSentTo(
            $assignee,
            TaskAssignedNotification::class,
            fn (TaskAssignedNotification $notification): bool => $this->hasTaskIdentity($notification, $assignee, $created),
        );
    }

    public function test_update_notification_is_dispatched_after_commit_with_both_task_identifiers(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $task = $this->task($project, $assignee);

        DB::transaction(function () use ($manager, $task): void {
            app(TaskLifecycleService::class)->update($task, ['title' => 'Committed update'], $manager);
            Notification::assertNothingSent();
        });

        Notification::assertSentTo(
            $assignee,
            TaskUpdatedNotification::class,
            fn (TaskUpdatedNotification $notification): bool => $this->hasTaskIdentity($notification, $assignee, $task),
        );
    }

    public function test_overdue_notification_and_marker_are_deferred_until_commit(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $task = $this->task($project, $assignee, ['due_date' => now()->subDay()->toDateString()]);

        DB::transaction(function () use ($manager, $task): void {
            app(TaskLifecycleService::class)->update($task, ['comments' => 'Still being worked'], $manager);

            Notification::assertNothingSent();
            $this->assertNull($task->fresh()->overdue_notification_sent_at);
        });

        Notification::assertSentToTimes($assignee, TaskUpdatedNotification::class, 1);
        Notification::assertSentTo(
            $assignee,
            TaskOverdueNotification::class,
            fn (TaskOverdueNotification $notification): bool => $this->hasTaskIdentity($notification, $assignee, $task),
        );
        $this->assertNotNull($task->fresh()->overdue_notification_sent_at);
    }

    public function test_approval_notification_is_dispatched_after_commit_with_both_task_identifiers(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $task = $this->task($project, $assignee);

        DB::transaction(function () use ($manager, $task): void {
            $this->approveTask($task, $manager);
            Notification::assertNothingSent();
        });

        Notification::assertSentTo(
            $assignee,
            TaskReviewWorkflowNotification::class,
            fn (TaskReviewWorkflowNotification $notification): bool => $this->hasTaskIdentity($notification, $assignee, $task),
        );
    }

    public function test_reopen_notification_is_dispatched_after_commit_with_both_task_identifiers(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $task = $this->completedTask($project, $assignee, $manager);
        Notification::fake();

        DB::transaction(function () use ($manager, $task): void {
            $this->reopenApprovedTask($task, $manager);
            Notification::assertNothingSent();
        });

        Notification::assertSentTo(
            $assignee,
            TaskReviewWorkflowNotification::class,
            fn (TaskReviewWorkflowNotification $notification): bool => $this->hasTaskIdentity($notification, $assignee, $task),
        );
    }

    public function test_transaction_rollback_dispatches_nothing_and_rolls_back_all_lifecycle_rows(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();

        try {
            DB::transaction(function () use ($manager, $project, $assignee): void {
                app(TaskLifecycleService::class)->create([
                    'project_id' => $project->id,
                    'title' => 'Rolled-back creation',
                    'assignee_id' => $assignee->id,
                    'reviewer_id' => $project->project_manager_id,
                    'priority' => 'Medium',
                ], $manager);

                Notification::assertNothingSent();

                throw new RuntimeException('Force the outer transaction to roll back.');
            });

            $this->fail('Expected the outer transaction to roll back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Force the outer transaction to roll back.', $exception->getMessage());
        }

        Notification::assertNothingSent();
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('task_histories', 0);
        $this->assertDatabaseCount('task_events', 0);
    }

    public function test_duplicate_approval_does_not_dispatch_another_notification(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $completed = $this->approveTask($this->task($project, $assignee), $manager);
        Notification::assertSentToTimes($assignee, TaskReviewWorkflowNotification::class, 1);
        Notification::fake();

        $this->actingAs($manager)
            ->postJson(route('tasks.approve.override', $completed), ['override_reason' => 'Duplicate'])
            ->assertUnprocessable();

        Notification::assertNothingSent();
    }

    public function test_duplicate_reopen_does_not_dispatch_another_notification(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $completed = $this->completedTask($project, $assignee, $manager);
        Notification::fake();
        $reopened = $this->reopenApprovedTask($completed, $manager);

        try {
            $this->reopenApprovedTask($reopened, $manager);
            $this->fail('Expected duplicate reopen to be rejected.');
        } catch (ValidationException) {
            Notification::assertSentToTimes($assignee, TaskReviewWorkflowNotification::class, 1);
        }
    }

    public function test_retired_bulk_completion_dispatches_nothing(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $first = $this->task($project, $assignee, ['title' => 'First bulk notification']);
        $second = $this->task($project, $assignee, ['title' => 'Second bulk notification']);

        $this->actingAs($manager)
            ->postJson(route('tasks.bulk-complete'), ['task_ids' => [$second->id, $first->id, $first->id]])
            ->assertGone();

        Notification::assertNothingSent();
        $this->assertSame('In Progress', $first->fresh()->status);
        $this->assertSame('In Progress', $second->fresh()->status);
    }

    private function managedProject(): array
    {
        $manager = $this->userWithRole('manager');
        $projectManager = $this->userWithRole('project_manager');
        $project = Project::factory()->create(['project_manager_id' => $projectManager->id]);
        $assignee = $this->userWithRole('team_member');
        $project->members()->attach($assignee->id);

        return [$manager, $project, $assignee];
    }

    private function userWithRole(string $role): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('name', $role)->value('id'),
        ]);
    }

    private function task(Project $project, User $assignee, array $attributes = []): Task
    {
        return Task::query()->create(array_merge([
            'project_id' => $project->id,
            'title' => 'Notification lifecycle task',
            'description' => 'Verify after-commit delivery',
            'assignee_id' => $assignee->id,
            'priority' => 'Medium',
            'status' => 'In Progress',
            'progress' => 40,
        ], $attributes));
    }

    private function completedTask(Project $project, User $assignee, User $manager): Task
    {
        return $this->approveTask($this->task($project, $assignee), $manager);
    }

    private function hasTaskIdentity(object $notification, User $notifiable, Task $task): bool
    {
        $payload = $notification->toArray($notifiable);

        return (int) $payload['task_id'] === (int) $task->id
            && $payload['task_uid'] === $task->task_uid;
    }
}
