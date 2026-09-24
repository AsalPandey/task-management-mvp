<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Support\BrowserPushMessageFactory;
use App\ValueObjects\BrowserPushMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class PwaWebPushFrontendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_manifest_and_service_worker_use_local_assets_and_safe_static_caching(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);
        $worker = file_get_contents(public_path('service-worker.js'));

        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('Task Management MVP', $manifest['name']);
        $this->assertSame('./', $manifest['scope']);
        $this->assertSame('dashboard', $manifest['start_url']);
        $this->assertNotEmpty($manifest['icons']);
        $this->assertTrue(collect($manifest['icons'])->every(
            fn (array $icon): bool => str_starts_with($icon['src'], 'icons/')
        ));

        $this->assertStringContainsString("request.method !== 'GET'", $worker);
        $this->assertStringContainsString("['style', 'script', 'image', 'font']", $worker);
        $this->assertStringNotContainsString("'document'", $worker);
        $this->assertStringContainsString('key.startsWith(CACHE_PREFIX)', $worker);
        $this->assertStringNotContainsString('keys.filter(key => key !== CACHE_VERSION)', $worker);
        $this->assertStringContainsString('candidate.origin === self.location.origin', $worker);
        $this->assertStringContainsString("appPath('notifications/all')", $worker);
    }

    public function test_layouts_reference_pwa_metadata_and_settings_expose_current_device_controls(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('rel="manifest"', false)
            ->assertSee('name="theme-color"', false)
            ->assertSee('apple-touch-icon', false)
            ->assertSee('js/pwa.js', false)
            ->assertDontSee('data-pwa-dialog', false);

        $user = User::factory()->create([
            'role_id' => Role::query()->where('name', 'team_member')->value('id'),
        ]);

        $this->actingAs($user)
            ->get(route('settings'))
            ->assertOk()
            ->assertSee('data-user-id="'.$user->id.'"', false)
            ->assertSee('data-push-status', false)
            ->assertSee('Enable Notifications')
            ->assertSee('Send Test Notification')
            ->assertSee('Disable This Device')
            ->assertSee('data-pwa-open', false)
            ->assertSee('data-pwa-dialog', false)
            ->assertSee('Having trouble installing?')
            ->assertSee('Disabling this device does not')
            ->assertSee('disable your other devices.');
    }

    public function test_permission_is_requested_only_by_the_explicit_enable_action(): void
    {
        $script = file_get_contents(public_path('js/pwa.js'));
        $enableStart = strpos($script, 'async function enable()');
        $permissionRequest = strpos($script, 'Notification.requestPermission()');
        $domReady = strpos($script, "document.addEventListener('DOMContentLoaded'");

        $this->assertIsInt($enableStart);
        $this->assertIsInt($permissionRequest);
        $this->assertIsInt($domReady);
        $this->assertGreaterThan($enableStart, $permissionRequest);
        $this->assertLessThan($domReady, $permissionRequest);
        $this->assertSame(1, substr_count($script, 'Notification.requestPermission()'));
    }

    public function test_frontend_handles_support_permission_device_privacy_and_install_states(): void
    {
        $script = file_get_contents(public_path('js/pwa.js'));

        foreach ([
            "'serviceWorker' in navigator",
            "'PushManager' in window",
            "'Notification' in window",
            "Notification.permission === 'denied'",
            "render('unsupported')",
            "render('blocked')",
            "render('enabled')",
            "render('stale')",
            "'DELETE'",
            'form[action$="/logout"]',
            'previousUser !== userId',
            'beforeinstallprompt',
            'task-management.install-dismissed-at',
            'task-management.installed-at',
            'navigator.userAgentData?.mobile === true',
            "window.matchMedia('(display-mode: standalone)')",
            'installCooldownMs = 30 * 24 * 60 * 60 * 1000',
            "installButton.textContent = installPrompt ? 'Install App' : 'View Installation Steps'",
            "window.addEventListener('appinstalled'",
            'Open this page in Safari',
            'If this desktop browser supports installation',
            'Notification.requestPermission()',
        ] as $expected) {
            $this->assertStringContainsString($expected, $script);
        }

        $this->assertStringNotContainsString('window.innerWidth', $script);
        $this->assertLessThan(
            strpos($script, "document.addEventListener('DOMContentLoaded'"),
            strpos($script, "window.addEventListener('beforeinstallprompt'"),
        );
    }

    public function test_pwa_and_push_paths_respect_an_application_subdirectory(): void
    {
        URL::forceRootUrl('https://example.test/task-management');

        try {
            $message = new BrowserPushMessage(
                title: 'Scoped notification',
                body: 'Safe body',
                type: 'task_updated',
                targetPath: 'https://attacker.example/',
            );
            $payload = $message->payload((string) Str::uuid());
            $taskMessage = BrowserPushMessageFactory::fromNotificationPayload([
                'type' => 'task_updated',
                'task_id' => 42,
                'message' => 'A scoped task changed.',
            ])->payload((string) Str::uuid());

            $this->assertSame('/task-management/notifications/all', $payload['data']['target']);
            $this->assertSame('/task-management/icons/pwa-192.png', $payload['icon']);
            $this->assertSame('/task-management/tasks#task-42', $taskMessage['data']['target']);
            $this->assertStringContainsString('APP_BASE_PATH', file_get_contents(public_path('service-worker.js')));
            $this->assertStringContainsString('serviceWorkerScope', file_get_contents(public_path('js/pwa.js')));
        } finally {
            URL::forceRootUrl(config('app.url'));
        }
    }

    public function test_notification_deep_link_does_not_bypass_normal_task_visibility(): void
    {
        $manager = $this->userWithRole('manager');
        $projectManager = $this->userWithRole('project_manager');
        $assignee = $this->userWithRole('team_member');
        $outsider = $this->userWithRole('team_member');
        $project = Project::factory()->create(['project_manager_id' => $projectManager->id]);
        $project->members()->attach($assignee->id);
        $task = Task::query()->create([
            'project_id' => $project->id,
            'title' => 'Push-only private task',
            'description' => 'Must remain scoped.',
            'assignee_id' => $assignee->id,
            'reviewer_id' => $projectManager->id,
            'created_by' => $manager->id,
            'assigned_by' => $manager->id,
            'status' => 'not_started',
            'priority' => 'Medium',
            'progress' => 0,
        ]);

        $target = route('tasks', ['status' => 'not_started'])."#task-{$task->id}";

        $this->actingAs($outsider)
            ->get($target)
            ->assertOk()
            ->assertDontSee('Push-only private task');

        $this->actingAs($manager)
            ->get($target)
            ->assertOk()
            ->assertSee('Push-only private task');
    }

    private function userWithRole(string $role): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('name', $role)->value('id'),
        ]);
    }
}
