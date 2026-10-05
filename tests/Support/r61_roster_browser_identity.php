<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::getDatabaseName() !== 'task_management_r6_company') {
    throw new RuntimeException('Private 1000-person fixture required.');
}
echo json_encode(['pm_email' => User::where('name', 'R6 Project Manager')->value('email')], JSON_THROW_ON_ERROR);
