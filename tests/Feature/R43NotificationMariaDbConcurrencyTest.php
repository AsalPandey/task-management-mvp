<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class R43NotificationMariaDbConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql' || ! preg_match('/^task_management_r43_concurrency_[a-z0-9_]+$/', DB::getDatabaseName())) {
            $this->markTestSkipped('Requires isolated R43 concurrency MariaDB schema.');
        }
        $this->seed();
    }

    public function test_two_bulk_read_workers_preserve_owner_and_old_read_timestamps_and_postcommit_inserts(): void
    {
        $manager = User::factory()->create(['role_id' => Role::where('name', 'manager')->value('id')]);
        $other = User::factory()->create(['role_id' => Role::where('name', 'team_member')->value('id')]);
        $rows = [];
        for ($i = 0; $i < 100; $i++) {
            $rows[] = $this->notice($manager->id);
        }
        $old = $this->notice($manager->id);
        $old['read_at'] = '2026-09-01 08:00:00';
        $foreign = $this->notice($other->id);
        try {
            DB::table('notifications')->insert($rows);
            DB::table('notifications')->insert($old);
            DB::table('notifications')->insert($foreign);
            $this->workers('read', $manager->id);
            $this->assertSame(0, $manager->unreadNotifications()->count());
            $this->assertSame(1, $other->unreadNotifications()->count());
            $this->assertSame('2026-09-01 08:00:00', DB::table('notifications')->where('id', $old['id'])->value('read_at'));
            $this->assertSame(1, DB::table('notifications')->whereIn('id', array_column($rows, 'id'))->distinct()->count('read_at'));
            DB::table('notifications')->insert($this->notice($manager->id));
            $this->assertSame(1, $manager->unreadNotifications()->count());
        } finally {
            DB::table('notifications')->whereIn('notifiable_id', [$manager->id, $other->id])->delete();
            $manager->forceDelete();
            $other->forceDelete();
        }
    }

    public function test_simultaneous_candidate_command_scans_deliver_one_notice_for_one_generation(): void
    {
        $manager = User::factory()->create(['role_id' => Role::where('name', 'manager')->value('id')]);
        $member = User::factory()->create(['role_id' => Role::where('name', 'team_member')->value('id')]);
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $project->members()->attach($member);
        $due = today(config('app.timezone'))->addDay()->toDateString();
        $task = Task::query()->create(['title' => 'Concurrent scalar candidate', 'project_id' => $project->id, 'assignee_id' => $member->id, 'status' => 'not_started', 'priority' => 'Medium', 'due_date' => $due]);
        $task->forceFill(['execution_due_date' => $due])->save();
        try {
            $this->workers('reminders', $member->id);
            $this->assertSame(1, DB::table('task_notification_deliveries')->where('task_id', $task->id)->count());
            $this->assertSame(1, DB::table('notifications')->where('data->task_id', $task->id)->count());
            $this->assertTrue($task->fresh()->deadlineReminderWasSentForActiveGeneration());
            $this->workers('reminders', $member->id);
            $this->assertSame(1, DB::table('notifications')->where('data->task_id', $task->id)->count());
        } finally {
            DB::table('notifications')->whereIn('notifiable_id', [$manager->id, $member->id])->delete();
            DB::table('task_notification_deliveries')->where('task_id', $task->id)->delete();
            $task->forceDelete();
            $project->members()->detach();
            $project->forceDelete();
            $manager->forceDelete();
            $member->forceDelete();
        }
    }

    private function workers(string $mode, int $user): void
    {
        $token = bin2hex(random_bytes(8));
        $release = sys_get_temp_dir().'/r43-release-'.$token;
        $ready = [sys_get_temp_dir().'/r43-ready-a-'.$token, sys_get_temp_dir().'/r43-ready-b-'.$token];
        $workers = [];
        try {
            foreach ($ready as $file) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/r43_notification_worker.php'), $mode, (string) $user, $file, $release], base_path(), timeout: 30);
                $worker->start();
                $workers[] = $worker;
            }
            $until = microtime(true) + 10;
            while ((! file_exists($ready[0]) || ! file_exists($ready[1])) && microtime(true) < $until) {
                usleep(10000);
            }
            foreach ($ready as $file) {
                $this->assertFileExists($file);
            }
            file_put_contents($release, 'go');
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
            }
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
        }
    }

    private function notice(int $owner): array
    {
        return ['id' => (string) Str::uuid(), 'type' => 'R43ConcurrentNotice', 'notifiable_type' => User::class, 'notifiable_id' => $owner, 'data' => '{"message":"Concurrent notice"}', 'created_at' => now(), 'updated_at' => now()];
    }
}
