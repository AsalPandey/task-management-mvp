<?php

namespace Tests\Feature;

use App\Contracts\TaskTransitionCommand;
use App\Enums\TaskState;
use App\Exceptions\TaskTransitionConflictException;
use App\Exceptions\TaskTransitionException;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Services\TaskEventRecorder;
use App\Services\TaskTransitionExecutor;
use App\ValueObjects\TaskOperationContext;
use App\ValueObjects\TaskTransitionEffects;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class TaskTransitionExecutorTest extends TestCase
{
    use RefreshDatabase;

    public function test_executor_reloads_locks_reauthorizes_and_returns_a_structured_result(): void
    {
        [$assignee, $task] = $this->assignedTask();
        $command = new FoundationProgressCommand;

        $result = app(TaskTransitionExecutor::class)->execute(
            $task,
            $assignee,
            $command,
            TaskOperationContext::test($assignee->id, 'phase-2.2-foundation'),
        );

        $this->assertSame(42, $result->task->progress);
        $this->assertSame('foundation_progressed', $result->history->action);
        $this->assertCount(1, $result->events);
        $this->assertSame(TaskEventRecorder::UPDATED, $result->events[0]->event_type);
        $this->assertSame(['command' => FoundationProgressCommand::class], $result->metadata);
        $this->assertSame(42, $task->fresh()->progress);
    }

    public function test_executor_reauthorizes_the_locked_database_row_not_the_stale_model(): void
    {
        [$assignee, $task, $project] = $this->assignedTask();
        $otherMember = $this->userWithRole('team_member');
        $project->members()->attach($otherMember->id);
        DB::table('tasks')->where('id', $task->id)->update(['assignee_id' => $otherMember->id]);

        try {
            app(TaskTransitionExecutor::class)->execute($task, $assignee, new FoundationProgressCommand);
            $this->fail('A stale in-memory assignment must not authorize a transition.');
        } catch (AuthorizationException $exception) {
            $response = app(ExceptionHandler::class)
                ->render(Request::create('/tasks/foundation', 'POST'), $exception);
            $this->assertSame(403, $response->getStatusCode());
        }

        $this->assertSame(20, $task->fresh()->progress);
        $this->assertDatabaseCount('task_histories', 0);
        $this->assertDatabaseCount('task_events', 0);
    }

    public function test_failed_transition_callback_rolls_back_every_database_write_and_after_commit_effect(): void
    {
        [$assignee, $task] = $this->assignedTask();
        $afterCommitCalls = 0;
        $command = new FailingFoundationCommand($afterCommitCalls);

        try {
            app(TaskTransitionExecutor::class)->execute($task, $assignee, $command);
            $this->fail('The failing transition command should throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Foundation callback failure.', $exception->getMessage());
        }

        $this->assertSame(20, $task->fresh()->progress);
        $this->assertDatabaseCount('task_submissions', 0);
        $this->assertDatabaseCount('task_histories', 0);
        $this->assertDatabaseCount('task_events', 0);
        $this->assertSame(0, $afterCommitCalls);
    }

    public function test_invalid_state_and_conflict_exceptions_have_deterministic_http_semantics(): void
    {
        [$assignee, $task] = $this->assignedTask();
        $task->forceFill(['status' => TaskState::OnHold])->save();

        try {
            app(TaskTransitionExecutor::class)->execute($task, $assignee, new FoundationProgressCommand);
            $this->fail('An invalid current state should fail.');
        } catch (TaskTransitionException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertSame('invalid_state', $exception->reason);
        }

        $conflict = new TaskTransitionConflictException;
        $this->assertSame(409, $conflict->getStatusCode());
        $this->assertSame(20, $task->fresh()->progress);
    }

    private function assignedTask(): array
    {
        $assignee = $this->userWithRole('team_member');
        $projectManager = $this->userWithRole('project_manager');
        $project = Project::factory()->create(['project_manager_id' => $projectManager->id]);
        $project->members()->attach($assignee->id);
        $task = Task::query()->create([
            'project_id' => $project->id,
            'assignee_id' => $assignee->id,
            'title' => 'Transition executor task',
            'priority' => 'Medium',
            'status' => TaskState::InProgress,
            'progress' => 20,
        ]);

        return [$assignee, $task, $project];
    }

    private function userWithRole(string $name): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => $name],
            ['label' => str($name)->replace('_', ' ')->title()->toString()],
        );

        return User::factory()->create(['role_id' => $role->id]);
    }
}

class FoundationProgressCommand implements TaskTransitionCommand
{
    public function ability(): string
    {
        return 'updateProgress';
    }

    public function validate(Task $task, User $actor): void
    {
        if ($task->machineState() !== TaskState::InProgress) {
            throw TaskTransitionException::invalidState('Progress may be changed only while work is in progress.');
        }
    }

    public function apply(Task $task, User $actor, TaskOperationContext $context): TaskTransitionEffects
    {
        $before = (int) $task->progress;
        $task->forceFill(['progress' => 42])->save();

        return new TaskTransitionEffects(
            historyAction: 'foundation_progressed',
            historyChanges: ['progress' => ['before' => $before, 'after' => 42]],
            events: [[
                'type' => TaskEventRecorder::UPDATED,
                'changed_fields' => ['progress' => ['before' => $before, 'after' => 42]],
            ]],
            metadata: ['command' => self::class],
        );
    }
}

class FailingFoundationCommand implements TaskTransitionCommand
{
    public function __construct(private int &$afterCommitCalls) {}

    public function ability(): string
    {
        return 'updateProgress';
    }

    public function validate(Task $task, User $actor): void {}

    public function apply(Task $task, User $actor, TaskOperationContext $context): TaskTransitionEffects
    {
        $task->forceFill(['progress' => 70])->save();
        TaskSubmission::query()->create([
            'task_id' => $task->id,
            'submitted_by' => $actor->id,
            'submitted_at' => now(),
        ]);

        $task->getConnection()->afterCommit(
            function (): void {
                $this->afterCommitCalls++;
            },
        );

        throw new RuntimeException('Foundation callback failure.');
    }
}
