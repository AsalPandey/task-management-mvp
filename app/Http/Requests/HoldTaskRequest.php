<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HoldTaskRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('hold', $this->route('task')) ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
            'status' => ['prohibited'],
            'source_state' => ['prohibited'],
            'target_state' => ['prohibited'],
        ];
    }
}
