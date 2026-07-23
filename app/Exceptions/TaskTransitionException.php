<?php

namespace App\Exceptions;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TaskTransitionException extends ValidationException
{
    public readonly string $reason;

    private function __construct(string $reason, string $field, string $message)
    {
        $validator = Validator::make([], []);
        $validator->errors()->add($field, $message);

        parent::__construct($validator);

        $this->reason = $reason;
    }

    public static function invalidState(string $message, string $field = 'task'): self
    {
        return new self('invalid_state', $field, $message);
    }

    public static function missingData(string $field, string $message): self
    {
        return new self('missing_transition_data', $field, $message);
    }

    public static function invariant(string $field, string $message): self
    {
        return new self('invariant_violation', $field, $message);
    }
}
