<?php

namespace App\Support;

use Illuminate\Support\Str;

class UlidGenerator
{
    public function generate(): string
    {
        return strtoupper((string) Str::ulid());
    }
}
