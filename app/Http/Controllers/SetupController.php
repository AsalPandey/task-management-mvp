<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use App\Models\User;
use App\Services\CompanySetupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SetupController extends Controller
{
    public function create(Request $request)
    {
        abort_unless($this->installerAllowed($request), 404);

        return view('setup.install');
    }

    public function store(Request $request, CompanySetupService $setup)
    {
        abort_unless($this->installerAllowed($request), 404);

        $data = Validator::make($request->all(), [
            'setup_token' => ['required', 'string'],
            'company_name' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'timezone' => ['required', 'timezone'],
            'app_url' => ['nullable', 'url'],
        ])->validate();

        abort_unless(hash_equals((string) config('app.setup_token'), $data['setup_token']), 404);

        $setup->setup($data);

        return redirect()->route('login')->with('status', 'Company setup complete. Log in with the manager account.');
    }

    private function installerAllowed(Request $request): bool
    {
        return ! User::query()->exists()
            && ! CompanySetting::isInstalled()
            && filled(config('app.setup_token'));
    }
}
