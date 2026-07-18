<?php

namespace App\Http\Middleware;

use App\Models\CompanySetting;
use Closure;
use Illuminate\Http\Request;
use Sentry\State\Scope;
use Symfony\Component\HttpFoundation\Response;

use function Sentry\configureScope;

class SetSentryContext
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            configureScope(function (Scope $scope) use ($request): void {
                $user = $request->user();
                if ($user) {
                    $scope->setUser([
                        'id' => (string) $user->id,
                        'email' => $user->email,
                        'role' => $user->role?->name,
                    ]);
                }

                $company = CompanySetting::current();
                if ($company) {
                    $scope->setTag('company', (string) $company->id);
                    $scope->setContext('company', [
                        'id' => $company->id,
                        'name' => $company->company_name,
                        'timezone' => $company->timezone,
                    ]);
                }
            });
        } catch (\Throwable) {
            // Sentry context must never block normal request handling.
        }

        return $next($request);
    }
}
