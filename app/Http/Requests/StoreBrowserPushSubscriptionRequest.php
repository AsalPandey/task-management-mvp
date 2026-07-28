<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBrowserPushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->active === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'public_key' => $this->input('keys.p256dh'),
            'auth_secret' => $this->input('keys.auth'),
            'content_encoding' => $this->input('content_encoding', 'aes128gcm'),
        ]);
    }

    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'max:2048', 'url', 'starts_with:https://'],
            'public_key' => ['required', 'string', 'min:40', 'max:512', 'regex:/^[A-Za-z0-9_\\-+=\\/]+$/'],
            'auth_secret' => ['required', 'string', 'min:16', 'max:255', 'regex:/^[A-Za-z0-9_\\-+=\\/]+$/'],
            'content_encoding' => ['required', 'in:aes128gcm,aesgcm'],
            'device_label' => ['nullable', 'string', 'max:120'],
        ];
    }
}
