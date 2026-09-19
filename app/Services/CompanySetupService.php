<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionsTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class CompanySetupService
{
    public function setup(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $usersExist = User::query()->lockForUpdate()->exists();
            if ($usersExist) {
                $existing = User::query()
                    ->where('email', $data['email'])
                    ->with('role')
                    ->first();

                if ($existing?->hasRole('manager') && $existing->isActive() && CompanySetting::isInstalled()) {
                    return $existing;
                }

                throw ValidationException::withMessages([
                    'email' => 'The application is already installed. Existing accounts and credentials were not changed.',
                ]);
            }

            app(RolesTableSeeder::class)->run();
            app(PermissionsTableSeeder::class)->run();

            $managerRole = Role::query()->where('name', 'manager')->firstOrFail();

            CompanySetting::query()->updateOrCreate(
                ['id' => CompanySetting::query()->value('id') ?: 1],
                [
                    'company_name' => $data['company_name'],
                    'timezone' => $data['timezone'] ?? 'Asia/Kathmandu',
                    'app_url' => $data['app_url'] ?? config('app.url'),
                    'installed_at' => now(),
                    'settings' => [
                        'notification_defaults' => [
                            'task_assigned' => true,
                            'task_completed' => true,
                            'deadline_reminder' => true,
                            'team_updates' => true,
                        ],
                    ],
                ],
            );

            return User::query()->create([
                'email' => $data['email'],
                'name' => $data['name'],
                'password' => Hash::make($data['password']),
                'role_id' => $managerRole->id,
                'active' => true,
                'timezone' => $data['timezone'] ?? 'Asia/Kathmandu',
                'email_verified_at' => now(),
                'notification_preferences' => [
                    'task_assigned' => true,
                    'task_completed' => true,
                    'deadline_reminder' => true,
                    'team_updates' => true,
                ],
            ]);
        });
    }
}
