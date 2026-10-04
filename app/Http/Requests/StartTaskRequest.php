<?php

namespace App\Http\Requests;

class StartTaskRequest extends TaskTransitionRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('start', $this->route('task')) ?? false;
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
