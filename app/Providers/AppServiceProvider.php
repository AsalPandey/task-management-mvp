<?php

namespace App\Providers;

use App\Contracts\BrowserPushTransport;
use App\Models\User;
use App\Services\AccountSessionSecurity;
use App\Services\MinishlinkBrowserPushTransport;
use App\Services\NotificationAccess;
use App\Services\WebPushDestinationValidator;
use Illuminate\Auth\Events\Login;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(BrowserPushTransport::class, MinishlinkBrowserPushTransport::class);
        $this->app->singleton(WebPushDestinationValidator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(NotificationSending::class, function ($event) {
            if ($event->notifiable instanceof User && method_exists($event->notification, 'toArray')) {
                $data = $event->notification->toArray($event->notifiable);
                if ((isset($data['task_id']) || isset($data['project_id']))
                    && ! app(NotificationAccess::class)->allows($event->notifiable->fresh(), $data)) {
                    return false;
                }
            }
        });
        User::updating(fn (User $user) => app(AccountSessionSecurity::class)->rotating($user));
        Event::listen(Login::class, function ($event): void {
            if ($event->guard === 'web' && request()->hasSession()) {
                app(AccountSessionSecurity::class)->remember(request(), $event->user);
            }
        });

        User::updated(function (User $user): void {
            if ($user->wasChanged('active') && ! $user->active) {
                $user->browserPushSubscriptions()->enabled()->update([
                    'disabled_at' => now(),
                    'revoked_at' => now(),
                ]);
            }
        });

        User::deleted(function (User $user): void {
            $user->browserPushSubscriptions()->enabled()->update([
                'disabled_at' => now(),
                'revoked_at' => now(),
            ]);
        });
    }
}
