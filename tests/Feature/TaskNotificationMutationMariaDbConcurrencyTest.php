<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskNotificationDelivery;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Services\TaskDeadlineNotificationDelivery;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TaskNotificationMutationMariaDbConcurrencyTest extends TestCase
{
    public static function mutationRaces(): array
    {
        return [
            'deadline change' => ['deadline'],
            'assignee reassignment' => ['assignee'],
            'cancellation' => ['cancel'],
        ];
    }

    public static function overduePathOrders(): array
    {
        return [
            'scheduler first' => ['scheduler', 'opportunistic'],
            'opportunistic first' => ['opportunistic', 'scheduler'],
        ];
    }

    #[DataProvider('mutationRaces')]
    public function test_mutation_wins_before_overdue_claim(string $operation): void
    {
        $this->requireDisposableMariaDb();
        $fixture = $this->executionFixture();
        $argument = $this->argument($operation, $fixture);

        try {
            [$mutation, $delivery] = $this->race(
                [$operation, $fixture['task']->id, $argument],
                ['deliver', $fixture['task']->id, TaskDeadlineNotificationDelivery::OVERDUE],
            );
            $this->assertTrue($mutation->isSuccessful(), $mutation->getErrorOutput());
            $this->assertTrue($delivery->isSuccessful(), $delivery->getErrorOutput());

            $task = $fixture['task']->fresh();
            if ($operation === 'cancel') {
                $this->assertSame('cancelled', $task->machineState()->value);
                $this->assertSame(0, TaskNotificationDelivery::query()->where('task_id', $task->id)->count());
            } else {
                $this->assertSame(1, TaskNotificationDelivery::query()->where('task_id', $task->id)->count());
                $this->assertSame($task->activeDeadlineGeneration()->fingerprint(), $task->fresh()->overdue_notification_generation);
                $result = app(TaskDeadlineNotificationDelivery::class)
                    ->deliver($task->id, TaskDeadlineNotificationDelivery::OVERDUE);
                $this->assertSame('already_delivered', $result->status);
            }
        } finally {
            $this->cleanup($fixture);
        }
    }

    #[DataProvider('mutationRaces')]
    public function test_delivery_wins_before_mutation_without_corrupting_new_generation(string $operation): void
    {
        $this->requireDisposableMariaDb();
        $fixture = $this->executionFixture();
        $argument = $this->argument($operation, $fixture);

        try {
            [$delivery, $mutation] = $this->race(
                ['deliver', $fixture['task']->id, TaskDeadlineNotificationDelivery::OVERDUE],
                [$operation, $fixture['task']->id, $argument],
            );
            $this->assertTrue($delivery->isSuccessful(), $delivery->getErrorOutput());
            $this->assertTrue($mutation->isSuccessful(), $mutation->getErrorOutput());
            $this->assertSame(1, TaskNotificationDelivery::query()->where('task_id', $fixture['task']->id)->count());

            $task = $fixture['task']->fresh();
            if ($operation !== 'cancel') {
                $this->assertFalse($task->overdueNotificationWasSentForActiveGeneration());
                app(TaskDeadlineNotificationDelivery::class)
                    ->deliver($task->id, TaskDeadlineNotificationDelivery::OVERDUE);
                $this->assertSame(2, TaskNotificationDelivery::query()->where('task_id', $task->id)->count());
            }
        } finally {
            $this->cleanup($fixture);
        }
    }

    public function test_reviewer_reassignment_wins_before_review_reminder(): void
    {
        $this->requireDisposableMariaDb();
        $fixture = $this->reviewFixture();
        try {
            $this->race(
                ['reviewer', $fixture['task']->id, $fixture['replacement']->id],
                ['deliver', $fixture['task']->id, TaskDeadlineNotificationDelivery::OVERDUE],
            );
            $delivery = TaskNotificationDelivery::query()->where('task_id', $fixture['task']->id)->sole();
            $this->assertSame($fixture['replacement']->id, $delivery->recipient_id);
            $this->assertSame($fixture['task']->fresh()->activeDeadlineGeneration()->fingerprint(), $delivery->deadline_generation);
        } finally {
            $this->cleanup($fixture);
        }
    }

    public function test_approval_wins_before_overdue_claim(): void
    {
        $this->requireDisposableMariaDb();
        $fixture = $this->reviewFixture();
        TaskSubmission::query()->create([
            'task_id' => $fixture['task']->id,
            'submitted_by' => $fixture['assignee']->id,
            'submitted_at' => now()->subHour(),
            'submission_note' => 'Concurrency fixture',
        ]);
        try {
            $this->race(
                ['approve', $fixture['task']->id, $fixture['reviewer']->id],
                ['deliver', $fixture['task']->id, TaskDeadlineNotificationDelivery::OVERDUE],
            );
            $this->assertSame('completed', $fixture['task']->fresh()->machineState()->value);
            $this->assertSame(0, TaskNotificationDelivery::query()->where('task_id', $fixture['task']->id)->count());
        } finally {
            $this->cleanup($fixture);
        }
    }

    public function test_overdue_delivery_wins_before_approval_without_corrupting_completion(): void
    {
        $this->requireDisposableMariaDb();
        $fixture = $this->reviewFixture();
        TaskSubmission::query()->create([
            'task_id' => $fixture['task']->id,
            'submitted_by' => $fixture['assignee']->id,
            'submitted_at' => now()->subHour(),
            'submission_note' => 'Concurrency fixture',
        ]);
        try {
            [$delivery, $approval] = $this->race(
                ['deliver', $fixture['task']->id, TaskDeadlineNotificationDelivery::OVERDUE],
                ['approve', $fixture['task']->id, $fixture['reviewer']->id],
            );
            $this->assertTrue($delivery->isSuccessful(), $delivery->getErrorOutput());
            $this->assertTrue($approval->isSuccessful(), $approval->getErrorOutput());
            $this->assertSame('completed', $fixture['task']->fresh()->machineState()->value);
            $this->assertSame(1, TaskNotificationDelivery::query()->where('task_id', $fixture['task']->id)->count());
        } finally {
            $this->cleanup($fixture);
        }
    }

    public function test_rolled_back_claim_is_recoverable_by_competing_worker(): void
    {
        $this->requireDisposableMariaDb();
        $fixture = $this->executionFixture();
        try {
            [$failed, $retry] = $this->race(
                ['rollback-deliver', $fixture['task']->id, TaskDeadlineNotificationDelivery::OVERDUE],
                ['deliver', $fixture['task']->id, TaskDeadlineNotificationDelivery::OVERDUE],
            );
            $this->assertFalse($failed->isSuccessful());
            $this->assertStringContainsString('Intentional pre-commit worker failure', $failed->getErrorOutput());
            $this->assertTrue($retry->isSuccessful(), $retry->getErrorOutput());
            $this->assertSame(1, TaskNotificationDelivery::query()->where('task_id', $fixture['task']->id)->count());
            $this->assertSame(1, DB::table('notifications')->where('data->task_id', $fixture['task']->id)->count());
        } finally {
            $this->cleanup($fixture);
        }
    }

    #[DataProvider('overduePathOrders')]
    public function test_scheduler_and_opportunistic_paths_compete_for_one_delivery(string $firstPath, string $secondPath): void
    {
        $this->requireDisposableMariaDb();
        $fixture = $this->executionFixture();
        try {
            [$first, $second] = $this->race(
                [$firstPath, $fixture['task']->id, 'unused'],
                [$secondPath, $fixture['task']->id, 'unused'],
            );
            $this->assertTrue($first->isSuccessful(), $first->getErrorOutput());
            $this->assertTrue($second->isSuccessful(), $second->getErrorOutput());
            $this->assertSame(1, TaskNotificationDelivery::query()->where('task_id', $fixture['task']->id)->count());
            $this->assertSame(1, DB::table('notifications')->where('data->task_id', $fixture['task']->id)->count());
        } finally {
            $this->cleanup($fixture);
        }
    }

    private function race(array $firstArgs, array $secondArgs): array
    {
        $token = bin2hex(random_bytes(8));
        $firstReady = sys_get_temp_dir()."/r2b5q-first-{$token}";
        $firstRelease = sys_get_temp_dir()."/r2b5q-first-release-{$token}";
        $secondReady = sys_get_temp_dir()."/r2b5q-second-{$token}";
        $secondRelease = sys_get_temp_dir()."/r2b5q-second-release-{$token}";
        file_put_contents($secondRelease, 'go');
        $first = $this->worker($firstArgs, $firstReady, $firstRelease);
        $first->start();
        $this->waitReady($first, $firstReady);
        $second = $this->worker($secondArgs, $secondReady, $secondRelease);
        $second->start();
        usleep(100_000);
        $this->assertTrue($second->isRunning(), 'Second worker did not overlap the held task lock.');
        file_put_contents($firstRelease, 'go');
        $first->wait();
        $second->wait();
        foreach ([$firstReady, $firstRelease, $secondReady, $secondRelease] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        return [$first, $second];
    }

    private function worker(array $args, string $ready, string $release): Process
    {
        return new Process([
            PHP_BINARY,
            base_path('tests/Support/task_notification_race_worker.php'),
            ...array_map('strval', $args),
            $ready,
            $release,
        ], base_path(), timeout: 30);
    }

    private function waitReady(Process $worker, string $file): void
    {
        $deadline = microtime(true) + 15;
        while (! file_exists($file) && microtime(true) < $deadline && $worker->isRunning()) {
            usleep(20_000);
        }
        $this->assertFileExists($file, $worker->getErrorOutput().$worker->getOutput());
    }

    private function executionFixture(): array
    {
        $manager = $this->user('manager');
        $assignee = $this->user('team_member');
        $replacement = $this->user('team_member');
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $project->members()->attach([$assignee->id, $replacement->id]);
        $deadline = today(config('app.timezone'))->subDay()->toDateString();
        $task = $this->task($project, $manager, $assignee, $deadline, 'not_started');

        return compact('task', 'project', 'manager', 'assignee', 'replacement');
    }

    private function reviewFixture(): array
    {
        $manager = $this->user('manager');
        $reviewer = $this->user('manager');
        $replacement = $this->user('manager');
        $assignee = $this->user('team_member');
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $project->members()->attach($assignee->id);
        $deadline = today(config('app.timezone'))->subDay()->toDateString();
        $task = $this->task($project, $manager, $assignee, $deadline, 'in_review');
        $task->forceFill([
            'reviewer_id' => $reviewer->id,
            'review_due_date' => $deadline,
            'submitted_at' => now()->subHours(2),
            'review_started_at' => now()->subHour(),
        ])->save();

        return compact('task', 'project', 'manager', 'assignee', 'reviewer', 'replacement');
    }

    private function task(Project $project, User $manager, User $assignee, string $deadline, string $status): Task
    {
        $task = Task::query()->create([
            'project_id' => $project->id,
            'title' => 'R2B.5Q race',
            'assignee_id' => $assignee->id,
            'created_by' => $manager->id,
            'assigned_by' => $manager->id,
            'priority' => 'Medium',
            'status' => $status,
            'due_date' => $deadline,
        ]);
        $task->forceFill(['execution_due_date' => $deadline])->save();

        return $task;
    }

    private function argument(string $operation, array $fixture): string
    {
        return match ($operation) {
            'deadline' => today(config('app.timezone'))->subDays(2)->toDateString(),
            'assignee' => (string) $fixture['replacement']->id,
            'cancel' => (string) $fixture['manager']->id,
        };
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::firstOrCreate(['name' => $role])->id]);
    }

    private function cleanup(array $fixture): void
    {
        DB::table('notifications')->where('data->task_id', $fixture['task']->id)->delete();
        TaskNotificationDelivery::query()->where('task_id', $fixture['task']->id)->delete();
        DB::table('task_approvals')->whereIn('submission_id', TaskSubmission::query()
            ->where('task_id', $fixture['task']->id)->select('id'))->delete();
        TaskSubmission::query()->where('task_id', $fixture['task']->id)->delete();
        DB::table('task_events')->where('task_id', $fixture['task']->id)->delete();
        DB::table('task_histories')->where('task_id', $fixture['task']->id)->delete();
        $fixture['task']->forceDelete();
        $fixture['project']->members()->detach();
        $fixture['project']->delete();
        foreach (collect($fixture)->filter(fn ($value) => $value instanceof User)->unique('id') as $user) {
            $user->forceDelete();
        }
    }

    private function requireDisposableMariaDb(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            || preg_match('/^task_management_phase28_r2b5q_[a-z0-9_]+$/', DB::getDatabaseName()) !== 1) {
            $this->markTestSkipped('Requires an isolated R2B.5Q MariaDB database.');
        }
    }
}
