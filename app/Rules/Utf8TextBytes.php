<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class Utf8TextBytes implements ValidationRule
{
    public function __construct(private readonly int $maximum = 60000) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || strlen($value) > $this->maximum) {
            $fail('The :attribute exceeds the supported UTF-8 storage size.');
        }
    }
}
