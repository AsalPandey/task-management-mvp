<?php

namespace App\Http\Requests;

class SubmitTaskRequest extends TaskTransitionRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('submission_note'))) {
            $note = trim($this->input('submission_note'));
            $this->merge(['submission_note' => $note === '' ? null : $note]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('submit', $this->route('task')) ?? false;
    }

    public function rules(): array
    {
        return $this->lifecycleVersionRules() + [
            'submission_note' => ['nullable', 'string', 'max:2000'],
            'status' => ['prohibited'],
            'reviewer_id' => ['prohibited'],
            'review_due_date' => ['prohibited'],
        ];
    }
}
