<?php

namespace App\Support;

/** SQL projection of Task::activeDeadline(), for reads only. Delivery still rechecks under lock. */
final class TaskDeadlineSql
{
    public static function kind(): string
    {
        return "CASE
            WHEN tasks.status IN ('completed', 'cancelled') THEN NULL
            WHEN tasks.status IN ('submitted', 'in_review') THEN 'review'
            WHEN tasks.status = 'revision_requested'
                OR (tasks.status = 'in_progress' AND tasks.active_revision_cycle_id IS NOT NULL
                    AND tasks.active_revision_cycle_id <> 0 AND tasks.revision_due_date IS NOT NULL) THEN 'revision'
            ELSE 'execution' END";
    }

    public static function date(): string
    {
        return "DATE(CASE
            WHEN tasks.status IN ('completed', 'cancelled') THEN NULL
            WHEN tasks.status IN ('submitted', 'in_review') THEN tasks.review_due_date
            WHEN tasks.status = 'revision_requested'
                OR (tasks.status = 'in_progress' AND tasks.active_revision_cycle_id IS NOT NULL AND tasks.active_revision_cycle_id <> 0
                    AND tasks.revision_due_date IS NOT NULL) THEN tasks.revision_due_date
            ELSE COALESCE(tasks.execution_due_date, tasks.due_date)
        END)";
    }
}
