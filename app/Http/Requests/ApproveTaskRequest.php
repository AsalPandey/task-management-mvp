<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveTaskRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('approval_comment'))) {
            $comment = trim($this->input('approval_comment'));
            $this->merge(['approval_comment' => $comment === '' ? null : $comment]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('approve', $this->route('task')) ?? false;
    }

    public function rules(): array
    {
        return [
            'approval_comment' => ['nullable', 'string', 'max:2000'],
            'status' => ['prohibited'],
            'progress' => ['prohibited'],
        ];
    }
}
