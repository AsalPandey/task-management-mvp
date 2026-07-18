<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class PermissionsTableSeeder extends Seeder
{
    private const PERMISSIONS = [
        'manage.company' => 'Manage company setup and global settings',
        'manage.users' => 'Create, edit, deactivate, and assign roles to users',
        'manage.projects' => 'Create and manage projects',
        'manage.project.members' => 'Add and remove project members',
        'manage.tasks' => 'Create, update, delete, and assign tasks',
        'update.own.tasks' => 'Update progress on assigned tasks',
        'view.analytics' => 'View team and project analytics',
        'view.own.analytics' => 'View personal analytics',
        'manage.notifications' => 'Manage own notifications',
        'manage.backups' => 'Run backup checks and smoke tests',
    ];

    private const ROLE_PERMISSIONS = [
        'manager' => [
            'manage.company',
            'manage.users',
            'manage.projects',
            'manage.project.members',
            'manage.tasks',
            'update.own.tasks',
            'view.analytics',
            'view.own.analytics',
            'manage.notifications',
            'manage.backups',
        ],
        'project_manager' => [
            'manage.projects',
            'manage.project.members',
            'manage.tasks',
            'update.own.tasks',
            'view.analytics',
            'view.own.analytics',
            'manage.notifications',
        ],
        'team_member' => [
            'update.own.tasks',
            'view.own.analytics',
            'manage.notifications',
        ],
    ];

    public function run(): void
    {
        $permissions = collect(self::PERMISSIONS)->mapWithKeys(function (string $description, string $name) {
            $permission = Permission::query()->updateOrCreate(
                ['name' => $name],
                ['description' => $description],
            );

            return [$name => $permission->id];
        });

        foreach (self::ROLE_PERMISSIONS as $roleName => $permissionNames) {
            $role = Role::query()->where('name', $roleName)->first();
            if (! $role) {
                continue;
            }

            $role->permissions()->sync(
                collect($permissionNames)
                    ->map(fn (string $name) => $permissions[$name])
                    ->all()
            );
        }
    }
}
