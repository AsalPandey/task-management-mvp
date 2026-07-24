<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RequestTaskRevisionRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('formal_feedback'))) {
            $this->merge(['formal_feedback' => trim($this->input('formal_feedback'))]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('requestRevision', $this->route('task')) ?? false;
    }

    public function rules(): array
    {
        return [
            'formal_feedback' => ['required', 'string', 'max:5000'],
            'revision_due_date' => ['required', 'date', 'after:today'],
            'status' => ['prohibited'],
            'reviewer_id' => ['prohibited'],
            'assignee_id' => ['prohibited'],
        ];
    }
}
