<?php

namespace App\Http\Requests;

use App\Models\Task;
use App\Support\InputContracts;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompletedTaskIndexRequest extends FormRequest
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
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:200'],
            'priority' => ['nullable', 'string', Rule::in(Task::PRIORITIES)],
            'project' => InputContracts::id('nullable', 'min:1'),
            'assignee' => InputContracts::id('nullable', 'min:1'),
        ];
    }
}
