<?php

namespace App\Support;

final class TaskCreationFields
{
    public const ALLOWED = [
        'title', 'project_id', 'description', 'assignee_id', 'reviewer_id',
        'priority', 'start_date', 'due_date', 'comments',
    ];

    public const FORBIDDEN = [
        'task_uid', 'status', 'state', 'progress', 'started_at', 'submitted_at',
        'review_started_at', 'approved_at', 'approved_by', 'held_at', 'held_by',
        'hold_reason', 'completed_at', 'completed_by', 'cancelled_at', 'cancelled_by',
        'cancellation_reason', 'revision_count', 'active_revision_cycle_id',
        'execution_due_date', 'review_due_date', 'revision_due_date',
        'lock_version', 'expected_version', 'created_by', 'assigned_by', 'original_task_id',
        'event_uid', 'event_type', 'sequence', 'actor_id', 'operation_key',
        'correlation_id', 'changed_fields', 'metadata', 'legacy_completion_provenance',
    ];
}
