<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanySetting extends Model
{
    protected $fillable = [
        'company_name',
        'timezone',
        'app_url',
        'installed_at',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'installed_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    public static function current(): ?self
    {
        return self::query()->first();
    }

    public static function isInstalled(): bool
    {
        return self::query()->whereNotNull('installed_at')->exists();
    }
}
