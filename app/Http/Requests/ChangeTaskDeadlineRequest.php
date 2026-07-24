<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeTaskDeadlineRequest extends FormRequest
{
    public function authorize(): bool
    {
        $task = $this->route('task');

        return $task && ($this->user()?->can('changeDeadline', $task) ?? false);
    }

    public function rules(): array
    {
        return [
            'deadline_type' => ['required', Rule::in(['execution', 'review', 'revision'])],
            'due_date' => ['required', 'date_format:Y-m-d', 'after:today'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['deadline_type' => $this->route('deadlineType')]);
    }
}
