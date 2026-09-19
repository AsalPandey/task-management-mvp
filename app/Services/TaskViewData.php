<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;

final class TaskViewData
{
    /**
     * Serialize only fields authorized for the current viewer.
     *
     * @return array<string, mixed>
     */
    public function make(Task $task, User $viewer): array
    {
        $task->loadMissing('latestSubmission.submittedBy');
        $data = [
            'id' => (int) $task->id,
            'task_uid' => $task->task_uid,
            'project_id' => $task->project_id === null ? null : (int) $task->project_id,
            'title' => $task->title,
            'description' => $task->description,
            'assignee_id' => $task->assignee_id === null ? null : (int) $task->assignee_id,
            'reviewer_id' => $task->reviewer_id === null ? null : (int) $task->reviewer_id,
            'priority' => $task->priority,
            'status' => $task->statusLabel(),
            'state' => $task->machineState()->value,
            'progress' => (int) $task->progress,
            'start_date' => $task->start_date?->toDateString(),
            'due_date' => $task->due_date?->toDateString(),
            'execution_due_date' => $task->execution_due_date?->toDateString(),
            'review_due_date' => $task->review_due_date?->toDateString(),
            'revision_due_date' => $task->revision_due_date?->toDateString(),
            'comments' => $task->comments,
            'submitted_at' => $task->submitted_at?->toAtomString(),
            'review_started_at' => $task->review_started_at?->toAtomString(),
            'completed_at' => $task->completed_at?->toAtomString(),
            'latest_submission' => $task->latestSubmission ? [
                'note' => $task->latestSubmission->submission_note,
                'submitted_at' => $task->latestSubmission->submitted_at?->toAtomString(),
                'submitted_by' => $task->latestSubmission->submittedBy?->name,
            ] : null,
            'project' => $task->project ? [
                'id' => (int) $task->project->id,
                'name' => $task->project->name,
            ] : null,
            'assignee' => $task->assignee ? [
                'id' => (int) $task->assignee->id,
                'name' => $task->assignee->name,
            ] : null,
            'reviewer' => $task->reviewer ? [
                'id' => (int) $task->reviewer->id,
                'name' => $task->reviewer->name,
            ] : null,
        ];

        if ($viewer->can('viewManagementNotes', $task)) {
            $data += [
                'hold_reason' => $task->hold_reason,
                'held_at' => $task->held_at?->toAtomString(),
                'cancellation_reason' => $task->cancellation_reason,
                'cancelled_at' => $task->cancelled_at?->toAtomString(),
                'approved_at' => $task->approved_at?->toAtomString(),
                'approved_by' => $task->approved_by,
            ];
        }

        return $data;
    }
}
