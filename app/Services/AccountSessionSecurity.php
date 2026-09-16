<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class AccountSessionSecurity
{
    public const SESSION_KEY = 'auth.security_fingerprint';

    public function rotating(User $user): void
    {
        if ($user->isDirty(['password', 'role_id', 'active', 'email', 'deleted_at'])) {
            // Random tokens avoid lost-increment races and join the same account UPDATE.
            $user->security_stamp = (string) Str::uuid();
            $user->remember_token = Str::random(60);
        }
    }

    public function fingerprint(User $user): string
    {
        return hash_hmac('sha256', json_encode([
            $user->id, $user->security_stamp, $user->getAuthPassword(),
            $user->role_id, (bool) $user->active, $user->email,
            $user->role?->permissions()->orderBy('permissions.id')->pluck('permissions.id')->all(),
        ]), (string) config('app.key'));
    }

    public function remember(Request $request, User $user): void
    {
        $request->session()->put(self::SESSION_KEY, $this->fingerprint($user));
    }

    public function preserveCurrent(Request $request, User $user): void
    {
        $request->session()->regenerate(true);
        $request->session()->forget('auth.password_confirmed_at');
        $this->remember($request, $user);
    }
}
