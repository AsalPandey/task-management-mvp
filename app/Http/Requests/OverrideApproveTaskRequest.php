<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OverrideApproveTaskRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['override_reason', 'approval_comment'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                $this->merge([$field => $value === '' ? null : $value]);
            }
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('overrideApprove', $this->route('task')) ?? false;
    }

    public function rules(): array
    {
        return [
            'override_reason' => ['required', 'string', 'max:5000'],
            'approval_comment' => ['nullable', 'string', 'max:2000'],
            'status' => ['prohibited'],
            'progress' => ['prohibited'],
        ];
    }
}
