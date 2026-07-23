<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResumeTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('resume', $this->route('task')) ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['prohibited'],
            'source_state' => ['prohibited'],
            'target_state' => ['prohibited'],
        ];
    }
}
