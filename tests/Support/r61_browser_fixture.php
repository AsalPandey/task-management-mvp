<?php

use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkflowNotificationIntent;
use App\Notifications\TaskReviewWorkflowNotification;
use App\Services\RequiredWorkflowNotifications;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! preg_match('/^task_management_r41_browser_(?:r61_[a-z0-9_]+|ci)$/', DB::getDatabaseName())) {
    throw new RuntimeException('Private R61 browser schema required');
}
$app->detectEnvironment(fn () => 'testing');
config(['app.env' => 'testing']);
$manager = User::where('email', 'manager@r41.example.invalid')->firstOrFail();
$r61Run = bin2hex(random_bytes(3));
function httpAction($actor, $method, $path, $data = [])
{
    auth()->guard('web')->setUser($actor->fresh()->load('role'));
    $kernel = app(Illuminate\Contracts\Http\Kernel::class);
    $request = Request::create($path, $method, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode($data));
    $response = $kernel->handle($request);
    $body = json_decode($response->getContent(), true);
    $kernel->terminate($request, $response);
    if ($response->getStatusCode() !== 200) {
        throw new RuntimeException($response->getContent());
    }

    return $body;
}
function makeAccount($manager, $name, $role)
{
    $name .= ' '.$GLOBALS['r61Run'];

    return User::findOrFail(httpAction($manager, 'POST', '/team-management', ['name' => $name, 'email' => strtolower(str_replace(' ', '-', $name)).'@r61.example.invalid', 'password' => 'R41-browser-unique-secret-123!', 'role_id' => Role::where('name', $role)->value('id')])['id']);
}
$employee = makeAccount($manager, 'R61 Promotion Employee', 'team_member');
$former = makeAccount($manager, 'R61 Historical Employee', 'team_member');
$pm = makeAccount($manager, 'R61 Outgoing PM', 'project_manager');
$next = makeAccount($manager, 'R61 Next PM', 'project_manager');
$admin = makeAccount($manager, 'R61 Secondary Manager', 'manager');
$project = Project::findOrFail(httpAction($manager, 'POST', '/projects', ['name' => 'R61 Boundary Project '.$r61Run, 'status' => 'active', 'project_manager_id' => $pm->id])['project']['id']);
foreach ([$employee, $former] as $person) {
    httpAction($manager, 'POST', '/projects/'.$project->id.'/add-member', ['user_id' => $person->id]);
}
function createWork($manager, $person, $project, $title)
{
    $r = httpAction($manager, 'POST', '/tasks', ['title' => $title, 'description' => 'R61 browser workflow fixture', 'start_date' => today()->toDateString(), 'project_id' => $project->id, 'assignee_id' => $person->id, 'reviewer_id' => $manager->id, 'priority' => 'High', 'due_date' => today()->addDays(4)->toDateString()]);

    return Task::findOrFail($r['task']['id']);
}
function action($actor, $task, $suffix, $data = [])
{
    return httpAction($actor, 'POST', '/tasks/'.$task->id.'/'.$suffix, ['expected_version' => $task->fresh()->lock_version] + $data);
}
$promotion = createWork($manager, $employee, $project, 'R61 Promotion Work');
$loading = createWork($manager, $employee, $project, 'R61 Loading Draft');
action($employee, $promotion, 'start');
$handover = createWork($manager, $pm, $project, 'R61 PM Assignment');
action($pm, $handover, 'start');
$history = createWork($manager, $former, $project, 'R61 Historical Approved Work');
action($former, $history, 'start');
action($former, $history, 'submit');
action($manager, $history, 'review/start');
action($manager, $history, 'approve');
$delayed = createWork($manager, $employee, $project, 'R61 Delayed Historical Notice');
action($employee, $delayed, 'start');
$fail = true;
Event::listen(NotificationSending::class, function ($event) use (&$fail) {
    if ($fail && $event->notification instanceof TaskReviewWorkflowNotification) {
        throw new RuntimeException('R61 controlled transport outage');
    }
});
action($employee, $delayed, 'submit');
action($manager, $delayed, 'cancel', ['cancellation_reason' => 'R61_PRIVATE_BROWSER_REASON']);
$fail = false;
$intent = WorkflowNotificationIntent::where('task_id', $delayed->id)->where('transition', 'submitted')->where('recipient_id', $manager->id)->firstOrFail();
app(RequiredWorkflowNotifications::class)->deliver($intent->id);
echo json_encode(['employee' => $employee->only(['id', 'name', 'email']), 'former' => $former->only(['id', 'name', 'email']), 'pm' => $pm->only(['id', 'name', 'email']), 'next' => $next->only(['id', 'name', 'email']), 'admin' => $admin->only(['id', 'name', 'email']), 'project' => $project->id, 'project_name' => $project->name, 'loading_task' => $loading->id, 'promotion_task' => $promotion->id, 'pm_task' => $handover->id, 'notice' => $intent->id], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
