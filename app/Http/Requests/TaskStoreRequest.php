<?php

namespace App\Http\Requests;

use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaskStoreRequest extends FormRequest
{
    public function authorize()
    {
        return $this->user()?->can('create', Task::class) ?? false;
    }

    public function rules()
    {
        return [
            'title' => 'required|string|max:255',
            'project_id' => 'required|exists:projects,id',
            'description' => 'nullable|string',
            'assignee_id' => 'required|exists:users,id',
            'reviewer_id' => 'required|exists:users,id|different:assignee_id',
            'review_due_date' => ['missing'],
            'execution_due_date' => ['missing'],
            'priority' => ['required', Rule::in(Task::PRIORITIES)],
            'status' => ['missing'],
            'state' => ['missing'],
            'progress' => ['missing'],
            'task_uid' => ['missing'],
            'started_at' => ['missing'],
            'submitted_at' => ['missing'],
            'review_started_at' => ['missing'],
            'approved_at' => ['missing'],
            'approved_by' => ['missing'],
            'held_at' => ['missing'],
            'held_by' => ['missing'],
            'hold_reason' => ['missing'],
            'completed_at' => ['missing'],
            'completed_by' => ['missing'],
            'cancelled_at' => ['missing'],
            'cancelled_by' => ['missing'],
            'cancellation_reason' => ['missing'],
            'revision_count' => ['missing'],
            'active_revision_cycle_id' => ['missing'],
            'revision_due_date' => ['missing'],
            'start_date' => 'nullable|date',
            'due_date' => 'nullable|date|after_or_equal:start_date',
            'comments' => 'nullable|string',
        ];
    }
}
