<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\UsersTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class ProductionSeedingSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_database_seeder_creates_reference_data_without_demo_users(): void
    {
        $this->artisan('db:seed', ['--force' => true])
            ->assertSuccessful();

        $this->assertSame(0, User::query()->count());
        $this->assertSame(3, Role::query()->count());
        $this->assertGreaterThan(0, Permission::query()->count());
    }

    public function test_production_default_seed_creates_no_demo_credentials(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['demo.allow_seeding' => false, 'demo.password' => null]);

        $this->artisan('db:seed', ['--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseHas('roles', ['name' => 'manager']);
        $this->assertDatabaseHas('roles', ['name' => 'project_manager']);
        $this->assertDatabaseHas('roles', ['name' => 'team_member']);
    }

    public function test_migrate_with_seed_remains_production_safe_and_creates_reference_data(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('migrate', ['--seed' => true, '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseHas('roles', ['name' => 'manager']);
        $this->assertGreaterThan(0, Permission::query()->count());
    }

    public function test_demo_seeding_without_explicit_opt_in_fails_without_users(): void
    {
        config([
            'demo.allow_seeding' => false,
            'demo.password' => $this->validDemoPassword(),
        ]);

        try {
            app(DemoSeeder::class)->run();
            $this->fail('DemoSeeder should require explicit opt-in.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ALLOW_DEMO_SEEDING', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_production_rejects_demo_seeding_even_when_opt_in_is_enabled(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config([
            'demo.allow_seeding' => true,
            'demo.password' => $this->validDemoPassword(),
        ]);

        try {
            app(DemoSeeder::class)->run();
            $this->fail('DemoSeeder should reject production environments.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('local or testing', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_explicit_testing_demo_seed_creates_only_placeholder_accounts(): void
    {
        $password = $this->validDemoPassword();
        config([
            'demo.allow_seeding' => true,
            'demo.password' => $password,
        ]);

        app(DemoSeeder::class)->run();

        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseHas('users', ['email' => 'demo.manager@example.invalid']);
        $this->assertDatabaseHas('users', ['email' => 'demo.project-manager@example.invalid']);
        $this->assertDatabaseHas('users', ['email' => 'demo.member@example.invalid']);
        $this->assertTrue(User::query()->get()->every(
            fn (User $user) => Hash::check($password, $user->password),
        ));
    }

    public function test_invalid_demo_password_creates_no_partial_users(): void
    {
        config([
            'demo.allow_seeding' => true,
            'demo.password' => 'invalid',
        ]);

        try {
            app(DemoSeeder::class)->run();
            $this->fail('DemoSeeder should reject an invalid local password.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('users', 0);
        }
    }

    public function test_legacy_user_seeder_fails_explicitly_without_creating_users(): void
    {
        try {
            app(UsersTableSeeder::class)->run();
            $this->fail('The legacy fixed-user seeder must remain disabled.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('disabled', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_prototype_is_archived_outside_public_and_excluded_from_packages(): void
    {
        $attributes = file_get_contents(base_path('.gitattributes'));
        $prototypeReadme = file_get_contents(resource_path('prototypes/README.md'));
        $prototypeAuth = file_get_contents(resource_path('prototypes/js/auth.js'));
        $prototypeLogin = file_get_contents(resource_path('prototypes/js/login.js'));

        $this->assertDirectoryDoesNotExist(public_path('prototypes'));
        $this->assertStringContainsString('/resources/prototypes/** export-ignore', $attributes);
        exec('git check-attr export-ignore -- resources/prototypes/README.md', $attributeOutput, $attributeExitCode);
        $this->assertSame(0, $attributeExitCode);
        $this->assertStringEndsWith(': set', $attributeOutput[0] ?? '');
        $this->assertStringContainsString('must never be deployed', $prototypeReadme);
        $this->assertStringNotContainsString('localStorage', $prototypeAuth);
        $this->assertStringNotContainsString('sessionStorage', $prototypeAuth);
        $this->assertStringNotContainsString('fillCredentials', $prototypeLogin);
    }

    private function validDemoPassword(): string
    {
        return 'Aa1!'.bin2hex(random_bytes(12));
    }
}
