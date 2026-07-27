<?php

namespace Database\Seeders;

use App\Enums\TaskState;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class BrowserSmokeSeeder extends Seeder
{
    private const DATABASE_PREFIX = 'task_management_phase29_smoke_';

    private const USERS = [
        'manager' => [
            'name' => 'Phase 2.9A Smoke Manager',
            'email' => 'phase29a.manager@example.test',
        ],
        'project_manager' => [
            'name' => 'Phase 2.9A Smoke Project Manager',
            'email' => 'phase29a.project-manager@example.test',
        ],
        'team_member' => [
            'name' => 'Phase 2.9A Smoke Team Member',
            'email' => 'phase29a.team-member@example.test',
        ],
    ];

    public function run(): void
    {
        $this->assertDisposableEnvironment();

        $password = config('browser-smoke.password');
        Validator::make(
            ['password' => $password],
            ['password' => ['required', 'string', Password::min(16)->mixedCase()->numbers()->symbols()]],
        )->validate();

        DB::transaction(function () use ($password): void {
            $roles = Role::query()
                ->whereIn('name', array_keys(self::USERS))
                ->pluck('id', 'name');

            if ($roles->count() !== count(self::USERS)) {
                throw new RuntimeException('Run the normal roles/permissions seeders in the disposable database first.');
            }

            $users = collect(self::USERS)->mapWithKeys(function (array $attributes, string $role) use ($password, $roles) {
                $user = User::withTrashed()->updateOrCreate(
                    ['email' => $attributes['email']],
                    [
                        'name' => $attributes['name'],
                        'password' => Hash::make($password),
                        'role_id' => $roles[$role],
                        'active' => true,
                        'email_verified_at' => now(),
                        'deleted_at' => null,
                        'timezone' => 'Asia/Kathmandu',
                    ],
                );
                $user->restore();

                return [$role => $user];
            });

            $project = Project::withTrashed()->updateOrCreate(
                ['name' => 'Phase 2.9A Browser Smoke Project'],
                [
                    'description' => 'Disposable browser-only workflow verification fixture.',
                    'project_manager_id' => $users['project_manager']->id,
                    'start_date' => now()->toDateString(),
                    'end_date' => now()->addMonth()->toDateString(),
                    'status' => 'active',
                    'color' => '#4f8cff',
                    'deleted_at' => null,
                ],
            );
            $project->restore();
            $project->members()->syncWithoutDetaching([
                $users['team_member']->id => ['added_by' => $users['manager']->id],
            ]);

            $task = Task::withTrashed()->firstOrNew([
                'project_id' => $project->id,
                'title' => 'Phase 2.9A full workflow smoke task',
            ]);
            $task->forceFill([
                'description' => 'Use this disposable task for the complete browser-smoke lifecycle.',
                'assignee_id' => $users['team_member']->id,
                'reviewer_id' => $users['project_manager']->id,
                'created_by' => $users['manager']->id,
                'assigned_by' => $users['manager']->id,
                'priority' => 'Medium',
                'status' => TaskState::NotStarted,
                'progress' => 0,
                'start_date' => now()->toDateString(),
                'due_date' => now()->addWeek()->toDateString(),
                'execution_due_date' => now()->addWeek()->toDateString(),
                'deleted_at' => null,
            ])->save();

            $this->command?->info('Disposable browser-smoke fixtures are ready.');
            foreach (self::USERS as $attributes) {
                $this->command?->line($attributes['email']);
            }
        });
    }

    private function assertDisposableEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Browser-smoke seeding is restricted to local or testing environments.');
        }

        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $database = $connection->getDatabaseName();

        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException('Browser-smoke seeding supports only isolated SQLite or MariaDB/MySQL databases.');
        }

        $identifier = $driver === 'sqlite'
            ? pathinfo($database, PATHINFO_FILENAME)
            : $database;

        if (! str_starts_with($identifier, self::DATABASE_PREFIX)) {
            throw new RuntimeException(
                'Refusing browser-smoke seeding: the database is not a positively identified Phase 2.9A disposable database.',
            );
        }
    }
}
