<?php

namespace App\Support;

use App\Models\Task;
use Illuminate\Support\Arr;

final class WorkflowNoticeSemantics
{
    public static function interpret(array $payload, Task $task, int $version, string $transition, ?string $eventAt): array
    {
        $historical = $version !== (int) $task->lock_version;
        if ($historical) {
            // A task version is immutable event-generation identity. Once advanced,
            // retain a historical notice, never attach a newer generation's details
            // or encourage the old action. Benign edits do not silently lose notices.
            $payload = Arr::only($payload, ['task_id', 'task_uid', 'task_title', 'task_url', 'type', 'actor_id', 'actor', 'assignor']);
            $event = match ($transition) {
                'assigned' => 'work was assigned',
                'submitted' => 'work was submitted for review',
                'resubmitted' => 'revised work was resubmitted for review',
                'review_started' => 'review started',
                'revision_requested' => 'revision was requested',
                'revision_started' => 'revision work started',
                'approved_completed' => 'work was approved and completed',
                'reopened_revision_required' => 'work was reopened for revision',
                'reviewer_reassigned' => 'review responsibility changed',
                'deadline_changed' => 'a workflow deadline changed',
                'cancelled' => 'work was cancelled',
                'held' => 'work was put on hold',
                'resumed' => 'work resumed',
                default => 'workflow activity was recorded',
            };
            $payload['message'] = "Historical notice: {$event} for '{$task->title}'. Current status: {$task->statusLabel()}. Open the task for current actions.";
            $payload['required_action'] = 'View current task (historical notice)';
            $payload['next_action'] = 'View current task (historical notice)';
        }

        return $payload + [
            'event_at' => $eventAt,
            'event_task_version' => $version,
            'current_state' => $task->machineState()->value,
            'current_state_label' => $task->statusLabel(),
            'historical' => $historical,
            'actionable' => ! $historical && ! in_array(($payload['required_action'] ?? $payload['next_action'] ?? 'None'), ['None', 'No further workflow action'], true),
        ];
    }
}
