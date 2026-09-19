<?php

namespace Tests\Feature;

use App\Enums\TaskState;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\TaskHistory;
use App\Models\User;
use App\Notifications\TaskReviewWorkflowNotification;
use App\Services\ReviewerEligibilityService;
use App\Services\TaskEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProjectManagerReplacementIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $companyManager;

    private User $pm1;

    private User $pm2;

    private User $member;

    private ReviewerEligibilityService $eligibilityService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $managerRoleId = Role::query()->where('name', 'manager')->value('id');
        $pmRoleId = Role::query()->where('name', 'project_manager')->value('id');
        $memberRoleId = Role::query()->where('name', 'team_member')->value('id');

        $this->companyManager = User::factory()->create(['role_id' => $managerRoleId]);
        $this->pm1 = User::factory()->create(['name' => 'PM Alpha', 'role_id' => $pmRoleId]);
        $this->pm2 = User::factory()->create(['name' => 'PM Beta', 'role_id' => $pmRoleId]);
        $this->member = User::factory()->create(['name' => 'Team Member Dev', 'role_id' => $memberRoleId]);

        $this->eligibilityService = app(ReviewerEligibilityService::class);
    }

    public function test_pm_replacement_reassigns_reviewers_across_all_active_task_states(): void
    {
        Notification::fake();

        $project = Project::factory()->create([
            'name' => 'Original Project',
            'project_manager_id' => $this->pm1->id,
            'status' => 'active',
        ]);
        $project->members()->attach([$this->pm1->id, $this->pm2->id, $this->member->id]);

        $activeStates = [
            TaskState::NotStarted->value => ['state' => TaskState::NotStarted, 'attributes' => ['progress' => 0]],
            TaskState::InProgress->value => ['state' => TaskState::InProgress, 'attributes' => ['progress' => 30, 'started_at' => now()]],
            TaskState::OnHold->value => ['state' => TaskState::OnHold, 'attributes' => ['progress' => 40, 'held_at' => now(), 'held_by' => $this->pm1->id, 'hold_reason' => 'Waiting']],
            TaskState::Submitted->value => ['state' => TaskState::Submitted, 'attributes' => ['progress' => 80, 'submitted_at' => now()]],
            TaskState::InReview->value => ['state' => TaskState::InReview, 'attributes' => ['progress' => 85, 'submitted_at' => now(), 'review_started_at' => now()]],
            TaskState::RevisionRequested->value => ['state' => TaskState::RevisionRequested, 'attributes' => ['progress' => 90, 'submitted_at' => now(), 'revision_count' => 1]],
        ];

        /** @var array<string, Task> $tasks */
        $tasks = [];
        foreach ($activeStates as $stateValue => $item) {
            $task = Task::create(array_merge([
                'project_id' => $project->id,
                'title' => 'Active in '.$item['state']->label(),
                'assignee_id' => $this->member->id,
                'status' => $item['state'],
                'priority' => 'Medium',
            ], $item['attributes']));
            $task->forceFill(['reviewer_id' => $this->pm1->id])->save();
            $tasks[$stateValue] = $task;
        }

        // Add completed and cancelled tasks to verify they are NOT touched
        $completedTask = Task::create([
            'project_id' => $project->id,
            'title' => 'Completed Task',
            'assignee_id' => $this->member->id,
            'status' => TaskState::Completed,
            'priority' => 'Medium',
        ]);
        $completedTask->forceFill(['reviewer_id' => $this->pm1->id, 'completed_at' => now()])->save();

        $cancelledTask = Task::create([
            'project_id' => $project->id,
            'title' => 'Cancelled Task',
            'assignee_id' => $this->member->id,
            'status' => TaskState::Cancelled,
            'priority' => 'Medium',
        ]);
        $cancelledTask->forceFill(['reviewer_id' => $this->pm1->id, 'cancelled_at' => now()])->save();

        // Perform PM replacement from PM1 to PM2 as company manager
        $response = $this->actingAs($this->companyManager)->putJson("/projects/{$project->id}", [
            'name' => 'Original Project Updated',
            'status' => 'active',
            'project_manager_id' => $this->pm2->id,
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        // Verify project manager was updated
        $this->assertSame($this->pm2->id, $project->fresh()->project_manager_id);

        // Verify every active task now has PM2 as reviewer and reviewer is eligible
        foreach ($tasks as $stateValue => $task) {
            $fresh = $task->fresh();
            $this->assertSame(
                $this->pm2->id,
                $fresh->reviewer_id,
                "Task in state {$stateValue} should have PM2 as reviewer.",
            );
            $this->assertSame(2, $fresh->lock_version, "Task in state {$stateValue} must advance exactly one version.");

            // Reviewer eligibility must be true for PM2 and false for PM1
            $this->assertTrue(
                $this->eligibilityService->isEligibleForTask($this->pm2, $fresh),
                "PM2 must be eligible for task in state {$stateValue}.",
            );
            $this->assertFalse(
                $this->eligibilityService->isEligibleForTask($this->pm1, $fresh),
                "PM1 must no longer be eligible for task in state {$stateValue}.",
            );

            // Verify TaskHistory recorded
            $this->assertTrue(
                TaskHistory::where('task_id', $task->id)
                    ->where('action', 'reviewer_reassigned')
                    ->where('user_id', $this->companyManager->id)
                    ->exists(),
                "Task in state {$stateValue} must record reviewer_reassigned in history.",
            );

            // Verify TaskEvent recorded
            $this->assertTrue(
                TaskEvent::where('task_id', $task->id)
                    ->where('event_type', TaskEventRecorder::REVIEWER_REASSIGNED)
                    ->where('actor_id', $this->companyManager->id)
                    ->exists(),
                "Task in state {$stateValue} must record reviewer_reassigned event.",
            );
        }

        // Completed and Cancelled tasks must retain their original reviewer (attribution preserved)
        $this->assertSame($this->pm1->id, $completedTask->fresh()->reviewer_id);
        $this->assertSame($this->pm1->id, $cancelledTask->fresh()->reviewer_id);
        $this->assertFalse(
            TaskHistory::where('task_id', $completedTask->id)
                ->where('action', 'reviewer_reassigned')
                ->exists(),
        );
        $this->assertFalse(
            TaskHistory::where('task_id', $cancelledTask->id)
                ->where('action', 'reviewer_reassigned')
                ->exists(),
        );

        // Verify notifications were sent for active tasks
        Notification::assertSentTo($this->pm2, TaskReviewWorkflowNotification::class);
    }

    public function test_self_review_conflict_blocks_pm_replacement_with_422(): void
    {
        $project = Project::factory()->create([
            'project_manager_id' => $this->pm1->id,
            'status' => 'active',
        ]);
        $project->members()->attach([$this->pm1->id, $this->pm2->id]);

        // Task is assigned to PM2, with PM1 as reviewer
        $task = Task::create([
            'project_id' => $project->id,
            'title' => 'Assigned to PM2',
            'assignee_id' => $this->pm2->id,
            'status' => TaskState::InProgress,
            'priority' => 'High',
        ]);
        $task->forceFill(['reviewer_id' => $this->pm1->id])->save();

        // Attempt to replace PM1 with PM2: should fail because PM2 cannot review their own task
        $response = $this->actingAs($this->companyManager)->putJson("/projects/{$project->id}", [
            'name' => $project->name,
            'status' => 'active',
            'project_manager_id' => $this->pm2->id,
        ]);

        $response->assertStatus(422);

        // Project and task remain unchanged
        $this->assertSame($this->pm1->id, $project->fresh()->project_manager_id);
        $this->assertSame($this->pm1->id, $task->fresh()->reviewer_id);
    }

    public function test_removing_pm_entirely_is_blocked_when_active_tasks_rely_on_pm(): void
    {
        $project = Project::factory()->create([
            'project_manager_id' => $this->pm1->id,
            'status' => 'active',
        ]);
        $project->members()->attach([$this->pm1->id, $this->member->id]);

        $task = Task::create([
            'project_id' => $project->id,
            'title' => 'Active task with PM reviewer',
            'assignee_id' => $this->member->id,
            'status' => TaskState::Submitted,
            'priority' => 'Medium',
        ]);
        $task->forceFill(['reviewer_id' => $this->pm1->id])->save();

        // Attempt to set project_manager_id to null
        $response = $this->actingAs($this->companyManager)->putJson("/projects/{$project->id}", [
            'name' => $project->name,
            'status' => 'active',
            'project_manager_id' => null,
        ]);

        $response->assertStatus(422);
        $this->assertSame($this->pm1->id, $project->fresh()->project_manager_id);
    }

    public function test_non_pm_changes_do_not_reassign_reviewers(): void
    {
        $project = Project::factory()->create([
            'name' => 'Original Name',
            'project_manager_id' => $this->pm1->id,
            'status' => 'active',
        ]);
        $project->members()->attach([$this->pm1->id, $this->member->id]);

        $task = Task::create([
            'project_id' => $project->id,
            'title' => 'Task',
            'assignee_id' => $this->member->id,
            'status' => TaskState::InProgress,
            'priority' => 'Medium',
        ]);
        $task->forceFill(['reviewer_id' => $this->pm1->id])->save();

        // Update project name only, keeping same PM
        $response = $this->actingAs($this->companyManager)->putJson("/projects/{$project->id}", [
            'name' => 'Renamed Project',
            'status' => 'active',
            'project_manager_id' => $this->pm1->id,
        ]);

        $response->assertOk();
        $this->assertSame('Renamed Project', $project->fresh()->name);
        $this->assertSame($this->pm1->id, $task->fresh()->reviewer_id);

        $this->assertFalse(
            TaskHistory::where('task_id', $task->id)
                ->where('action', 'reviewer_reassigned')
                ->exists(),
        );
    }

    public function test_active_task_with_independent_manager_reviewer_is_preserved(): void
    {
        $project = Project::factory()->create([
            'project_manager_id' => $this->pm1->id,
            'status' => 'active',
        ]);
        $project->members()->attach([$this->pm1->id, $this->pm2->id, $this->member->id]);

        // Task is reviewed by company manager, not by pm1
        $task = Task::create([
            'project_id' => $project->id,
            'title' => 'Reviewed by Company Manager',
            'assignee_id' => $this->member->id,
            'status' => TaskState::InProgress,
            'priority' => 'Medium',
        ]);
        $task->forceFill(['reviewer_id' => $this->companyManager->id])->save();

        // Replace PM1 with PM2
        $response = $this->actingAs($this->companyManager)->putJson("/projects/{$project->id}", [
            'name' => $project->name,
            'status' => 'active',
            'project_manager_id' => $this->pm2->id,
        ]);

        $response->assertOk();

        // The company manager remains the reviewer because they are still eligible across all projects
        $this->assertSame($this->companyManager->id, $task->fresh()->reviewer_id);
        $this->assertTrue($this->eligibilityService->isEligibleForTask($this->companyManager, $task->fresh()));
    }
}
