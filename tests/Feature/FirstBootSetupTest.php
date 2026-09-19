<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FirstBootSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_company_command_creates_company_roles_permissions_and_first_manager(): void
    {
        config([
            'company-bootstrap.company_name' => 'Acme Nepal',
            'company-bootstrap.manager_name' => 'First Manager',
            'company-bootstrap.manager_email' => 'manager@acme.test',
            'company-bootstrap.manager_password' => 'A-unique-install-password-123!',
        ]);

        $this->artisan('app:setup-company', [
            '--timezone' => 'Asia/Kathmandu',
            '--app-url' => 'https://acme.test',
        ])->assertSuccessful();

        $this->assertDatabaseHas('company_settings', [
            'company_name' => 'Acme Nepal',
            'timezone' => 'Asia/Kathmandu',
            'app_url' => 'https://acme.test',
        ]);
        $this->assertNotNull(CompanySetting::current()?->installed_at);
        $this->assertDatabaseHas('roles', ['name' => 'manager']);
        $this->assertDatabaseHas('roles', ['name' => 'project_manager']);
        $this->assertDatabaseHas('roles', ['name' => 'team_member']);
        $manager = User::where('email', 'manager@acme.test')->first();
        $this->assertTrue($manager?->hasRole('manager'));
        $this->assertTrue(Hash::check('A-unique-install-password-123!', $manager->password));
    }

    public function test_non_interactive_setup_fails_clearly_when_bootstrap_secrets_are_absent(): void
    {
        config([
            'company-bootstrap.company_name' => null,
            'company-bootstrap.manager_name' => null,
            'company-bootstrap.manager_email' => null,
            'company-bootstrap.manager_password' => null,
        ]);

        $this->artisan('app:setup-company', ['--no-interaction' => true])
            ->expectsOutputToContain('company name field is required')
            ->expectsOutputToContain('password field is required')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_bootstrap_rerun_does_not_duplicate_or_reset_the_initial_manager(): void
    {
        config([
            'company-bootstrap.company_name' => 'Acme Nepal',
            'company-bootstrap.manager_name' => 'First Manager',
            'company-bootstrap.manager_email' => 'manager@acme.test',
            'company-bootstrap.manager_password' => 'Original-unique-password-123!',
        ]);

        $this->artisan('app:setup-company', ['--no-interaction' => true])->assertSuccessful();
        $originalHash = User::query()->sole()->password;

        config([
            'company-bootstrap.company_name' => 'Replacement Company Must Not Apply',
            'company-bootstrap.manager_name' => 'Replacement Name Must Not Apply',
            'company-bootstrap.manager_password' => 'Replacement-password-must-not-apply!',
        ]);
        $this->artisan('app:setup-company', ['--no-interaction' => true])->assertSuccessful();

        $this->assertDatabaseCount('users', 1);
        $this->assertSame($originalHash, User::query()->sole()->password);
        $this->assertTrue(Hash::check('Original-unique-password-123!', User::query()->sole()->password));
        $this->assertFalse(Hash::check('Replacement-password-must-not-apply!', User::query()->sole()->password));
        $this->assertSame('First Manager', User::query()->sole()->name);
        $this->assertSame('Acme Nepal', CompanySetting::query()->sole()->company_name);
    }

    public function test_web_installer_requires_setup_token_and_is_disabled_after_install(): void
    {
        config(['app.setup_token' => 'secure-token']);

        $this->post('/setup', [
            'setup_token' => 'wrong-token',
            'company_name' => 'Acme Nepal',
            'name' => 'First Manager',
            'email' => 'manager@acme.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'Asia/Kathmandu',
            'app_url' => 'https://acme.test',
        ])->assertNotFound();

        $this->post('/setup', [
            'setup_token' => 'secure-token',
            'company_name' => 'Acme Nepal',
            'name' => 'First Manager',
            'email' => 'manager@acme.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'timezone' => 'Asia/Kathmandu',
            'app_url' => 'https://acme.test',
        ])->assertRedirect(route('login'));

        $this->assertSame(1, User::count());
        $this->assertTrue(Role::where('name', 'manager')->exists());
        $this->get('/setup')->assertNotFound();
    }
}
