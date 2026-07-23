<?php

namespace App\Http\Requests;

use App\Models\Task;
use App\Support\TaskStateCompatibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaskIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Task::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->query('search'))) {
            $this->merge([
                'search' => trim($this->query('search')),
            ]);
        }

        if (is_string($this->query('status')) && $this->query('status') !== '') {
            $this->merge([
                'status' => TaskStateCompatibility::normalizeLegacyLabel($this->query('status')),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', 'string', Rule::in(TaskStateCompatibility::genericStates())],
            'priority' => ['nullable', 'string', Rule::in(Task::PRIORITIES)],
            'project' => ['nullable', 'integer', 'min:1'],
            'assignee' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
