<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class AccountEmailAvailable implements ValidationRule
{
    public function __construct(private readonly ?int $ignoreId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }
        $existing = User::withTrashed()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($value))])
            ->when($this->ignoreId, fn ($query) => $query->whereKeyNot($this->ignoreId))->first();
        if ($existing?->trashed() || ($existing && ! $existing->active)) {
            $fail('This email belongs to a prior account. Reuse is prohibited to preserve its history; contact your manager about the prior account.');
        } elseif ($existing) {
            $fail('The email has already been taken.');
        }
    }
}
