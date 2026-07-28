<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BrowserPushEndpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->active === true;
    }

    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'max:2048', 'url', 'starts_with:https://'],
        ];
    }
}
