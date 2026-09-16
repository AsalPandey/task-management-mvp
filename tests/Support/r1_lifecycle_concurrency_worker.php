<?php

use App\Exceptions\AccountLifecycleException;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountLifecycleService;
use App\Services\ProjectManagerReplacementService;
use App\Services\TaskLifecycleService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$operation = (string) ($argv[1] ?? '');
$targetId = (int) ($argv[2] ?? 0);
$actorId = (int) ($argv[3] ?? 0);
$worker = (string) ($argv[4] ?? 'worker');
$holdMilliseconds = (int) ($argv[5] ?? 0);
$readyFile = ! empty($argv[6]) ? $argv[6] : null;
$payloadJson = $argv[7] ?? '{}';
$payload = json_decode($payloadJson, true) ?: [];

try {
    $result = DB::transaction(function () use ($operation, $targetId, $actorId, $holdMilliseconds, $readyFile, $payload) {
        $actor = User::query()->findOrFail($actorId);

        switch ($operation) {
            case 'delete_user':
                Role::query()->where('name', 'manager')->lockForUpdate()->first();
                if ($holdMilliseconds > 0) {
                    if ($readyFile) {
                        file_put_contents($readyFile, 'locked');
                    }
                    usleep($holdMilliseconds * 1000);
                }
                $targetUser = User::query()->whereKey($targetId)->with('role')->lockForUpdate()->firstOrFail();
                app(AccountLifecycleService::class)->assertCanDelete($targetUser, $actor);
                $targetUser->delete();

                return ['action' => 'deleted', 'user_id' => $targetId];

            case 'deactivate_user':
                Role::query()->where('name', 'manager')->lockForUpdate()->first();
                if ($holdMilliseconds > 0) {
                    if ($readyFile) {
                        file_put_contents($readyFile, 'locked');
                    }
                    usleep($holdMilliseconds * 1000);
                }
                $targetUser = User::query()->whereKey($targetId)->with('role')->lockForUpdate()->firstOrFail();
                app(AccountLifecycleService::class)->assertCanDeactivate($targetUser, $actor);
                $targetUser->forceFill(['active' => false])->save();

                return ['action' => 'deactivated', 'user_id' => $targetId];

            case 'demote_user':
                $memberRole = Role::query()->firstOrCreate(['name' => 'team_member'], ['label' => 'Team Member']);
                Role::query()->where('name', 'manager')->lockForUpdate()->first();
                if ($holdMilliseconds > 0) {
                    if ($readyFile) {
                        file_put_contents($readyFile, 'locked');
                    }
                    usleep($holdMilliseconds * 1000);
                }
                $targetUser = User::query()->whereKey($targetId)->with('role')->lockForUpdate()->firstOrFail();
                app(AccountLifecycleService::class)->assertCanChangeRole($targetUser, $memberRole->id, $actor);
                $targetUser->update(['role_id' => $memberRole->id]);

                return ['action' => 'demoted', 'user_id' => $targetId];

            case 'deactivate_user_with_user_lock':
                $targetUser = User::query()->whereKey($targetId)->with('role')->lockForUpdate()->firstOrFail();
                if ($holdMilliseconds > 0) {
                    if ($readyFile) {
                        file_put_contents($readyFile, 'locked');
                    }
                    usleep($holdMilliseconds * 1000);
                }
                app(AccountLifecycleService::class)->assertCanDeactivate($targetUser, $actor);
                $targetUser->forceFill(['active' => false])->save();

                return ['action' => 'deactivated', 'user_id' => $targetId];

            case 'create_task':
                $projectId = (int) ($payload['project_id'] ?? 0);
                $assigneeId = $targetId;
                $reviewerId = (int) ($payload['reviewer_id'] ?? $actorId);

                if ($holdMilliseconds > 0) {
                    User::query()->whereKey($assigneeId)->lockForUpdate()->firstOrFail();
                    if ($readyFile) {
                        file_put_contents($readyFile, 'locked');
                    }
                    usleep($holdMilliseconds * 1000);
                }

                $task = app(TaskLifecycleService::class)->create([
                    'project_id' => $projectId,
                    'title' => 'Concurrent race task',
                    'assignee_id' => $assigneeId,
                    'reviewer_id' => $reviewerId,
                    'priority' => 'Medium',
                ], $actor);

                return ['action' => 'created_task', 'task_id' => $task->id];

            case 'replace_pm':
                $projectId = $targetId;
                $newPmId = (int) ($payload['new_pm_id'] ?? 0);

                $lockedProject = Project::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();
                if ($holdMilliseconds > 0) {
                    if ($readyFile) {
                        file_put_contents($readyFile, 'locked');
                    }
                    usleep($holdMilliseconds * 1000);
                }

                $oldPmId = $lockedProject->project_manager_id ? (int) $lockedProject->project_manager_id : null;
                if ($oldPmId !== $newPmId) {
                    app(ProjectManagerReplacementService::class)->reconcile(
                        project: $lockedProject,
                        oldPmId: $oldPmId,
                        newPmId: $newPmId,
                        actor: $actor,
                    );
                }
                $lockedProject->update(['project_manager_id' => $newPmId]);
                if ($lockedProject->project_manager_id) {
                    $lockedProject->members()->syncWithoutDetaching([
                        $lockedProject->project_manager_id => ['added_by' => $actor->id],
                    ]);
                }

                return [
                    'action' => 'replaced_pm',
                    'project_id' => $projectId,
                    'new_pm_id' => $newPmId,
                ];

            default:
                throw new InvalidArgumentException("Unsupported operation [{$operation}]");
        }
    });

    $response = [
        'result' => 'success',
        'database' => DB::getDatabaseName(),
        'details' => $result,
    ];
} catch (AccountLifecycleException $e) {
    $response = [
        'result' => 'conflict',
        'status_code' => $e->getStatusCode(),
        'database' => DB::getDatabaseName(),
        'message' => $e->getMessage(),
    ];
} catch (ValidationException $e) {
    $response = [
        'result' => 'conflict',
        'status_code' => 422,
        'database' => DB::getDatabaseName(),
        'message' => collect($e->errors())->flatten()->first(),
    ];
} catch (HttpException $e) {
    $response = [
        'result' => 'conflict',
        'status_code' => $e->getStatusCode(),
        'database' => DB::getDatabaseName(),
        'message' => $e->getMessage(),
    ];
} catch (Throwable $e) {
    $response = [
        'result' => 'error',
        'database' => DB::getDatabaseName(),
        'exception' => get_class($e),
        'message' => $e->getMessage(),
    ];
}

fwrite(STDOUT, json_encode($response, JSON_THROW_ON_ERROR));
