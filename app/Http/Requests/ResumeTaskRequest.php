<?php

namespace App\Http\Requests;

class ResumeTaskRequest extends TaskTransitionRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('resume', $this->route('task')) ?? false;
    }

    public function rules(): array
    {
        return $this->lifecycleVersionRules() + [
            'status' => ['prohibited'],
            'source_state' => ['prohibited'],
            'target_state' => ['prohibited'],
        ];
    }
}
