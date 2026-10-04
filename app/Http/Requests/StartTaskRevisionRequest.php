<?php

namespace App\Http\Requests;

class StartTaskRevisionRequest extends TaskTransitionRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('startRevision', $this->route('task')) ?? false;
    }

    public function rules(): array
    {
        return $this->lifecycleVersionRules() + [
            'status' => ['prohibited'],
            'revision_due_date' => ['prohibited'],
            'active_revision_cycle_id' => ['prohibited'],
        ];
    }
}
