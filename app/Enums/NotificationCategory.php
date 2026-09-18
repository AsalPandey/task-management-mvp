<?php

namespace App\Enums;

enum NotificationCategory: string
{
    case Mandatory = 'mandatory';
    case TaskCompletion = 'task_completion';
    case TeamUpdates = 'team_updates';
    case System = 'system';
    case ExplicitUserAction = 'explicit_user_action';

    public function preferenceKey(): ?string
    {
        return match ($this) {
            self::TaskCompletion => 'task_completed',
            self::TeamUpdates => 'team_updates',
            self::Mandatory, self::System, self::ExplicitUserAction => null,
        };
    }
}
