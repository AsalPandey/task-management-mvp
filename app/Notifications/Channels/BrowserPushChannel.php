<?php

namespace App\Notifications\Channels;

use App\Contracts\SendsBrowserPush;
use App\Jobs\SendBrowserPushNotification;
use App\Models\User;
use App\Services\NotificationPreferencePolicy;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class BrowserPushChannel
{
    public function __construct(private readonly NotificationPreferencePolicy $preferences) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (
            ! $notification instanceof SendsBrowserPush
            || ! $notifiable instanceof User
            || ! $notifiable->active
            || ! $this->preferences->decideForNotification($notifiable, $notification, self::class)->allowed
            || ! $notifiable->browserPushSubscriptions()->enabled()->exists()
            || ! $this->configured()
        ) {
            return;
        }

        $message = $notification->toBrowserPush($notifiable);

        $notificationId = (string) $notification->id;
        $payload = $message->payload($notificationId);

        DB::afterCommit(function () use ($notifiable, $notificationId, $payload): void {
            SendBrowserPushNotification::dispatch(
                $notifiable->id,
                $notificationId,
                $payload,
            )->afterCommit();
        });
    }

    private function configured(): bool
    {
        return filled(config('webpush.vapid.subject'))
            && filled(config('webpush.vapid.public_key'))
            && filled(config('webpush.vapid.private_key'));
    }
}
