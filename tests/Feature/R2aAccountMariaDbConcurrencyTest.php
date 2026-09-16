<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\AccountSessionSecurity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class R2aAccountMariaDbConcurrencyTest extends TestCase
{
    public function test_concurrent_password_change_and_deactivation_cannot_restore_old_session_fingerprint(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            || ! preg_match('/^task_management_phase28_r2a_[a-z0-9_]+$/', DB::getDatabaseName())) {
            $this->markTestSkipped('Requires an isolated R2A MariaDB database.');
        }
        $manager = User::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'manager'])->id]);
        $user = User::factory()->create(['role_id' => Role::firstOrCreate(['name' => 'team_member'])->id]);
        $before = app(AccountSessionSecurity::class)->fingerprint($user);
        $barrier = tempnam(sys_get_temp_dir(), 'r2a-lock-');
        unlink($barrier);
        $workers = [];
        try {
            $workers[] = $first = new Process([PHP_BINARY, base_path('tests/Support/r2a_account_security_worker.php'), 'password', (string) $user->id, (string) $manager->id, $barrier], base_path(), timeout: 15);
            $first->start();
            $deadline = microtime(true) + 5;
            while (! file_exists($barrier) && microtime(true) < $deadline && $first->isRunning()) {
                usleep(20_000);
            }
            $this->assertFileExists($barrier, $first->getErrorOutput());
            $workers[] = $second = new Process([PHP_BINARY, base_path('tests/Support/r2a_account_security_worker.php'), 'deactivate', (string) $user->id, (string) $manager->id, $barrier], base_path(), timeout: 15);
            $second->start();
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
            }
            $firstStamp = json_decode($first->getOutput(), true, flags: JSON_THROW_ON_ERROR)['stamp'];
            $secondStamp = json_decode($second->getOutput(), true, flags: JSON_THROW_ON_ERROR)['stamp'];
            $this->assertNotSame($firstStamp, $secondStamp);
            $user->refresh();
            $this->assertSame($secondStamp, $user->security_stamp);
            $this->assertFalse($user->active);
            $this->assertTrue(Hash::check('concurrent-password', $user->password));
            $this->assertNotSame($before, app(AccountSessionSecurity::class)->fingerprint($user));
            $user->update(['active' => true]);
            $this->assertNotSame($before, app(AccountSessionSecurity::class)->fingerprint($user));
            $this->assertNotSame($secondStamp, $user->security_stamp);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            if (file_exists($barrier)) {
                unlink($barrier);
            }
            $user->forceDelete();
            $manager->forceDelete();
        }
    }
}
