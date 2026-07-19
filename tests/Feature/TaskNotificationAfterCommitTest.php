<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskCompletedNotification;
use App\Notifications\TaskOverdueNotification;
use App\Notifications\TaskRevertedNotification;
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
                'priority' => 'High',
                'status' => 'In Progress',
                'progress' => 10,
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

    public function test_completion_notification_is_dispatched_after_commit_with_both_task_identifiers(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $task = $this->task($project, $assignee);

        DB::transaction(function () use ($manager, $task): void {
            app(TaskLifecycleService::class)->complete($task, $manager);
            Notification::assertNothingSent();
        });

        Notification::assertSentTo(
            $assignee,
            TaskCompletedNotification::class,
            fn (TaskCompletedNotification $notification): bool => $this->hasTaskIdentity($notification, $assignee, $task),
        );
    }

    public function test_reopen_notification_is_dispatched_after_commit_with_both_task_identifiers(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $task = $this->completedTask($project, $assignee, $manager);

        DB::transaction(function () use ($manager, $task): void {
            app(TaskLifecycleService::class)->reopen($task, $manager);
            Notification::assertNothingSent();
        });

        Notification::assertSentTo(
            $assignee,
            TaskRevertedNotification::class,
            fn (TaskRevertedNotification $notification): bool => $this->hasTaskIdentity($notification, $assignee, $task),
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
                    'priority' => 'Medium',
                    'status' => 'In Progress',
                    'progress' => 20,
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

    public function test_duplicate_completion_does_not_dispatch_another_notification(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $service = app(TaskLifecycleService::class);
        $completed = $service->complete($this->task($project, $assignee), $manager);

        try {
            $service->complete($completed, $manager);
            $this->fail('Expected duplicate completion to be rejected.');
        } catch (ValidationException) {
            Notification::assertSentToTimes($assignee, TaskCompletedNotification::class, 1);
        }
    }

    public function test_duplicate_reopen_does_not_dispatch_another_notification(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $service = app(TaskLifecycleService::class);
        $reopened = $service->reopen($this->completedTask($project, $assignee, $manager), $manager);

        try {
            $service->reopen($reopened, $manager);
            $this->fail('Expected duplicate reopen to be rejected.');
        } catch (ValidationException) {
            Notification::assertSentToTimes($assignee, TaskRevertedNotification::class, 1);
        }
    }

    public function test_bulk_completion_dispatches_once_for_each_successfully_completed_task(): void
    {
        [$manager, $project, $assignee] = $this->managedProject();
        $first = $this->task($project, $assignee, ['title' => 'First bulk notification']);
        $second = $this->task($project, $assignee, ['title' => 'Second bulk notification']);

        DB::transaction(function () use ($manager, $first, $second): void {
            app(TaskLifecycleService::class)->completeMany([$second->id, $first->id, $first->id], $manager);
            Notification::assertNothingSent();
        });

        Notification::assertSentToTimes($assignee, TaskCompletedNotification::class, 2);

        $notifiedTaskIds = Notification::sent($assignee, TaskCompletedNotification::class)
            ->map(fn (TaskCompletedNotification $notification): int => (int) $notification->toArray($assignee)['task_id'])
            ->sort()
            ->values()
            ->all();

        $this->assertSame([$first->id, $second->id], $notifiedTaskIds);
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
        $task = $this->task($project, $assignee, [
            'status' => 'Completed',
            'progress' => 100,
        ]);

        $task->forceFill([
            'completed_at' => now(),
            'completed_by' => $manager->id,
        ])->save();

        return $task->fresh();
    }

    private function hasTaskIdentity(object $notification, User $notifiable, Task $task): bool
    {
        $payload = $notification->toArray($notifiable);

        return (int) $payload['task_id'] === (int) $task->id
            && $payload['task_uid'] === $task->task_uid;
    }
}
