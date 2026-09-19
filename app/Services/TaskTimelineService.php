<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use Illuminate\Support\Collection;

final class TaskTimelineService
{
    private const LABELS = [
        'task.created' => 'Task created',
        'task.started' => 'Work started',
        'task.held' => 'Task placed on hold',
        'task.resumed' => 'Work resumed',
        'task.submitted' => 'Submitted for review',
        'task.review_started' => 'Review started',
        'task.revision_requested' => 'Revision requested',
        'task.revision_started' => 'Revision work started',
        'task.resubmitted' => 'Task resubmitted',
        'task.approved' => 'Task approved and completed',
        'task.completed' => 'Task completed',
        'task.reopened' => 'Task reopened for revision',
        'task.cancelled' => 'Task cancelled',
        'task.reviewer_reassigned' => 'Reviewer reassigned',
        'task.deadline_changed' => 'Deadline changed',
        'task.updated' => 'Task details updated',
    ];

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function forViewer(Task $task, User $viewer, int $limit = 100): Collection
    {
        $management = $viewer->can('viewManagementNotes', $task);
        $events = $task->events()
            ->with('actor:id,name')
            ->reorder()
            ->orderByDesc('sequence')
            ->limit(min(max($limit, 1), 100))
            ->get()
            ->sortBy('sequence')
            ->values();

        return $events
            ->reject(function (TaskEvent $event, int $index) use ($events): bool {
                if ($event->event_type !== TaskEventRecorder::COMPLETED) {
                    return false;
                }

                $previous = $events->get($index - 1);

                return $previous?->event_type === TaskEventRecorder::APPROVED
                    && $previous?->correlation_id === $event->correlation_id;
            })
            ->map(function (TaskEvent $event) use ($management): array {
                $details = [];
                if ($event->event_type === TaskEventRecorder::DEADLINE_CHANGED) {
                    $details[] = ucfirst((string) data_get($event->metadata, 'deadline_type')).' deadline updated';
                }
                if ($event->event_type === TaskEventRecorder::REVIEWER_REASSIGNED) {
                    $details[] = 'Reviewer assignment updated';
                }
                if ($management && data_get($event->metadata, 'reason_reference')) {
                    $details[] = 'Management reason recorded';
                }

                return [
                    'sequence' => (int) $event->sequence,
                    'label' => self::LABELS[$event->event_type] ?? 'Task activity recorded',
                    'actor' => $event->actor?->name ?? 'System',
                    'occurred_at' => $event->occurred_at?->toAtomString(),
                    'details' => $details,
                ];
            });
    }
}
