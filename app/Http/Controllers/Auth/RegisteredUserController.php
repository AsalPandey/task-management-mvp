<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): RedirectResponse
    {
        abort_unless(! User::query()->exists(), 404);
        abort_unless(filled(config('app.setup_token')), 404);

        return redirect()->route('setup.create');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(): RedirectResponse
    {
        abort(404);
    }
}
