<?php

namespace Tests\Support;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

trait R61ProcessHarness
{
    private array $workers = [];

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $schemaPrefix = 'task_management_r6_r61_'.(str_contains(static::class, 'Responsibility') ? 'responsibility_' : 'account_');
        if (DB::getDriverName() !== 'mysql' || ! str_starts_with(DB::getDatabaseName(), $schemaPrefix)) {
            $this->markTestSkipped('Requires private R61 account MariaDB schema.');
        }
        $this->seed();
        $this->directory = sys_get_temp_dir().'/r61-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
        if (isset($this->directory)) {
            foreach (glob($this->directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    protected function user(string $role): User
    {
        return User::factory()->create(['role_id' => Role::where('name', $role)->value('id')]);
    }

    protected function start(array $spec): array
    {
        $prefix = $this->directory.'/'.count($this->workers);
        $spec += ['ready' => $prefix.'-ready', 'release' => $prefix.'-release', 'result' => $prefix.'-result'];
        file_put_contents($prefix.'-spec', json_encode($spec));
        $process = new Process([PHP_BINARY, base_path('tests/Support/r61_http_worker.php'), $prefix.'-spec'], base_path(), timeout: 40);
        $process->start();
        $this->workers[] = $process;

        return [$process, $spec];
    }

    protected function ready(array $job): void
    {
        $until = microtime(true) + 15;
        while (! file_exists($job[1]['ready']) && $job[0]->isRunning() && microtime(true) < $until) {
            usleep(10000);
        }
        $this->assertFileExists($job[1]['ready'], $job[0]->getOutput().$job[0]->getErrorOutput());
    }

    protected function finish(array $job): array
    {
        $job[0]->wait();
        $this->assertTrue($job[0]->isSuccessful(), $job[0]->getOutput().$job[0]->getErrorOutput());

        return json_decode(file_get_contents($job[1]['result']), true, flags: JSON_THROW_ON_ERROR);
    }
}
