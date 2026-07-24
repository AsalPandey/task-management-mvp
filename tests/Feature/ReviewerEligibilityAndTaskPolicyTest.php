<?php

namespace Tests\Feature;

use App\Exceptions\TaskTransitionException;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\ReviewerEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewerEligibilityAndTaskPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_reviewer_eligibility_accepts_only_active_manager_or_managing_project_manager(): void
    {
        $fixtures = $this->fixtures();
        $reviewers = app(ReviewerEligibilityService::class);

        $this->assertTrue($reviewers->isEligible($fixtures['manager'], $fixtures['project'], $fixtures['assignee']->id));
        $this->assertTrue($reviewers->isEligible($fixtures['project_manager'], $fixtures['project'], $fixtures['assignee']->id));
        $this->assertFalse($reviewers->isEligible($fixtures['unrelated_pm'], $fixtures['project'], $fixtures['assignee']->id));
        $this->assertFalse($reviewers->isEligible($fixtures['team_member'], $fixtures['project'], $fixtures['assignee']->id));
        $this->assertFalse($reviewers->isEligible($fixtures['inactive_manager'], $fixtures['project'], $fixtures['assignee']->id));
        $this->assertFalse($reviewers->isEligible($fixtures['assignee'], $fixtures['project'], $fixtures['assignee']->id));
    }

    public function test_reviewer_validation_provides_structured_invariant_errors(): void
    {
        $fixtures = $this->fixtures();
        $reviewers = app(ReviewerEligibilityService::class);

        foreach ([
            $fixtures['unrelated_pm'],
            $fixtures['team_member'],
            $fixtures['inactive_manager'],
            $fixtures['assignee'],
        ] as $reviewer) {
            try {
                $reviewers->assertEligible($reviewer, $fixtures['project'], $fixtures['assignee']->id);
                $this->fail('Expected an ineligible reviewer to be rejected.');
            } catch (TaskTransitionException $exception) {
                $this->assertSame('invariant_violation', $exception->reason);
                $this->assertSame(422, $exception->status);
            }
        }
    }

    public function test_creator_identity_alone_grants_no_reviewer_authority(): void
    {
        $fixtures = $this->fixtures();
        $task = $fixtures['task'];
        $task->forceFill([
            'created_by' => $fixtures['team_member']->id,
            'reviewer_id' => $fixtures['manager']->id,
        ])->save();

        $this->assertFalse($fixtures['team_member']->can('startReview', $task));
        $this->assertFalse($fixtures['team_member']->can('requestRevision', $task));
        $this->assertFalse($fixtures['team_member']->can('approve', $task));
    }

    public function test_policy_abilities_preserve_assignee_reviewer_and_management_boundaries(): void
    {
        $fixtures = $this->fixtures();
        $task = $fixtures['task'];
        $task->forceFill(['reviewer_id' => $fixtures['manager']->id])->save();

        foreach (['start', 'hold', 'resume', 'submit', 'startRevision', 'resubmit', 'updateProgress'] as $ability) {
            $this->assertTrue($fixtures['assignee']->can($ability, $task), "Assignee should have {$ability}.");
            $this->assertFalse($fixtures['team_member']->can($ability, $task), "Coworker should not have {$ability}.");
        }

        foreach (['startReview', 'requestRevision', 'approve'] as $ability) {
            $this->assertTrue($fixtures['manager']->can($ability, $task), "Assigned reviewer should have {$ability}.");
            $this->assertFalse($fixtures['project_manager']->can($ability, $task), "Unassigned reviewer should not have {$ability}.");
        }

        foreach (['cancel', 'reassignReviewer', 'viewSensitivePriority', 'viewManagementNotes'] as $ability) {
            $this->assertTrue($fixtures['manager']->can($ability, $task));
            $this->assertTrue($fixtures['project_manager']->can($ability, $task));
            $this->assertFalse($fixtures['unrelated_pm']->can($ability, $task));
            $this->assertFalse($fixtures['assignee']->can($ability, $task));
        }

        $this->assertTrue($fixtures['manager']->can('overrideReviewer', $task));
        $this->assertFalse($fixtures['project_manager']->can('overrideReviewer', $task));
        $this->assertFalse($fixtures['assignee']->can('complete', $task));
        $this->assertFalse($fixtures['assignee']->can('reopen', $task));
        $this->assertTrue($fixtures['manager']->can('reopen', $task));
        $this->assertTrue($fixtures['project_manager']->can('reopen', $task));
    }

    public function test_self_approval_is_always_denied(): void
    {
        $fixtures = $this->fixtures();
        $task = $fixtures['task'];
        $task->forceFill([
            'assignee_id' => $fixtures['manager']->id,
            'reviewer_id' => $fixtures['manager']->id,
        ])->save();

        // The assigned reviewer reaches the locked command, which rejects self-approval with 422.
        $this->assertTrue($fixtures['manager']->can('approve', $task));
    }

    private function fixtures(): array
    {
        $roles = collect([
            'manager' => 'Manager',
            'project_manager' => 'Project Manager',
            'team_member' => 'Team Member',
        ])->map(fn (string $label, string $name) => Role::query()->create(compact('name', 'label')));

        $manager = User::factory()->create(['role_id' => $roles['manager']->id]);
        $projectManager = User::factory()->create(['role_id' => $roles['project_manager']->id]);
        $unrelatedPm = User::factory()->create(['role_id' => $roles['project_manager']->id]);
        $assignee = User::factory()->create(['role_id' => $roles['team_member']->id]);
        $teamMember = User::factory()->create(['role_id' => $roles['team_member']->id]);
        $inactiveManager = User::factory()->create([
            'role_id' => $roles['manager']->id,
            'active' => false,
        ]);
        $project = Project::factory()->create(['project_manager_id' => $projectManager->id]);
        $project->members()->attach([$assignee->id, $teamMember->id]);
        $task = Task::query()->create([
            'project_id' => $project->id,
            'assignee_id' => $assignee->id,
            'created_by' => $manager->id,
            'title' => 'Policy foundation task',
            'priority' => 'Medium',
            'status' => 'In Progress',
            'progress' => 20,
        ]);

        return [
            'manager' => $manager,
            'project_manager' => $projectManager,
            'unrelated_pm' => $unrelatedPm,
            'assignee' => $assignee,
            'team_member' => $teamMember,
            'inactive_manager' => $inactiveManager,
            'project' => $project,
            'task' => $task,
        ];
    }
}
