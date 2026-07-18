<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class DemoSeeder extends Seeder
{
    private const USERS = [
        ['name' => 'Demo Manager', 'email' => 'demo.manager@example.invalid', 'role' => 'manager'],
        ['name' => 'Demo Project Manager', 'email' => 'demo.project-manager@example.invalid', 'role' => 'project_manager'],
        ['name' => 'Demo Team Member', 'email' => 'demo.member@example.invalid', 'role' => 'team_member'],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo seeding is restricted to local or testing environments.');
        }

        if (config('demo.allow_seeding') !== true) {
            throw new RuntimeException('Demo seeding requires ALLOW_DEMO_SEEDING=true.');
        }

        $password = config('demo.password');
        Validator::make(
            ['password' => $password],
            ['password' => ['required', 'string', Password::min(16)->mixedCase()->numbers()->symbols()]],
        )->validate();

        DB::transaction(function () use ($password): void {
            app(RolesTableSeeder::class)->run();
            app(PermissionsTableSeeder::class)->run();

            $roles = Role::query()->pluck('id', 'name');

            foreach (self::USERS as $demoUser) {
                User::query()->updateOrCreate(
                    ['email' => $demoUser['email']],
                    [
                        'name' => $demoUser['name'],
                        'password' => Hash::make($password),
                        'role_id' => $roles[$demoUser['role']],
                        'active' => true,
                        'email_verified_at' => now(),
                    ],
                );
            }
        });
    }
}
