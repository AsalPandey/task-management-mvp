<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class PositiveResourceId implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $digits = is_int($value) ? (string) $value : $value;
        $maximum = (string) PHP_INT_MAX;
        if (! is_string($digits) || ! preg_match('/^[1-9][0-9]*$/D', $digits)
            || strlen($digits) > strlen($maximum)
            || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
            $fail('The :attribute must be a positive integer identifier.');
        }
    }
}
