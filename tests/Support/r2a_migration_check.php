<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$safe = DB::getDriverName() === 'sqlite' && DB::getDatabaseName() === ':memory:';
$safe = $safe || (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
    && preg_match('/^task_management_phase28_r2a_[a-z0-9_]+_migration$/', DB::getDatabaseName()));
if (! $safe || ! app()->environment('testing')) {
    throw new RuntimeException('Disposable R2A migration database required.');
}
$run = function (string $command, array $args = []): void {
    if (Artisan::call($command, $args + ['--force' => true]) !== 0) {
        throw new RuntimeException(Artisan::output());
    }
};
$run('migrate:fresh');
if (! Schema::hasColumn('users', 'security_stamp')) {
    throw new RuntimeException('Fresh migration missing stamp');
}
$run('migrate:rollback', ['--step' => 1]);
$id = DB::table('users')->insertGetId(['name' => 'Upgrade fixture', 'email' => 'upgrade@example.test', 'password' => password_hash('fixture-password', PASSWORD_BCRYPT), 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
$before = (array) DB::table('users')->find($id);
$run('migrate');
$after = (array) DB::table('users')->find($id);
unset($after['security_stamp']);
if ($before !== $after) {
    throw new RuntimeException('Upgrade altered pre-existing user data');
}
$run('migrate:rollback', ['--step' => 1]);
if (Schema::hasColumn('users', 'security_stamp') || (array) DB::table('users')->find($id) !== $before) {
    throw new RuntimeException('Rollback did not preserve user data');
}
$run('migrate');
if (! Schema::hasColumn('users', 'security_stamp') || DB::table('users')->count() !== 1) {
    throw new RuntimeException('Reapplication failed');
}
echo DB::getDriverName().": fresh, additive upgrade, rollback and reapplication PASS\n";
