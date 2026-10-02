<?php

use App\Enums\TaskState;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskAnalyticsService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$timezone = config('app.timezone');
if (! str_starts_with(DB::getDatabaseName(), 'task_management_r42_timezone_')
    || ! app()->environment('production') || config('app.debug') || ! app()->configurationIsCached()
    || ! app()->routesAreCached() || date_default_timezone_get() !== $timezone
    || ! in_array($timezone, ['Asia/Kathmandu', 'America/New_York'], true)
    || config('queue.default') !== 'database' || config('session.driver') !== 'database'
    || config('cache.default') !== 'database') {
    throw new RuntimeException('Requires a cached production-like disposable timezone installation.');
}
$check = function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
Notification::fake();
if (Task::query()->exists()) {
    throw new RuntimeException('Requires an empty disposable task fixture.');
}
try {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-03 23:59:59', $timezone));
    Carbon::setTestNow(CarbonImmutable::getTestNow());
    $manager = User::factory()->create(['role_id' => Role::where('name', 'manager')->value('id')]);
    $member = User::factory()->create(['role_id' => Role::where('name', 'team_member')->value('id')]);
    $project = Project::factory()->create(['project_manager_id' => $manager->id]);
    $project->members()->attach($member->id);
    $makeTask = fn (string $due) => Task::query()->create(['title' => 'Cached timezone fixture', 'project_id' => $project->id, 'assignee_id' => $member->id, 'priority' => 'Medium', 'status' => TaskState::InProgress, 'due_date' => $due]);
    $todayTask = $makeTask('2026-10-03');
    $todayTask->forceFill(['execution_due_date' => '2026-10-03', 'created_at' => '2026-10-03 00:00:00'])->save();
    $check(! $todayTask->activeDeadlineGeneration()->isOverdue(), 'Due today became overdue before midnight.');
    $check($todayTask->activeDeadline()->toDateString() === '2026-10-03', 'Displayed deadline changed date.');
    $check(app(TaskAnalyticsService::class)->report($manager, '2026-10-03', '2026-10-03')['totalTasks'] === 1, 'Inclusive creation cohort boundary changed.');
    $tomorrowTask = $makeTask('2026-10-04');
    $check(Artisan::call('app:send-task-deadline-reminders') === 0, 'Reminder command failed.');
    $check($tomorrowTask->fresh()->deadlineReminderWasSentForActiveGeneration(), 'Tomorrow reminder missed deployment date.');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-04 00:00:00', $timezone));
    Carbon::setTestNow(CarbonImmutable::getTestNow());
    $check($todayTask->fresh()->activeDeadlineGeneration()->isOverdue(), 'Midnight did not make yesterday overdue.');
    $check(Artisan::call('app:send-overdue-task-notifications') === 0, 'Overdue command failed.');
    $check($todayTask->fresh()->overdueNotificationWasSentForActiveGeneration(), 'Overdue command used the wrong clock.');
    echo json_encode(['timezone' => $timezone, 'php_timezone' => date_default_timezone_get(), 'config_cached' => true, 'routes_cached' => true, 'due_today' => 'pass', 'midnight' => 'pass', 'reminder' => 'pass', 'analytics_boundary' => 'pass'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
}
