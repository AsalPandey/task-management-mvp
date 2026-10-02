<?php

namespace App\Providers;

use App\Contracts\BrowserPushTransport;
use App\Models\User;
use App\Services\AccountSessionSecurity;
use App\Services\MinishlinkBrowserPushTransport;
use App\Services\NotificationAccess;
use App\Services\NotificationPreferencePolicy;
use App\Services\WebPushDestinationValidator;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
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
        foreach (['setup' => [10, 1], 'task-create' => [30, 1], 'analytics-export' => [10, 1],
            'profile-update' => [10, 1], 'push-subscribe' => [30, 1], 'push-test' => [3, 10],
            'password-forgot' => [10, 1], 'password-reset' => [10, 1], 'email-verification' => [6, 1],
            'password-confirm' => [10, 1], 'password-update' => [10, 1]] as $name => [$attempts, $minutes]) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinutes($minutes, $attempts)
                ->by($request->user() ? 'user:'.$request->user()->id : 'ip:'.$request->ip()));
        }

        Event::listen(NotificationSending::class, function ($event) {
            if ($event->notifiable instanceof User && method_exists($event->notification, 'toArray')) {
                $user = $event->notifiable->fresh();
                if (! $user) {
                    return false;
                }

                $data = $event->notification->toArray($event->notifiable);
                if ((isset($data['task_id']) || isset($data['project_id']))
                    && ! app(NotificationAccess::class)->allows($user, $data)) {
                    return false;
                }

                if (! app(NotificationPreferencePolicy::class)
                    ->decideForType($user, (string) ($data['type'] ?? ''), $event->channel)
                    ->allowed) {
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
