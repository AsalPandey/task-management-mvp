<?php

namespace App\Http\Requests;

class CancelTaskRequest extends TaskTransitionRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('cancellation_reason'))) {
            $this->merge(['cancellation_reason' => trim($this->input('cancellation_reason'))]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('cancel', $this->route('task')) ?? false;
    }

    public function rules(): array
    {
        return $this->lifecycleVersionRules() + [
            'cancellation_reason' => ['required', 'string', 'max:5000'],
            'status' => ['prohibited'],
            'progress' => ['prohibited'],
            'cancelled_at' => ['prohibited'],
            'cancelled_by' => ['prohibited'],
        ];
    }
}
