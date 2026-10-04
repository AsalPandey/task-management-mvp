<?php

namespace Tests\Feature;

use App\Enums\TaskState;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskUpdatedNotification;
use App\Services\ProjectMembershipService;
use App\Services\TaskAnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

class CorrectnessNeighborsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_team_search_rejects_non_scalar_input_before_the_typed_query_callback(): void
    {
        [$manager] = $this->fixtures();
        $this->actingAs($manager)->getJson('/team-management?search[]=member')->assertUnprocessable()->assertJsonValidationErrors('search');
    }

    public function test_exact_email_storage_boundary_and_case_insensitive_duplicates(): void
    {
        [$manager, $member] = $this->fixtures();
        $email = str_repeat('a', 64).'@'.str_repeat('b', 62).'.'.str_repeat('c', 62).'.'.str_repeat('d', 60).'.com';
        $this->assertSame(255, strlen($email));
        $payload = ['name' => 'Email boundary', 'email' => $email, 'password' => 'safe-password', 'role_id' => $member->role_id];
        $this->actingAs($manager)->postJson('/team-management', $payload)->assertOk();
        $this->postJson('/team-management', array_replace($payload, ['email' => strtoupper($email)]))->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/team-management', array_replace($payload, ['email' => str_replace('.com', 'd.com', $email)]))->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->putJson('/team-management/'.$member->id, array_replace($payload, ['email' => ' RENAMED@EXAMPLE.TEST ']))->assertOk();
        $this->assertSame('renamed@example.test', $member->fresh()->email);
    }

    public function test_text_character_and_byte_boundaries_keep_complete_unicode_snapshots(): void
    {
        [$manager, $member, $project, $payload] = $this->fixtures();
        $this->actingAs($manager);
        foreach ([str_repeat('a', 20000), str_repeat('😀', 15000), str_repeat('न', 20000), str_repeat('é', 10000)] as $text) {
            $this->postJson('/tasks', $payload + ['description' => $text, 'comments' => $text])->assertOk();
            $task = Task::query()->latest('id')->firstOrFail();
            $this->assertSame($text, $task->description);
            $this->putJson('/tasks/'.$task->id, ['description' => $text.'!', 'expected_version' => $task->lock_version])
                ->assertUnprocessable();
            $history = $task->histories()->firstOrFail();
            $this->assertSame($text, $history->changes['description']);
        }
        $this->postJson('/tasks', $payload + ['description' => str_repeat('😀', 15001)])->assertUnprocessable()->assertJsonValidationErrors('description');
    }

    public function test_business_reasons_fit_schema_without_truncating_private_audit_text(): void
    {
        [$manager, $member, $project, $payload] = $this->fixtures();
        $this->actingAs($manager)->postJson('/tasks', $payload)->assertOk();
        $task = Task::query()->sole();
        $reason = str_repeat('न', 5000);
        $this->postTaskTransitionJson('/tasks/'.$task->id.'/cancel', ['cancellation_reason' => $reason])->assertOk();
        $this->assertSame($reason, $task->fresh()->cancellation_reason);
        $this->assertSame(TaskState::Cancelled, $task->fresh()->machineState());
    }

    public function test_valid_large_update_retains_the_full_database_notification_payload(): void
    {
        [$manager, $member, $project, $payload] = $this->fixtures();
        $this->actingAs($manager)->postJson('/tasks', $payload)->assertOk();
        $task = Task::query()->sole();
        $text = str_repeat('😀', 15000);
        $response = $this->putJson('/tasks/'.$task->id, ['description' => $text, 'comments' => $text, 'expected_version' => $task->lock_version])->assertOk();
        $this->assertNull($response->json('notification_status'));
        $notice = $member->notifications()->where('type', TaskUpdatedNotification::class)->sole();
        $this->assertSame($text, $notice->data['changes']['description']);
        $this->assertSame($text, $notice->data['changes']['comments']);
    }

    public function test_membership_required_history_failure_rolls_back_and_effects_wait_for_outer_commit(): void
    {
        Notification::fake();
        [$manager, $member, $project] = $this->fixtures();
        $project->members()->detach($member);
        try {
            DB::transaction(function () use ($project, $member, $manager): void {
                app(ProjectMembershipService::class)->change($project, $member->id, $manager, true);
                Notification::assertNothingSent();
                throw new \RuntimeException('controlled rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('controlled rollback', $exception->getMessage());
        }
        $this->assertDatabaseCount('project_user', 0);
        $this->assertDatabaseCount('project_histories', 0);
        Notification::assertNothingSent();
        DB::transaction(function () use ($project, $member, $manager): void {
            app(ProjectMembershipService::class)->change($project, $member->id, $manager, true);
            Notification::assertNothingSent();
        });
        Notification::assertCount(1);
    }

    public function test_production_failure_response_and_wrapped_query_logs_exclude_dummy_secrets(): void
    {
        config(['app.debug' => false]);
        $handler = new TestHandler;
        Log::channel()->getLogger()->pushHandler($handler);
        $secrets = ['dummy-plaintext-password', '$2y$04$dummy-hash', 'dummy-reset-token', 'dummy-session-cookie', 'dummy-authorization', 'dummy-app-key'];
        $exception = new QueryException('mysql', 'insert into users (password, remember_token) values (?, ?)', $secrets, new \PDOException('driver echoed dummy-reset-token', 23000));
        Route::get('/r42-controlled-failure', fn () => throw $exception);
        $response = $this->withHeaders(['Authorization' => 'Bearer dummy-authorization', 'Cookie' => 'session=dummy-session-cookie'])->getJson('/r42-controlled-failure')->assertStatus(500);
        app(ExceptionHandler::class)->report(new \RuntimeException('Wrapped database operation', 0, $exception));
        $formatter = new JsonFormatter;
        $captured = implode('', array_map(fn ($record) => $formatter->format($record), $handler->getRecords()));
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $captured);
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        foreach (['SQL:', 'Connection:', base_path(), 'trace'] as $detail) {
            $this->assertStringNotContainsString($detail, $response->getContent());
        }
        $this->assertStringContainsString('query_fingerprint', $captured);
    }

    public function test_timezone_boundaries_reports_reminders_and_overdue_share_deployment_clock(): void
    {
        [$manager, $member, $project] = $this->fixtures();
        foreach (['Asia/Kathmandu', 'America/New_York'] as $timezone) {
            config(['app.timezone' => $timezone]);
            date_default_timezone_set($timezone);
            $this->travelTo(CarbonImmutable::parse('2026-10-03 23:59:59', $timezone));
            Notification::fake();
            $task = Task::query()->create(['title' => 'Timezone '.$timezone, 'project_id' => $project->id, 'assignee_id' => $member->id, 'priority' => 'Medium', 'status' => TaskState::InProgress, 'due_date' => '2026-10-03']);
            $task->forceFill(['execution_due_date' => '2026-10-03', 'created_at' => '2026-10-03 00:00:00'])->save();
            $this->assertFalse($task->activeDeadlineGeneration()->isOverdue());
            $this->assertSame('2026-10-03', $task->activeDeadline()->toDateString());
            $this->assertSame(1, app(TaskAnalyticsService::class)->report($manager, '2026-10-03', '2026-10-03')['totalTasks']);
            $tomorrow = Task::query()->create(['title' => 'Reminder '.$timezone, 'project_id' => $project->id, 'assignee_id' => $member->id, 'priority' => 'Medium', 'status' => TaskState::InProgress, 'due_date' => '2026-10-04']);
            $this->artisan('app:send-task-deadline-reminders')->assertSuccessful();
            $this->assertTrue($tomorrow->fresh()->deadlineReminderWasSentForActiveGeneration());
            $this->travelTo(CarbonImmutable::parse('2026-10-04 00:00:00', $timezone));
            $this->assertTrue($task->fresh()->activeDeadlineGeneration()->isOverdue());
            $this->artisan('app:send-overdue-task-notifications')->assertSuccessful();
            $this->assertTrue($task->fresh()->overdueNotificationWasSentForActiveGeneration());
            DB::table('task_notification_deliveries')->delete();
            $task->forceDelete();
            $tomorrow->forceDelete();
        }
        date_default_timezone_set(config('app.timezone'));
    }

    private function fixtures(): array
    {
        $manager = User::factory()->create(['role_id' => Role::where('name', 'manager')->value('id')]);
        $member = User::factory()->create(['role_id' => Role::where('name', 'team_member')->value('id')]);
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $project->members()->attach($member->id);

        return [$manager, $member, $project, ['title' => 'Neighbor', 'project_id' => $project->id, 'assignee_id' => $member->id, 'reviewer_id' => $manager->id, 'priority' => 'Medium']];
    }
}
