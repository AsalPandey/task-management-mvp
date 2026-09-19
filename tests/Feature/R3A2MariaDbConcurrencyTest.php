<?php

namespace Tests\Feature;

use App\Enums\TaskState;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\TaskSubmission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class R3A2MariaDbConcurrencyTest extends TestCase
{
    public function test_racing_duplicate_creation_commits_one_task_and_one_created_event(): void
    {
        $this->requireDisposableMariaDb();
        [$manager, $reviewer, $assignee, $project] = $this->fixtures();
        $startFile = $this->barrier('r3a2-create-start-');
        $payload = [
            'actor_id' => $manager->id,
            'correlation_id' => 'r3a2-racing-create',
            'task' => [
                'title' => 'R3A2 racing duplicate', 'project_id' => $project->id,
                'assignee_id' => $assignee->id, 'reviewer_id' => $reviewer->id, 'priority' => 'High',
            ],
        ];

        try {
            $first = $this->worker('create', $payload, startFile: $startFile);
            $second = $this->worker('create', $payload, startFile: $startFile);
            $first->start();
            $second->start();
            file_put_contents($startFile, 'go');
            $first->wait();
            $second->wait();
            $results = [$this->decode($first), $this->decode($second)];

            $this->assertEqualsCanonicalizing(['created', 'duplicate'], array_column(array_map(
                fn ($result) => ['outcome' => $result['details']['outcome'] ?? $result['result']],
                $results,
            ), 'outcome'));
            $this->assertSame(1, Task::query()->where('title', 'R3A2 racing duplicate')->count());
            $this->assertSame(1, TaskEvent::query()->where('correlation_id', 'r3a2-racing-create')->count());
        } finally {
            $this->cleanupProject($project, [$manager, $reviewer, $assignee]);
            @unlink($startFile);
        }
    }

    public function test_role_demotion_and_review_transition_cannot_strand_the_active_reviewer(): void
    {
        $this->requireDisposableMariaDb();
        [$manager, $reviewer, $assignee, $project] = $this->fixtures();
        $task = $this->submittedTask($project, $assignee, $reviewer);
        $readyFile = $this->barrier('r3a2-demote-ready-');

        try {
            $demote = $this->worker('demote', [
                'actor_id' => $manager->id, 'target_id' => $reviewer->id, 'correlation_id' => 'demote',
            ], 800, $readyFile);
            $demote->start();
            $this->waitForBarrier($demote, $readyFile);
            $review = $this->worker('review', [
                'actor_id' => $reviewer->id, 'task_id' => $task->id, 'correlation_id' => 'review',
            ]);
            $review->run();
            $demote->wait();

            $this->assertSame('success', $this->decode($review)['result']);
            $this->assertSame('conflict', $this->decode($demote)['result']);
            $this->assertTrue($reviewer->fresh()->hasRole('manager'));
            $this->assertSame(TaskState::InReview, $task->fresh()->machineState());
        } finally {
            $this->cleanupProject($project, [$manager, $reviewer, $assignee]);
            @unlink($readyFile);
        }
    }

    public function test_reopen_reconciliation_and_reviewer_deactivation_serialize_safely(): void
    {
        $this->requireDisposableMariaDb();
        [$manager, $oldReviewer, $assignee, $project] = $this->fixtures();
        $newReviewer = $this->user('manager');
        $task = $this->submittedTask($project, $assignee, $oldReviewer);
        $completed = $this->approveTask($task, $oldReviewer);
        $oldReviewer->forceFill(['active' => false])->save();
        $readyFile = $this->barrier('r3a2-deactivate-ready-');

        try {
            $deactivate = $this->worker('deactivate', [
                'actor_id' => $manager->id, 'target_id' => $newReviewer->id, 'correlation_id' => 'deactivate',
            ], 800, $readyFile);
            $deactivate->start();
            $this->waitForBarrier($deactivate, $readyFile);
            $reopen = $this->worker('reopen', [
                'actor_id' => $manager->id, 'task_id' => $completed->id,
                'reviewer_id' => $newReviewer->id, 'correlation_id' => 'reopen',
            ]);
            $reopen->run();
            $deactivate->wait();

            $this->assertSame('success', $this->decode($deactivate)['result']);
            $this->assertSame('conflict', $this->decode($reopen)['result']);
            $this->assertSame(TaskState::Completed, $task->fresh()->machineState());
            $this->assertFalse($newReviewer->fresh()->isActive());
        } finally {
            $this->cleanupProject($project, [$manager, $oldReviewer, $newReviewer, $assignee]);
            @unlink($readyFile);
        }
    }

    private function requireDisposableMariaDb(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            || preg_match('/^task_management_phase28_r3a2_[a-z0-9_]+$/', DB::getDatabaseName()) !== 1) {
            $this->markTestSkipped('Requires an isolated R3A.2 MariaDB database.');
        }
    }

    private function fixtures(): array
    {
        $this->seed();
        $manager = $this->user('manager');
        $reviewer = $this->user('manager');
        $assignee = $this->user('team_member');
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $project->members()->attach($assignee->id);

        return [$manager, $reviewer, $assignee, $project];
    }

    private function submittedTask(Project $project, User $assignee, User $reviewer): Task
    {
        $task = Task::query()->create([
            'title' => 'R3A2 concurrency task', 'project_id' => $project->id,
            'assignee_id' => $assignee->id, 'status' => TaskState::Submitted, 'priority' => 'High',
            'submitted_at' => now()->subMinute(),
        ]);
        $task->forceFill(['reviewer_id' => $reviewer->id])->save();
        TaskSubmission::query()->create([
            'task_id' => $task->id, 'submitted_by' => $assignee->id,
            'submitted_at' => $task->submitted_at, 'submission_note' => 'Concurrency fixture.',
        ]);

        return $task;
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('name', $role)->value('id')]);
    }

    private function worker(string $operation, array $payload, int $hold = 0, ?string $readyFile = null, ?string $startFile = null): Process
    {
        return new Process([
            PHP_BINARY, base_path('tests/Support/r3a2_concurrency_worker.php'), $operation,
            json_encode($payload, JSON_THROW_ON_ERROR), (string) $hold, $readyFile ?? '', $startFile ?? '',
        ], base_path(), timeout: 20);
    }

    private function barrier(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        $this->assertNotFalse($path);
        unlink($path);

        return $path;
    }

    private function waitForBarrier(Process $worker, string $path): void
    {
        $deadline = microtime(true) + 5;
        while (! file_exists($path) && microtime(true) < $deadline) {
            if (! $worker->isRunning()) {
                $this->fail($worker->getErrorOutput().$worker->getOutput());
            }
            usleep(20_000);
        }
        $this->assertFileExists($path);
    }

    private function decode(Process $process): array
    {
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());

        return json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
    }

    private function cleanupProject(Project $project, array $users): void
    {
        $taskIds = Task::withTrashed()->where('project_id', $project->id)->pluck('id');
        DB::table('task_notification_deliveries')->whereIn('task_id', $taskIds)->delete();
        DB::table('task_events')->whereIn('task_id', $taskIds)->delete();
        DB::table('task_approvals')->whereIn('task_id', $taskIds)->delete();
        DB::table('task_submissions')->whereIn('task_id', $taskIds)->delete();
        DB::table('task_histories')->whereIn('task_id', $taskIds)->delete();
        DB::table('task_revision_cycles')->whereIn('task_id', $taskIds)->delete();
        Task::withTrashed()->whereIn('id', $taskIds)->forceDelete();
        $project->members()->detach();
        $project->forceDelete();
        User::withTrashed()->whereIn('id', collect($users)->pluck('id'))->forceDelete();
    }
}
