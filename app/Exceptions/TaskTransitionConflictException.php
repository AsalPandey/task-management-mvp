<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class TaskTransitionConflictException extends ConflictHttpException
{
    public function __construct(string $message = 'The task changed before the transition could be applied.')
    {
        parent::__construct($message);
    }
}
