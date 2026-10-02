<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ProjectMemberAdded;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProjectMembershipMariaDbConcurrencyTest extends TestCase
{
    public function test_simultaneous_membership_add_has_one_pivot_history_and_database_notification(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! str_starts_with(DB::getDatabaseName(), 'task_management_r42_membership_')) {
            $this->markTestSkipped('Requires isolated R4.2 membership MariaDB database.');
        }
        $this->seed();
        $manager = User::factory()->create(['role_id' => Role::where('name', 'manager')->value('id')]);
        $member = User::factory()->create(['role_id' => Role::where('name', 'team_member')->value('id')]);
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $barrier = tempnam(sys_get_temp_dir(), 'r42-membership-');
        unlink($barrier);
        try {
            $workers = array_map(fn () => new Process([PHP_BINARY, base_path('tests/Support/r42_membership_worker.php'), (string) $project->id, (string) $member->id, (string) $manager->id, $barrier], base_path(), timeout: 20), range(1, 3));
            foreach ($workers as $worker) {
                $worker->start();
            }
            file_put_contents($barrier, 'go');
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
                $results[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR)['changed'];
            }
            $this->assertSame(1, count(array_filter($results)));
            $this->assertSame(1, DB::table('project_user')->where('project_id', $project->id)->where('user_id', $member->id)->count());
            $this->assertSame(1, $project->histories()->where('action', 'member_added')->count());
            $this->assertSame(1, $member->notifications()->where('type', ProjectMemberAdded::class)->count());
        } finally {
            @unlink($barrier);
            DB::table('notifications')->where('notifiable_id', $member->id)->delete();
            $project->histories()->delete();
            $project->forceDelete();
            $member->forceDelete();
            $manager->forceDelete();
        }
    }
}
