<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskNotificationDelivery;
use App\Models\User;
use App\Services\TaskDeadlineNotificationDelivery;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TaskNotificationDeliveryMariaDbConcurrencyTest extends TestCase
{
    public static function deliveryTypes(): array
    {
        return [
            'deadline reminder' => [TaskDeadlineNotificationDelivery::DEADLINE_REMINDER, 1],
            'overdue' => [TaskDeadlineNotificationDelivery::OVERDUE, -1],
        ];
    }

    #[DataProvider('deliveryTypes')]
    public function test_two_workers_create_one_logical_delivery(string $type, int $deadlineOffset): void
    {
        $this->requireDisposableMariaDb();
        [$task, $project, $users] = $this->fixture($deadlineOffset);
        $token = bin2hex(random_bytes(8));
        $ready = [sys_get_temp_dir()."/r2b5-ready-a-{$token}", sys_get_temp_dir()."/r2b5-ready-b-{$token}"];
        $release = sys_get_temp_dir()."/r2b5-release-{$token}";
        $workers = [];

        try {
            foreach ($ready as $file) {
                $worker = new Process([
                    PHP_BINARY,
                    base_path('tests/Support/task_notification_delivery_worker.php'),
                    (string) $task->id,
                    $type,
                    $file,
                    $release,
                ], base_path(), timeout: 20);
                $worker->start();
                $workers[] = $worker;
            }

            $deadline = microtime(true) + 8;
            while ((! file_exists($ready[0]) || ! file_exists($ready[1])) && microtime(true) < $deadline) {
                usleep(20_000);
            }
            $this->assertFileExists($ready[0]);
            $this->assertFileExists($ready[1]);
            file_put_contents($release, 'go');

            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
            }

            $statuses = array_map(
                fn (Process $worker): string => json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status'],
                $workers,
            );
            sort($statuses);
            $this->assertSame(['already_delivered', 'delivered'], $statuses);
            $this->assertSame(1, TaskNotificationDelivery::query()->where('task_id', $task->id)->count());
            $this->assertSame(1, DB::table('notifications')->where('data->task_id', $task->id)->count());
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            foreach ([...$ready, $release] as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
            DB::table('notifications')->where('data->task_id', $task->id)->delete();
            TaskNotificationDelivery::query()->where('task_id', $task->id)->delete();
            $task->forceDelete();
            $project->members()->detach();
            $project->delete();
            foreach ($users as $user) {
                $user->forceDelete();
            }
        }
    }

    private function fixture(int $deadlineOffset): array
    {
        $manager = User::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'manager'])->id]);
        $assignee = User::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'team_member'])->id]);
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $project->members()->attach($assignee->id);
        $deadline = today(config('app.timezone'))->addDays($deadlineOffset)->toDateString();
        $task = Task::query()->create([
            'project_id' => $project->id,
            'title' => 'Concurrent delivery',
            'assignee_id' => $assignee->id,
            'created_by' => $manager->id,
            'assigned_by' => $manager->id,
            'priority' => 'Medium',
            'status' => 'not_started',
            'due_date' => $deadline,
        ]);
        $task->forceFill(['execution_due_date' => $deadline])->save();

        return [$task, $project, [$manager, $assignee]];
    }

    private function requireDisposableMariaDb(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            || preg_match('/^task_management_phase28_r2b5_[a-z0-9_]+$/', DB::getDatabaseName()) !== 1) {
            $this->markTestSkipped('Requires an isolated R2B.5 MariaDB database.');
        }
    }
}
