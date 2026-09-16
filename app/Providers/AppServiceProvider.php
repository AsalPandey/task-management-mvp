<?php

namespace App\Providers;

use App\Contracts\BrowserPushTransport;
use App\Models\User;
use App\Services\MinishlinkBrowserPushTransport;
use App\Services\WebPushDestinationValidator;
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
