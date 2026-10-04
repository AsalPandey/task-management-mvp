<?php

namespace App\Support;

use App\Rules\PositiveResourceId;
use App\Rules\Utf8TextBytes;

final class InputContracts
{
    public const TEXT_CHARACTERS = 20000;

    public static function date(string ...$rules): array
    {
        return ['bail', 'nullable', 'date_format:Y-m-d', 'after_or_equal:1000-01-01', 'before_or_equal:9999-12-31', ...$rules];
    }

    public static function id(string $presence = 'required', mixed ...$rules): array
    {
        return ['bail', $presence, new PositiveResourceId, ...$rules];
    }

    public static function text(string $presence = 'nullable', int $characters = self::TEXT_CHARACTERS): array
    {
        return ['bail', $presence, 'string', 'max:'.$characters, new Utf8TextBytes];
    }
}
