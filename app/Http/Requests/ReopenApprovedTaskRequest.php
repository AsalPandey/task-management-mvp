<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReopenApprovedTaskRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reopen_reason'))) {
            $this->merge(['reopen_reason' => trim($this->input('reopen_reason'))]);
        }
        if (is_string($this->input('rework_instructions'))) {
            $this->merge(['rework_instructions' => trim($this->input('rework_instructions'))]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('reopen', $this->route('task')) ?? false;
    }

    public function rules(): array
    {
        return [
            'reopen_reason' => ['required', 'string', 'max:5000'],
            'revision_due_date' => ['required', 'date', 'after:today'],
            'rework_instructions' => ['nullable', 'string', 'max:5000'],
            'status' => ['prohibited'],
            'progress' => ['prohibited'],
            'assignee_id' => ['prohibited'],
            'reviewer_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
