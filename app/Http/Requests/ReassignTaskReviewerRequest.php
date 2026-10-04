<?php

namespace App\Http\Requests;

use App\Support\InputContracts;

class ReassignTaskReviewerRequest extends TaskTransitionRequest
{
    public function authorize(): bool
    {
        $task = $this->route('task');

        return $task && ($this->user()?->can('reassignReviewer', $task) ?? false);
    }

    public function rules(): array
    {
        return $this->lifecycleVersionRules() + [
            'reviewer_id' => InputContracts::id('required', 'exists:users,id'),
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
