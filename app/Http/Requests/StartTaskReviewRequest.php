<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartTaskReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('startReview', $this->route('task')) ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['prohibited'],
            'reviewer_id' => ['prohibited'],
            'source_state' => ['prohibited'],
            'target_state' => ['prohibited'],
        ];
    }
}
