<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResubmitTaskRequest extends FormRequest
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
        return $this->user()?->can('resubmit', $this->route('task')) ?? false;
    }

    public function rules(): array
    {
        return [
            'submission_note' => ['nullable', 'string', 'max:2000'],
            'status' => ['prohibited'],
            'reviewer_id' => ['prohibited'],
            'revision_due_date' => ['prohibited'],
        ];
    }
}
