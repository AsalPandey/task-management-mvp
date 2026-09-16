<?php

use App\Models\Role;
use App\Models\User;
use App\Services\AccountLifecycleService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
    || ! preg_match('/^task_management_phase28_r2a_[a-z0-9_]+$/', DB::getDatabaseName())) {
    throw new RuntimeException('Only an isolated R2A QA database is allowed.');
}
[$script, $operation, $userId, $actorId, $barrier] = $argv;
$stamp = DB::transaction(function () use ($operation, $userId, $actorId, $barrier) {
    Role::where('name', 'manager')->lockForUpdate()->firstOrFail();
    $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();
    if ($operation === 'password') {
        file_put_contents($barrier, 'locked');
        usleep(700_000);
        $user->update(['password' => Hash::make('concurrent-password')]);
    } elseif ($operation === 'deactivate') {
        app(AccountLifecycleService::class)->assertCanDeactivate($user, User::findOrFail($actorId));
        $user->update(['active' => false]);
    } else {
        throw new RuntimeException('Unsupported operation');
    }

    return $user->security_stamp;
});
echo json_encode(['stamp' => $stamp], JSON_THROW_ON_ERROR);
