<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskNotificationDelivery;
use App\Models\User;
use App\Services\TaskDeadlineNotificationDelivery;
use App\Services\TaskNotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TaskNotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Queue::fake();
    }

    public function test_repeated_deadline_delivery_has_one_logical_claim_and_database_notification(): void
    {
        [$task, $assignee] = $this->task(now()->addDay()->toDateString());
        $deliveries = app(TaskDeadlineNotificationDelivery::class);

        $first = $deliveries->deliver($task->id, TaskDeadlineNotificationDelivery::DEADLINE_REMINDER);
        $second = $deliveries->deliver($task->id, TaskDeadlineNotificationDelivery::DEADLINE_REMINDER);

        $this->assertTrue($first->delivered());
        $this->assertSame('already_delivered', $second->status);
        $this->assertDatabaseCount('task_notification_deliveries', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('task_notification_deliveries', [
            'task_id' => $task->id,
            'notification_type' => 'deadline_reminder',
            'recipient_id' => $assignee->id,
            'status' => 'delivered',
        ]);
    }

    public function test_scheduled_and_opportunistic_overdue_paths_share_one_delivery_boundary(): void
    {
        [$task] = $this->task(now()->subDay()->toDateString());
        $dispatcher = app(TaskNotificationDispatcher::class);
        $deliveries = app(TaskDeadlineNotificationDelivery::class);

        $dispatcher->taskUpdated($task, ['title' => 'Changed'], $task->creator);
        $deliveries->deliver($task->id, TaskDeadlineNotificationDelivery::OVERDUE);

        $this->assertDatabaseCount('task_notification_deliveries', 1);
        $this->assertSame(1, $task->assignee->notifications()
            ->where('data->type', 'task_overdue')
            ->count());
    }

    public function test_new_generation_and_new_recipient_are_independently_deliverable(): void
    {
        [$task, $firstAssignee] = $this->task(now()->addDay()->toDateString());
        $deliveries = app(TaskDeadlineNotificationDelivery::class);
        $deliveries->deliver($task->id, TaskDeadlineNotificationDelivery::DEADLINE_REMINDER);

        $secondAssignee = $this->member();
        $task->project->members()->attach($secondAssignee->id);
        $task->forceFill(['assignee_id' => $secondAssignee->id])->save();
        $deliveries->deliver($task->id, TaskDeadlineNotificationDelivery::DEADLINE_REMINDER);

        $this->assertDatabaseCount('task_notification_deliveries', 2);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseHas('task_notification_deliveries', ['recipient_id' => $firstAssignee->id]);
        $this->assertDatabaseHas('task_notification_deliveries', ['recipient_id' => $secondAssignee->id]);
        $this->assertSame(2, TaskNotificationDelivery::query()->distinct()->count('deadline_generation'));
    }

    public function test_invalid_owner_and_final_task_do_not_consume_delivery(): void
    {
        [$task, $assignee] = $this->task(now()->subDay()->toDateString());
        $task->project->members()->detach($assignee->id);

        $invalid = app(TaskDeadlineNotificationDelivery::class)
            ->deliver($task->id, TaskDeadlineNotificationDelivery::OVERDUE);
        $this->assertSame('suppressed', $invalid->status);
        $this->assertDatabaseCount('task_notification_deliveries', 0);

        $task->forceFill(['status' => 'cancelled'])->save();
        $final = app(TaskDeadlineNotificationDelivery::class)
            ->deliver($task->id, TaskDeadlineNotificationDelivery::OVERDUE);
        $this->assertContains($final->status, ['suppressed', 'stale']);
        $this->assertDatabaseCount('task_notification_deliveries', 0);
    }

    private function task(string $deadline): array
    {
        $manager = User::factory()->create([
            'role_id' => Role::query()->where('name', 'manager')->value('id'),
        ]);
        $assignee = $this->member();
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $project->members()->attach($assignee->id);
        $task = Task::query()->create([
            'project_id' => $project->id,
            'title' => 'Durable delivery task',
            'assignee_id' => $assignee->id,
            'created_by' => $manager->id,
            'assigned_by' => $manager->id,
            'priority' => 'Medium',
            'status' => 'not_started',
            'due_date' => $deadline,
        ]);
        $task->forceFill(['execution_due_date' => $deadline])->save();

        return [$task->fresh(['creator', 'project']), $assignee];
    }

    private function member(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('name', 'team_member')->value('id'),
        ]);
    }
}
