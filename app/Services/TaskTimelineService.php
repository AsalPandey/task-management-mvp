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
        return $this->pageForViewer($task, $viewer, null, $limit)['entries'];
    }

    public function pageForViewer(Task $task, User $viewer, ?int $before = null, int $limit = 100): array
    {
        $management = $viewer->can('viewManagementNotes', $task);
        $limit = min(max($limit, 1), 100);
        $events = $task->events()
            ->with('actor:id,name')
            ->reorder()
            ->orderByDesc('sequence')
            ->when($before !== null, fn ($query) => $query->where('sequence', '<', $before))
            // Collapse the established approval/completion presentation pair before
            // pagination so a page boundary cannot expose its duplicate completion.
            ->where(fn ($query) => $query->where('event_type', '!=', TaskEventRecorder::COMPLETED)
                ->orWhereNotExists(fn ($previous) => $previous->selectRaw('1')->from('task_events as previous')
                    ->whereColumn('previous.task_id', 'task_events.task_id')
                    ->whereRaw('previous.sequence = task_events.sequence - 1')
                    ->where('previous.event_type', TaskEventRecorder::APPROVED)
                    ->where(fn ($correlation) => $correlation->whereColumn('previous.correlation_id', 'task_events.correlation_id')
                        ->orWhere(fn ($nulls) => $nulls->whereNull('previous.correlation_id')->whereNull('task_events.correlation_id')))))
            ->limit($limit + 1)
            ->get();
        $hasMore = $events->count() > $limit;
        $events = $events->take($limit)
            ->sortBy('sequence')
            ->values();

        $entries = $events->map(function (TaskEvent $event) use ($management): array {
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

        return ['entries' => $entries, 'has_more' => $hasMore,
            'next_cursor' => $hasMore ? (string) $events->first()->sequence : null];
    }
}
