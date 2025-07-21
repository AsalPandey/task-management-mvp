<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UsersTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = \App\Models\Role::pluck('id', 'name');
        \App\Models\User::insert([
            [
                'name' => 'Manager User',
                'email' => 'manager@example.com',
                'password' => bcrypt('password'),
                'role_id' => $roles['manager'],
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Team Leader User',
                'email' => 'teamleader@example.com',
                'password' => bcrypt('password'),
                'role_id' => $roles['teamleader'],
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Team Member User',
                'email' => 'teammember@example.com',
                'password' => bcrypt('password'),
                'role_id' => $roles['team_member'],
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
