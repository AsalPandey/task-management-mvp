<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SetSentryContext;
use App\Models\CompanySetting;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SetSentryContext::class,
        ]);
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);
        $exceptions->dontReportDuplicates();
        $exceptions->context(function () {
            try {
                return [
                    'company' => optional(CompanySetting::current())->company_name,
                ];
            } catch (Throwable) {
                return [];
            }
        });
    })->create();
