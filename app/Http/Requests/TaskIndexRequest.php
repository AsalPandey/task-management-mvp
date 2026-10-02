<?php

namespace App\Http\Requests;

use App\Enums\TaskState;
use App\Models\Task;
use App\Support\InputContracts;
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
            'status' => [
                'nullable',
                'string',
                Rule::in(array_column(TaskState::cases(), 'value')),
            ],
            'priority' => ['nullable', 'string', Rule::in(Task::PRIORITIES)],
            'project' => InputContracts::id('nullable', 'min:1'),
            'assignee' => InputContracts::id('nullable', 'min:1'),
            'reviewer' => InputContracts::id('nullable', 'min:1'),
            'scope' => [
                'nullable',
                Rule::in(['assigned_to_me', 'created_by_me', 'waiting_for_review']),
            ],
        ];
    }
}
