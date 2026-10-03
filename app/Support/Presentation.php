<?php

namespace App\Support;

final class Presentation
{
    public static function initials(?string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name ?? ''), flags: PREG_SPLIT_NO_EMPTY);
        if (! $parts) {
            return '?';
        }

        $initials = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            preg_match('/^\X/u', $part, $match);
            $initials .= $match[0] ?? '';
        }

        return mb_strtoupper($initials, 'UTF-8');
    }

    public static function role(?string $key): string
    {
        return match ($key) {
            'manager' => 'Manager',
            'project_manager' => 'Project Manager',
            'team_member' => 'Team Member',
            null => 'No role',
            default => 'Unknown role',
        };
    }
}
