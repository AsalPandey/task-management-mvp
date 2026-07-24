<?php

namespace App\Http\Requests;

use App\Models\Task;
use App\Support\TaskStateCompatibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaskStoreRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('status'))) {
            $this->merge([
                'status' => TaskStateCompatibility::normalizeLegacyLabel($this->input('status')),
            ]);
        }
    }

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
            'assignee_id' => 'nullable|exists:users,id',
            'reviewer_id' => 'nullable|exists:users,id',
            'review_due_date' => 'nullable|date|after:today',
            'priority' => ['required', Rule::in(Task::PRIORITIES)],
            'status' => ['required', Rule::in(TaskStateCompatibility::genericStates())],
            'progress' => 'required|integer|min:0|max:99',
            'approved_at' => ['missing'],
            'approved_by' => ['missing'],
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
