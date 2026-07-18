<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FirstBootSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_company_command_creates_company_roles_permissions_and_first_manager(): void
    {
        $this->artisan('app:setup-company', [
            '--company' => 'Acme Nepal',
            '--name' => 'First Manager',
            '--email' => 'manager@acme.test',
            '--password' => 'password123',
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
        $this->assertTrue(User::where('email', 'manager@acme.test')->first()?->hasRole('manager'));
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
