<?php

namespace App\Http\Requests;

use App\Support\InputContracts;
use Illuminate\Foundation\Http\FormRequest;

abstract class TaskTransitionRequest extends FormRequest
{
    protected function lifecycleVersionRules(): array
    {
        return [
            'expected_version' => InputContracts::id(),
            'lock_version' => ['prohibited'],
            'version' => ['prohibited'],
        ];
    }
}
