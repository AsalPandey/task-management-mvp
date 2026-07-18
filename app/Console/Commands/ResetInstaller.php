<?php

namespace App\Console\Commands;

use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Console\Command;

class ResetInstaller extends Command
{
    protected $signature = 'app:reset-installer {--force : Reset the installed marker even when users exist}';

    protected $description = 'Reset the first-boot installer marker for controlled recovery or staging rebuilds.';

    public function handle(): int
    {
        if (User::query()->exists() && ! $this->option('force')) {
            $this->error('Users exist, so the web installer will remain disabled. Re-run with --force only during controlled recovery.');

            return self::FAILURE;
        }

        CompanySetting::query()->update(['installed_at' => null]);
        $this->info('Installer marker reset. The web installer still requires APP_SETUP_TOKEN and an empty users table.');

        return self::SUCCESS;
    }
}
