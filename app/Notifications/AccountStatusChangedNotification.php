<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class AccountStatusChangedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public bool $active,
        public ?User $actor = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->active ? 'account_activated' : 'account_deactivated',
            'message' => $this->active
                ? 'Your account has been activated.'
                : 'Your account has been deactivated.',
            'actor_id' => $this->actor?->id,
        ];
    }
}
