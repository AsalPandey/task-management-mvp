<?php

namespace Tests\Feature;

use App\Enums\TaskState;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

class TaskNotificationIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $projectManager;

    private User $member;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $managerRoleId = Role::query()->where('name', 'manager')->value('id');
        $pmRoleId = Role::query()->where('name', 'project_manager')->value('id');
        $memberRoleId = Role::query()->where('name', 'team_member')->value('id');

        $this->manager = User::factory()->create(['role_id' => $managerRoleId]);
        $this->projectManager = User::factory()->create(['role_id' => $pmRoleId]);
        $this->member = User::factory()->create(['role_id' => $memberRoleId]);

        $this->project = Project::factory()->create([
            'project_manager_id' => $this->projectManager->id,
            'status' => 'active',
        ]);
        $this->project->members()->attach([$this->projectManager->id, $this->member->id]);
    }

    public function test_task_approval_succeeds_with_delivery_failed_status_when_transport_fails(): void
    {
        Log::spy();

        $task = Task::query()->create([
            'project_id' => $this->project->id,
            'title' => 'Task awaiting approval',
            'assignee_id' => $this->member->id,
            'priority' => 'High',
            'status' => TaskState::InReview,
            'progress' => 90,
            'submitted_at' => now()->subHour(),
            'review_started_at' => now()->subMinutes(30),
        ]);
        $task->forceFill(['reviewer_id' => $this->projectManager->id])->save();

        TaskSubmission::query()->create([
            'task_id' => $task->id,
            'revision_cycle_id' => null,
            'submitted_by' => $this->member->id,
            'submitted_at' => now()->subHour(),
            'submission_note' => 'Ready for final review.',
        ]);

        Notification::shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException('Injected notification transport failure.'));

        $response = $this->actingAs($this->projectManager)->postJson(route('tasks.approve', $task), [
            'approval_comment' => 'Looks good to me.',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'notification_status' => 'delivery_failed',
        ]);

        $fresh = $task->fresh();
        $this->assertSame('Completed', $fresh->status);
        $this->assertSame(100, $fresh->progress);
        $this->assertNotNull($fresh->completed_at);
        $this->assertSame($this->projectManager->id, $fresh->completed_by);

        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context) use ($task) {
            return str_contains($message, 'Task operation succeeded but notification dispatch failed.')
                && ($context['task_id'] ?? null) === $task->id;
        });
    }

    public function test_task_creation_succeeds_with_delivery_failed_and_is_idempotent_on_retry(): void
    {
        Log::spy();

        Notification::shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException('Injected notification transport failure on creation.'));

        $correlationId = 'idempotent-test-create-'.uniqid();

        $payload = [
            'title' => 'Idempotent Task Creation',
            'description' => 'Test task with correlation ID',
            'project_id' => $this->project->id,
            'assignee_id' => $this->member->id,
            'reviewer_id' => $this->projectManager->id,
            'priority' => 'Medium',
        ];

        // First attempt: notification fails, but task is created and 200 is returned with delivery_failed
        $response = $this->actingAs($this->manager)
            ->withHeader('X-Correlation-ID', $correlationId)
            ->postJson(route('tasks.store'), $payload);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'notification_status' => 'delivery_failed',
        ]);

        $createdTaskId = $response->json('task.id');
        $this->assertNotNull($createdTaskId);
        $this->assertDatabaseHas('tasks', ['id' => $createdTaskId, 'title' => 'Idempotent Task Creation']);

        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context) use ($createdTaskId) {
            return str_contains($message, 'Task operation succeeded but notification dispatch failed.')
                && ($context['task_id'] ?? null) === $createdTaskId;
        });

        // Second attempt with exact same correlation ID: returns existing task without creating duplicate
        $retryResponse = $this->actingAs($this->manager)
            ->withHeader('X-Correlation-ID', $correlationId)
            ->postJson(route('tasks.store'), $payload);

        $retryResponse->assertOk();
        $retryResponse->assertJson([
            'success' => true,
            'idempotent_replay' => true,
            'task' => ['id' => $createdTaskId],
        ]);

        $this->assertSame(1, Task::where('title', 'Idempotent Task Creation')->count());
    }

    public function test_task_transition_failure_still_returns_client_error(): void
    {
        // Task is in Not Started state; attempting to approve directly must fail with 422
        $task = Task::query()->create([
            'project_id' => $this->project->id,
            'title' => 'Task not yet submitted',
            'assignee_id' => $this->member->id,
            'priority' => 'High',
            'status' => TaskState::NotStarted,
            'progress' => 0,
        ]);
        $task->forceFill(['reviewer_id' => $this->projectManager->id])->save();

        $response = $this->actingAs($this->projectManager)->postJson(route('tasks.approve', $task), [
            'approval_comment' => 'Cannot approve yet.',
        ]);

        $response->assertStatus(422);
        $this->assertSame('Not Started', $task->fresh()->status);
    }

    public function test_task_creation_idempotency_rejects_mismatched_payload_with_conflict(): void
    {
        $correlationId = 'mismatch-test-'.uniqid();

        $originalPayload = [
            'title' => 'Original Task Title',
            'description' => 'Original description',
            'project_id' => $this->project->id,
            'assignee_id' => $this->member->id,
            'reviewer_id' => $this->projectManager->id,
            'priority' => 'Medium',
        ];

        $firstResponse = $this->actingAs($this->manager)
            ->withHeader('X-Correlation-ID', $correlationId)
            ->postJson(route('tasks.store'), $originalPayload);

        $firstResponse->assertOk();

        // Attempting to send a different payload with the same correlation ID must return 409 Conflict
        $alteredPayload = array_merge($originalPayload, [
            'title' => 'Completely Different Task Title',
        ]);

        $conflictResponse = $this->actingAs($this->manager)
            ->withHeader('X-Correlation-ID', $correlationId)
            ->postJson(route('tasks.store'), $alteredPayload);

        $conflictResponse->assertStatus(409);
        $conflictResponse->assertJson([
            'message' => 'Idempotency key collision with mismatched payload.',
        ]);
    }

    public function test_task_creation_idempotency_rejects_cross_user_correlation_reuse_with_conflict(): void
    {
        $correlationId = 'cross-user-test-'.uniqid();

        $payload = [
            'title' => 'User A Task',
            'description' => 'Created by user A',
            'project_id' => $this->project->id,
            'assignee_id' => $this->member->id,
            'reviewer_id' => $this->projectManager->id,
            'priority' => 'High',
        ];

        $firstResponse = $this->actingAs($this->manager)
            ->withHeader('X-Correlation-ID', $correlationId)
            ->postJson(route('tasks.store'), $payload);

        $firstResponse->assertOk();

        // Another manager sends the same correlation ID
        $anotherManager = User::factory()->create(['role_id' => $this->manager->role_id]);

        $crossUserResponse = $this->actingAs($anotherManager)
            ->withHeader('X-Correlation-ID', $correlationId)
            ->postJson(route('tasks.store'), $payload);

        $crossUserResponse->assertStatus(409);
        $crossUserResponse->assertJson([
            'message' => 'The provided correlation ID is already associated with another request.',
        ]);
    }
}
