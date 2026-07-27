<?php

namespace Tests\Feature;

use App\Enums\TaskState;
use App\Models\Task;
use App\Models\TaskRevisionCycle;
use App\Models\User;
use App\Notifications\TaskOverdueNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TaskOverdueNotificationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_eligible_active_tasks_are_notified_once(): void
    {
        Notification::fake();
        $assignee = User::factory()->create();

        $active = $this->task($assignee, TaskState::InProgress, [
            'execution_due_date' => now()->subDay()->toDateString(),
        ]);
        $cancelled = $this->task($assignee, TaskState::Cancelled, [
            'execution_due_date' => now()->subDay()->toDateString(),
            'cancelled_at' => now(),
        ]);
        $completed = $this->task($assignee, TaskState::Completed, [
            'execution_due_date' => now()->subDay()->toDateString(),
            'completed_at' => now(),
        ]);
        $deleted = $this->task($assignee, TaskState::InProgress, [
            'execution_due_date' => now()->subDay()->toDateString(),
        ]);
        $deleted->delete();

        $this->assertTrue($active->fresh()->activeDeadline()?->isPast());
        $this->artisan('app:send-overdue-task-notifications')->assertSuccessful();
        $this->artisan('app:send-overdue-task-notifications')->assertSuccessful();

        Notification::assertSentToTimes($assignee, TaskOverdueNotification::class, 1);
        $this->assertNotNull($active->fresh()->overdue_notification_sent_at);
        $this->assertNull($cancelled->fresh()->overdue_notification_sent_at);
        $this->assertNull($completed->fresh()->overdue_notification_sent_at);
        $this->assertNull($deleted->fresh()->overdue_notification_sent_at);
    }

    public function test_reopened_task_uses_its_revision_deadline(): void
    {
        Notification::fake();
        $assignee = User::factory()->create();
        $task = $this->task($assignee, TaskState::RevisionRequested, [
            'due_date' => now()->addMonth()->toDateString(),
            'execution_due_date' => now()->addMonth()->toDateString(),
            'revision_due_date' => now()->subDay()->toDateString(),
        ]);
        $cycle = TaskRevisionCycle::query()->create([
            'task_id' => $task->id,
            'cycle_number' => 1,
            'requested_at' => now()->subDays(2),
            'revision_due_date' => now()->subDay()->toDateString(),
            'origin' => 'completed_reopen',
        ]);
        $task->forceFill(['active_revision_cycle_id' => $cycle->id])->save();

        $this->assertTrue($task->fresh()->activeDeadline()?->isPast());
        $this->artisan('app:send-overdue-task-notifications')->assertSuccessful();

        Notification::assertSentTo(
            $assignee,
            TaskOverdueNotification::class,
            fn (TaskOverdueNotification $notification): bool => $notification->toArray($assignee)['due_date']
                === now()->subDay()->format('M d, Y'),
        );
        $this->assertNotNull($task->fresh()->overdue_notification_sent_at);
    }

    private function task(User $assignee, TaskState $state, array $attributes = []): Task
    {
        $task = Task::query()->create([
            'title' => "Overdue {$state->value}",
            'assignee_id' => $assignee->id,
            'status' => $state,
        ]);
        $task->forceFill($attributes)->save();

        return $task;
    }
}
