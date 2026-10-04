<?php

namespace App\Http\Requests;

class StartTaskReviewRequest extends TaskTransitionRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('startReview', $this->route('task')) ?? false;
    }

    public function rules(): array
    {
        return $this->lifecycleVersionRules() + [
            'status' => ['prohibited'],
            'reviewer_id' => ['prohibited'],
            'source_state' => ['prohibited'],
            'target_state' => ['prohibited'],
        ];
    }
}
