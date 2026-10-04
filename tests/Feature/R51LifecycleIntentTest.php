<?php

namespace Tests\Feature;

use App\Enums\TaskState;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskTransitionExecutor;
use App\TaskTransitions\StartTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class R51LifecycleIntentTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_lifecycle_route_rejects_missing_malformed_and_stale_intent_without_effects(): void
    {
        $this->seed();
        Notification::fake();
        $manager = User::factory()->create(['role_id' => Role::where('name', 'manager')->value('id')]);
        $member = User::factory()->create(['role_id' => Role::where('name', 'team_member')->value('id')]);
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $project->members()->attach($member->id);
        $task = Task::query()->forceCreate(['title' => 'Intent work', 'priority' => 'Medium', 'project_id' => $project->id, 'assignee_id' => $member->id, 'reviewer_id' => $manager->id, 'status' => TaskState::InReview, 'lock_version' => 8]);
        $routes = [
            'start' => [], 'hold' => ['reason' => 'Waiting'], 'resume' => [], 'submit' => [],
            'review/start' => [], 'revision-request' => ['formal_feedback' => 'Correct', 'revision_due_date' => now()->addDays(3)->toDateString()],
            'revision/start' => [], 'resubmit' => [], 'approve' => [], 'approve/override' => ['override_reason' => 'Urgent'],
            'reopen' => ['reopen_reason' => 'Further work', 'revision_due_date' => now()->addDays(3)->toDateString()],
            'cancel' => ['cancellation_reason' => 'Not needed'], 'reviewer/reassign' => ['reviewer_id' => $manager->id, 'reason' => 'Reassign'],
            'deadline/execution' => ['due_date' => now()->addDays(4)->toDateString(), 'reason' => 'Extend'],
            'deadline/review' => ['due_date' => now()->addDays(4)->toDateString(), 'reason' => 'Extend'],
            'deadline/revision' => ['due_date' => now()->addDays(4)->toDateString(), 'reason' => 'Extend'],
        ];
        foreach ($routes as $path => $payload) {
            $this->app['cache']->store()->flush();
            $this->actingAs(in_array($path, ['start', 'hold', 'resume', 'submit', 'revision/start', 'resubmit'], true) ? $member : $manager);
            $url = "/tasks/{$task->id}/{$path}";
            foreach ([[], ['expected_version' => null], ['expected_version' => 0], ['expected_version' => -1], ['expected_version' => 1.5], ['expected_version' => 'garbage'], ['expected_version' => [8]], ['expected_version' => (object) ['v' => 8]], ['expected_version' => true], ['expected_version' => false], ['expected_version' => '8.0'], ['expected_version' => '184467440737095516160']] as $version) {
                $this->postJson($url, $payload + $version)->assertUnprocessable()->assertJsonValidationErrors('expected_version');
            }
            $this->postJson($url, $payload + ['expected_version' => 4])->assertConflict();
            $this->assertSame(8, $task->fresh()->lock_version);
            $this->assertSame(TaskState::InReview, $task->fresh()->machineState());
            $this->assertDatabaseCount('task_events', 0);
            $this->assertDatabaseCount('task_histories', 0);
            $this->assertDatabaseCount('task_approvals', 0);
            Notification::assertNothingSent();
        }
    }

    public function test_executor_cannot_disable_intent_check_by_omitting_context(): void
    {
        $this->seed();
        $member = User::factory()->create(['role_id' => Role::where('name', 'team_member')->value('id')]);
        $task = Task::query()->create(['title' => 'Missing internal intent', 'priority' => 'Medium', 'project_id' => Project::factory()->create()->id, 'assignee_id' => $member->id]);
        try {
            app(TaskTransitionExecutor::class)->execute($task, $member, app(StartTask::class));
            $this->fail('Executor must require explicit intent.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('expected_version', $exception->errors());
        }
        $this->assertSame(1, $task->fresh()->lock_version);
        $this->assertDatabaseCount('task_events', 0);
    }

    public function test_current_integer_and_form_digit_string_versions_execute_once(): void
    {
        $this->seed();
        $member = User::factory()->create(['role_id' => Role::where('name', 'team_member')->value('id')]);
        $manager = User::factory()->create(['role_id' => Role::where('name', 'manager')->value('id')]);
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $project->members()->attach($member->id);
        $task = Task::query()->forceCreate(['title' => 'Current intent', 'priority' => 'Medium', 'reviewer_id' => $manager->id, 'project_id' => $project->id, 'assignee_id' => $member->id, 'status' => TaskState::NotStarted]);
        $this->actingAs($member)->postJson("/tasks/{$task->id}/start", ['expected_version' => '1'])->assertOk();
        $this->postJson("/tasks/{$task->id}/hold", ['expected_version' => 2, 'reason' => 'Waiting'])->assertOk();
        $this->assertSame(3, $task->fresh()->lock_version);
    }
}
