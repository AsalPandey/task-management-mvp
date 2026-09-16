<?php

namespace App\Support;

use App\Models\User;

final class UserPayload
{
    public static function roster(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'name' => $user->name,
            'active' => (bool) $user->active,
            'role' => $user->role ? ['id' => (int) $user->role->id, 'name' => $user->role->name] : null,
        ];
    }

    public static function account(User $user): array
    {
        return self::roster($user) + ['email' => $user->email, 'role_id' => (int) $user->role_id];
    }

    public static function self(User $user): array
    {
        return self::account($user) + ['timezone' => $user->timezone, 'notification_preferences' => $user->notification_preferences];
    }
}
