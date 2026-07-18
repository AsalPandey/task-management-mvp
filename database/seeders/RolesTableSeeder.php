<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ([
            'manager' => 'Manager',
            'project_manager' => 'Project Manager',
            'team_member' => 'Team Member',
        ] as $name => $label) {
            Role::query()->updateOrCreate(
                ['name' => $name],
                ['label' => $label],
            );
        }
    }
}
