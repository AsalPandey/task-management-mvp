<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AccountSessionSecurity;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class EnsureCurrentAccountSession
{
    public function handle(Request $request, Closure $next)
    {
        $guard = Auth::guard('web');
        $user = $guard->user();
        // Only session-authenticated requests have this key; stateless guards do not.
        if ($user && $request->session()->has($guard->getName())) {
            $current = User::find($user->id);
            $saved = $request->session()->get(AccountSessionSecurity::SESSION_KEY);
            if (! $current || ! $current->isActive() || ! is_string($saved)
                || ! hash_equals(app(AccountSessionSecurity::class)->fingerprint($current), $saved)) {
                $guard->logoutCurrentDevice();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                throw new AuthenticationException('Please sign in again.', ['web']);
            }
            $guard->setUser($current);
        }

        return $next($request);
    }
}
