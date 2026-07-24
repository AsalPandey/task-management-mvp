<?php

namespace Tests\Feature;

use App\Exceptions\TaskNotificationDispatchException;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\TaskHistory;
use App\Models\User;
use App\Services\TaskEventRecorder;
use App\Services\TaskLifecycleService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class TaskNotificationDeliveryFailureTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        if (! Schema::hasTable('roles')) {
            $this->artisan('migrate:fresh')->run();
        }
    }

    protected function tearDown(): void
    {
        try {
            if (Schema::hasTable('migrations')) {
                $this->truncateTablesForAllConnections();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_root_transaction_rollback_discards_registered_notifications_and_lifecycle_writes(): void
    {
        $this->seed();
        Notification::fake();
        $manager = $this->userWithRole('manager');
        $projectManager = $this->userWithRole('project_manager');
        $assignee = $this->userWithRole('team_member');
        $project = Project::factory()->create(['project_manager_id' => $projectManager->id]);
        $project->members()->attach($assignee->id);

        try {
            DB::transaction(function () use ($assignee, $manager, $project): void {
                app(TaskLifecycleService::class)->create([
                    'project_id' => $project->id,
                    'title' => 'Root transaction rollback',
                    'assignee_id' => $assignee->id,
                    'reviewer_id' => $project->project_manager_id,
                    'priority' => 'Medium',
                ], $manager);

                Notification::assertNothingSent();

                throw new RuntimeException('Injected root transaction rollback.');
            });

            $this->fail('Expected the root transaction to roll back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected root transaction rollback.', $exception->getMessage());
        }

        Notification::assertNothingSent();
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('task_histories', 0);
        $this->assertDatabaseCount('task_events', 0);
    }

    public function test_delivery_failure_is_deterministic_and_does_not_roll_back_committed_lifecycle_state(): void
    {
        $this->seed();
        $manager = $this->userWithRole('manager');
        $projectManager = $this->userWithRole('project_manager');
        $assignee = $this->userWithRole('team_member');
        $project = Project::factory()->create(['project_manager_id' => $projectManager->id]);
        $project->members()->attach($assignee->id);
        $task = Task::query()->create([
            'project_id' => $project->id,
            'title' => 'Committed despite delivery failure',
            'assignee_id' => $assignee->id,
            'priority' => 'High',
            'status' => 'In Progress',
            'progress' => 75,
        ]);

        Notification::shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException('Injected notification transport failure.'));

        try {
            $this->approveTask($task, $manager);
            $this->fail('Expected the post-commit notification failure to be reported.');
        } catch (TaskNotificationDispatchException $exception) {
            $this->assertSame('task.approved_completed', $exception->operation);
            $this->assertSame($task->id, $exception->taskId);
            $this->assertSame('Injected notification transport failure.', $exception->getPrevious()?->getMessage());
        }

        $committed = $task->fresh();
        $this->assertSame('Completed', $committed->status);
        $this->assertSame(100, $committed->progress);
        $this->assertNotNull($committed->completed_at);
        $this->assertSame($manager->id, $committed->completed_by);
        $this->assertSame(1, TaskHistory::query()->where('task_id', $task->id)->count());
        $this->assertSame(1, TaskEvent::query()
            ->where('task_id', $task->id)
            ->where('event_type', TaskEventRecorder::COMPLETED)
            ->count());
        $this->assertSame(1, TaskEvent::query()
            ->where('task_id', $task->id)
            ->where('event_type', TaskEventRecorder::APPROVED)
            ->count());
        $this->assertDatabaseCount('task_approvals', 1);
        $this->assertDatabaseCount('completed_tasks', 0);
    }

    private function userWithRole(string $role): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('name', $role)->value('id'),
        ]);
    }
}
