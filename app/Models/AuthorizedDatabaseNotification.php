<?php

namespace App\Models;

use App\Services\NotificationAccess;
use Illuminate\Notifications\DatabaseNotification;

class AuthorizedDatabaseNotification extends DatabaseNotification
{
    protected $table = 'notifications';

    public function getDataAttribute($value): array
    {
        // Preserve stored history; expose content using the owner's current access.
        return app(NotificationAccess::class)->forReader(
            User::find($this->notifiable_id),
            $this->fromJson($value) ?? [],
        );
    }
}
