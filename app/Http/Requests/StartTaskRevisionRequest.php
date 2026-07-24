<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartTaskRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('startRevision', $this->route('task')) ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['prohibited'],
            'revision_due_date' => ['prohibited'],
            'active_revision_cycle_id' => ['prohibited'],
        ];
    }
}
