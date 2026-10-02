<?php

namespace App\Http\Requests;

use App\Models\Task;
use App\Support\TaskCreationFields;
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
            'priority' => ['required', Rule::in(Task::PRIORITIES)],
            'start_date' => 'nullable|date',
            'due_date' => 'nullable|date|after_or_equal:start_date',
            'comments' => 'nullable|string',
        ] + array_fill_keys(TaskCreationFields::FORBIDDEN, ['missing']);
    }
}
