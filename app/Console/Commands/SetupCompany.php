<?php

namespace App\Console\Commands;

use App\Services\CompanySetupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class SetupCompany extends Command
{
    protected $signature = 'app:setup-company
        {--company= : Company name}
        {--name= : First manager name}
        {--email= : First manager email}
        {--password= : First manager password}
        {--timezone=Asia/Kathmandu : Company timezone}
        {--app-url= : Public application URL}
        {--force : Re-run setup even when users already exist}';

    protected $description = 'Create the initial company profile, roles, permissions, and first manager account.';

    public function handle(CompanySetupService $setup): int
    {
        $data = [
            'company_name' => $this->option('company') ?: $this->ask('Company name'),
            'name' => $this->option('name') ?: $this->ask('First manager name'),
            'email' => $this->option('email') ?: $this->ask('First manager email'),
            'password' => $this->option('password') ?: $this->secret('First manager password'),
            'timezone' => $this->option('timezone') ?: 'Asia/Kathmandu',
            'app_url' => $this->option('app-url') ?: config('app.url'),
        ];

        $validator = Validator::make($data, [
            'company_name' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
            'timezone' => ['required', 'timezone'],
            'app_url' => ['nullable', 'url'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = $setup->setup($validator->validated(), (bool) $this->option('force'));
        $this->info("Company setup complete. First manager: {$user->email}");

        return self::SUCCESS;
    }
}
