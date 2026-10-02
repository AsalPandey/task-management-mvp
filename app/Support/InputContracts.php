<?php

namespace App\Support;

use App\Rules\PositiveResourceId;
use App\Rules\Utf8TextBytes;

final class InputContracts
{
    public const TEXT_CHARACTERS = 20000;

    public static function id(string $presence = 'required', mixed ...$rules): array
    {
        return ['bail', $presence, new PositiveResourceId, ...$rules];
    }

    public static function text(string $presence = 'nullable', int $characters = self::TEXT_CHARACTERS): array
    {
        return ['bail', $presence, 'string', 'max:'.$characters, new Utf8TextBytes];
    }
}
