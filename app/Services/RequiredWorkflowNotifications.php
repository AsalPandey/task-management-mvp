<?php

namespace App\Services;

use App\Exceptions\TaskNotificationDispatchException;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkflowNotificationIntent;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskReviewWorkflowNotification;
use App\Notifications\TaskWorkflowTransitionNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class RequiredWorkflowNotifications
{
    public function record(Task $task, User $actor, string $transition, Collection $recipients, string $action, bool $deliverAfterCommit = true): void
    {
        if (! $deliverAfterCommit && $task->getConnection()->transactionLevel() === 0) {
            throw new RuntimeException('An outer transaction is required for bulk workflow intents.');
        }
        foreach ($recipients->filter()->unique('id') as $recipient) {
            $responsibility = (int) $recipient->id === (int) $task->assignee_id ? 'assignee'
                : ((int) $recipient->id === (int) $task->reviewer_id ? 'reviewer' : 'observer');
            $identity = [
                'task_id' => $task->id, 'task_version' => $task->lock_version,
                'recipient_id' => $recipient->id, 'transition' => $transition,
            ];
            $values = ['id' => (string) Str::uuid(), 'actor_id' => $actor->id, 'responsibility' => $responsibility,
                'action' => $action, 'available_at' => now()];
            if ($deliverAfterCommit) {
                $intent = WorkflowNotificationIntent::firstOrCreate($identity, $values);
            } else {
                // PM reconciliation holds the task row through the outer commit.
                // Its new version serializes this identity; an unexpected unique
                // violation must roll back the whole operation, not a savepoint.
                $intent = WorkflowNotificationIntent::firstOrNew($identity, $values);
                if (! $intent->exists) {
                    $intent->save();
                }
            }
            $id = $intent->id;
            if ($deliverAfterCommit) {
                $task->getConnection()->afterCommit(fn () => $this->deliver($id));
            }
        }
    }

    public function deliverProjectReviewerReassignments(int $projectId): void
    {
        WorkflowNotificationIntent::query()->where('status', 'pending')->where('transition', 'reviewer_reassigned')
            ->whereIn('task_id', Task::query()->where('project_id', $projectId)->select('id'))
            ->select('id')->chunkById(100, function ($intents) {
                foreach ($intents as $intent) {
                    $this->deliver($intent->id);
                }
            });
    }

    public function deliver(string $id): void
    {
        $snapshot = WorkflowNotificationIntent::find($id);
        if (! $snapshot || $snapshot->status !== 'pending') {
            return;
        }
        try {
            DB::transaction(function () use ($snapshot, $id) {
                // Current account -> project -> task -> intent. Mutation writers never lock intents first.
                $user = User::with('role')->whereKey($snapshot->recipient_id)->lockForUpdate()->first();
                $routing = Task::find($snapshot->task_id);
                $project = $routing ? Project::whereKey($routing->project_id)->lockForUpdate()->first() : null;
                $task = Task::whereKey($snapshot->task_id)->lockForUpdate()->first();
                $intent = WorkflowNotificationIntent::whereKey($id)->lockForUpdate()->first();
                if (! $intent || $intent->status !== 'pending') {
                    return;
                }
                $task?->setRelation('project', $project);
                if ($task && $routing && (int) $task->project_id !== (int) $routing->project_id) {
                    throw new RuntimeException('Task routing changed; retry against the current project.');
                }
                $valid = $task && $project && (int) $task->project_id === (int) $project->id
                    && $user && $user->isActive() && $user->can('view', $task);
                if ($valid && $intent->responsibility === 'assignee') {
                    $valid = (int) $task->assignee_id === (int) $user->id
                        && app(TaskAssignmentCandidateService::class)->canExecuteInProject($user, $project)
                        && DB::table('project_user')->where('project_id', $project->id)->where('user_id', $user->id)->lockForUpdate()->exists();
                }
                if ($valid && $intent->responsibility === 'reviewer') {
                    $valid = (int) $task->reviewer_id === (int) $user->id
                        && app(ReviewerEligibilityService::class)->isEligible($user, $project, $task->assignee_id);
                }
                if (! $valid) {
                    $intent->update(['status' => 'discarded', 'finished_at' => now()]);

                    return;
                }
                $actor = User::find($intent->actor_id) ?? new User(['name' => 'System']);
                $task->loadMissing(['assignee', 'creator', 'reviewer', 'activeRevisionCycle', 'approval', 'project.projectManager']);
                $notification = match ($intent->transition) {
                    'assigned' => new TaskAssignedNotification($task, $actor),
                    'held', 'resumed' => new TaskWorkflowTransitionNotification($task, $actor, $intent->transition, $intent->action),
                    default => new TaskReviewWorkflowNotification($task, $actor, $intent->transition, $intent->action),
                };
                $data = $notification->toArray($user);
                if (! app(NotificationPreferencePolicy::class)->decideForType($user, $data['type'], 'database')->allowed) {
                    $intent->update(['status' => 'discarded', 'finished_at' => now()]);

                    return;
                }
                $notification->id = $intent->id;
                if (! DB::table('notifications')->where('id', $id)->exists()) {
                    Notification::send(collect([$user]), $notification);
                    if (! Notification::isFake() && ! DB::table('notifications')->where('id', $id)->exists()) {
                        throw new RuntimeException('Required in-app notice was not persisted.');
                    }
                }
                $intent->update(['status' => 'delivered', 'finished_at' => now(), 'last_error' => null]);
            }, 3);
        } catch (Throwable $exception) {
            // Delivery writes rolled back; the original business transaction is already committed.
            WorkflowNotificationIntent::whereKey($id)->where('status', 'pending')->update([
                'attempts' => DB::raw('attempts + 1'),
                'available_at' => now()->addSeconds(min(3600, 30 * (2 ** min(7, (int) $snapshot->attempts)))),
                'last_error' => get_class($exception),
            ]);
            throw new TaskNotificationDispatchException('task.'.$snapshot->transition, (int) $snapshot->task_id, $exception);
        }
    }
}
