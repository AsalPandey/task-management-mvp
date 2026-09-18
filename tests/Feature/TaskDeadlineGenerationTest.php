<?php

namespace Tests\Feature;

use App\Enums\TaskState;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskRevisionCycle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TaskDeadlineGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Notification::fake();
    }

    public function test_fresh_execution_generation_is_retained_by_start_hold_and_resume(): void
    {
        $f = $this->fixtures();
        $initial = $this->fingerprint($f['task']);
        $this->assertSame('execution', $f['task']->activeDeadlineGeneration()?->kind);
        $this->assertFalse($f['task']->deadlineReminderWasSentForActiveGeneration());

        $this->actingAs($f['assignee'])->postJson(route('tasks.start', $f['task']))->assertOk();
        $this->assertSame($initial, $this->fingerprint($f['task']->fresh()));

        $this->postJson(route('tasks.hold', $f['task']), ['reason' => 'Waiting'])->assertOk();
        $this->assertSame($initial, $this->fingerprint($f['task']->fresh()));

        $this->postJson(route('tasks.resume', $f['task']))->assertOk();
        $this->assertSame($initial, $this->fingerprint($f['task']->fresh()));
    }

    public function test_execution_deadline_and_owning_assignee_changes_rearm_but_unrelated_edits_do_not(): void
    {
        $f = $this->fixtures();
        $task = $f['task'];
        $task->markDeadlineReminderSentForActiveGeneration();
        $consumed = $this->fingerprint($task->fresh());

        $this->actingAs($f['manager'])->putJson(route('tasks.update', $task), ['title' => 'Renamed'])->assertOk();
        $this->assertSame($consumed, $this->fingerprint($task->fresh()));
        $this->assertTrue($task->fresh()->deadlineReminderWasSentForActiveGeneration());

        $this->postJson(route('tasks.deadline.change', [$task, 'execution']), [
            'due_date' => now(config('app.timezone'))->addDays(8)->toDateString(),
        ])->assertOk();
        $this->assertNotSame($consumed, $this->fingerprint($task->fresh()));
        $this->assertFalse($task->fresh()->deadlineReminderWasSentForActiveGeneration());

        $task->markDeadlineReminderSentForActiveGeneration();
        $beforeReassignment = $this->fingerprint($task->fresh());
        $this->putJson(route('tasks.update', $task), ['assignee_id' => $f['other_assignee']->id])->assertOk();
        $this->assertNotSame($beforeReassignment, $this->fingerprint($task->fresh()));
        $this->assertFalse($task->fresh()->deadlineReminderWasSentForActiveGeneration());
    }

    public function test_submission_review_start_revision_start_and_resubmission_follow_generation_rules(): void
    {
        $f = $this->fixtures(TaskState::InProgress);
        $task = $f['task'];
        $execution = $this->fingerprint($task);
        $task->markDeadlineReminderSentForActiveGeneration();

        $this->actingAs($f['assignee'])->postJson(route('tasks.submit', $task))->assertOk();
        $submitted = $task->fresh();
        $review = $this->fingerprint($submitted);
        $this->assertSame('review', $submitted->activeDeadlineGeneration()?->kind);
        $this->assertNotSame($execution, $review);
        $this->assertFalse($submitted->deadlineReminderWasSentForActiveGeneration());

        $submitted->markDeadlineReminderSentForActiveGeneration();
        $this->actingAs($f['reviewer'])->postJson(route('tasks.review.start', $task))->assertOk();
        $this->assertSame($review, $this->fingerprint($task->fresh()));
        $this->assertTrue($task->fresh()->deadlineReminderWasSentForActiveGeneration());

        $this->postJson(route('tasks.revision.request', $task), [
            'formal_feedback' => 'Revise the result.',
            'revision_due_date' => now(config('app.timezone'))->addDays(4)->toDateString(),
        ])->assertOk();
        $revision = $this->fingerprint($task->fresh());
        $this->assertSame('revision', $task->fresh()->activeDeadlineGeneration()?->kind);
        $this->assertNotSame($review, $revision);
        $this->assertFalse($task->fresh()->deadlineReminderWasSentForActiveGeneration());

        $task->fresh()->markDeadlineReminderSentForActiveGeneration();
        $this->actingAs($f['assignee'])->postJson(route('tasks.revision.start', $task))->assertOk();
        $this->assertSame($revision, $this->fingerprint($task->fresh()));

        $this->postJson(route('tasks.resubmit', $task))->assertOk();
        $resubmitted = $task->fresh();
        $this->assertSame('review', $resubmitted->activeDeadlineGeneration()?->kind);
        $this->assertNotSame($revision, $this->fingerprint($resubmitted));
        $this->assertFalse($resubmitted->deadlineReminderWasSentForActiveGeneration());
    }

    public function test_repeated_revision_cycles_never_inherit_a_consumed_generation(): void
    {
        $f = $this->fixtures(TaskState::InReview);
        $task = $f['task'];

        $firstRevision = $this->requestRevision($task, $f['reviewer'], 4);
        $task->fresh()->markDeadlineReminderSentForActiveGeneration();
        $this->actingAs($f['assignee'])->postJson(route('tasks.revision.start', $task))->assertOk();
        $this->postJson(route('tasks.resubmit', $task))->assertOk();
        $firstReview = $this->fingerprint($task->fresh());
        $task->fresh()->markDeadlineReminderSentForActiveGeneration();
        $this->actingAs($f['reviewer'])->postJson(route('tasks.review.start', $task))->assertOk();

        $secondRevision = $this->requestRevision($task, $f['reviewer'], 5);
        $this->assertNotSame($firstRevision, $secondRevision);
        $this->assertNotSame($firstReview, $secondRevision);
        $this->assertFalse($task->fresh()->deadlineReminderWasSentForActiveGeneration());

        $this->actingAs($f['assignee'])->postJson(route('tasks.revision.start', $task))->assertOk();
        $this->postJson(route('tasks.resubmit', $task))->assertOk();
        $this->assertNotSame($firstReview, $this->fingerprint($task->fresh()));
        $this->assertFalse($task->fresh()->deadlineReminderWasSentForActiveGeneration());
    }

    public function test_reviewer_reassignment_rearms_only_when_review_is_active(): void
    {
        $f = $this->fixtures(TaskState::Submitted);
        $task = $f['task'];
        $task->markDeadlineReminderSentForActiveGeneration();
        $before = $this->fingerprint($task->fresh());

        $this->actingAs($f['manager'])->postJson(route('tasks.reviewer.reassign', $task), [
            'reviewer_id' => $f['other_reviewer']->id,
            'reason' => 'Review coverage changed.',
        ])->assertOk();

        $this->assertNotSame($before, $this->fingerprint($task->fresh()));
        $this->assertFalse($task->fresh()->deadlineReminderWasSentForActiveGeneration());
    }

    public function test_reopen_creates_a_fresh_revision_generation_and_final_states_are_ineligible(): void
    {
        $f = $this->fixtures(TaskState::InReview);
        $task = $f['task'];
        $review = $this->fingerprint($task);
        $task->markDeadlineReminderSentForActiveGeneration();

        $completed = $this->approveTask($task, $f['reviewer'])->fresh();
        $this->assertNull($completed->activeDeadlineGeneration());

        $reopened = $this->reopenApprovedTask($completed, $f['manager'])->fresh();
        $this->assertSame('revision', $reopened->activeDeadlineGeneration()?->kind);
        $this->assertNotSame($review, $this->fingerprint($reopened));
        $this->assertFalse($reopened->deadlineReminderWasSentForActiveGeneration());

        $reopened->forceFill(['status' => TaskState::Cancelled])->save();
        $this->assertNull($reopened->fresh()->activeDeadlineGeneration());
    }

    public function test_date_only_deadline_becomes_overdue_only_after_local_day_ends(): void
    {
        $timezone = config('app.timezone');
        $this->travelTo(now($timezone)->startOfDay()->addHours(12));
        $f = $this->fixtures(TaskState::InProgress, today($timezone)->toDateString());
        $this->assertFalse($f['task']->activeDeadlineGeneration()?->isOverdue());

        $this->travelTo(now($timezone)->addDay()->startOfDay());
        $this->assertTrue($f['task']->fresh()->activeDeadlineGeneration()?->isOverdue());
        $this->travelBack();
    }

    public function test_review_deadline_wins_after_resubmission_and_revision_deadline_cannot_be_changed_in_review(): void
    {
        $f = $this->fixtures(TaskState::Submitted);
        $task = $f['task'];
        $reviewDate = $task->review_due_date->toDateString();
        $cycle = TaskRevisionCycle::query()->create([
            'task_id' => $task->id,
            'cycle_number' => 1,
            'requested_by' => $f['reviewer']->id,
            'requested_at' => now()->subDays(2),
            'revision_due_date' => now(config('app.timezone'))->addDay()->toDateString(),
            'resubmitted_at' => now()->subHour(),
            'origin' => 'review_revision',
        ]);
        $task->forceFill([
            'active_revision_cycle_id' => $cycle->id,
            'revision_due_date' => now(config('app.timezone'))->addDay()->toDateString(),
        ])->save();

        $this->assertSame('review', $task->fresh()->activeDeadlineGeneration()?->kind);
        $this->assertSame($reviewDate, $task->fresh()->activeDeadline()?->toDateString());

        $this->actingAs($f['manager'])->postJson(route('tasks.deadline.change', [$task, 'revision']), [
            'due_date' => now(config('app.timezone'))->addDays(3)->toDateString(),
            'reason' => 'Must not replace review deadline.',
        ])->assertUnprocessable();
        $this->assertSame($reviewDate, $task->fresh()->activeDeadline()?->toDateString());
    }

    private function requestRevision(Task $task, User $reviewer, int $days): string
    {
        $this->actingAs($reviewer)->postJson(route('tasks.revision.request', $task), [
            'formal_feedback' => "Revision cycle {$days}.",
            'revision_due_date' => now(config('app.timezone'))->addDays($days)->toDateString(),
        ])->assertOk();

        return $this->fingerprint($task->fresh());
    }

    private function fingerprint(Task $task): string
    {
        return $task->activeDeadlineGeneration()?->fingerprint()
            ?? throw new \RuntimeException('Expected an active deadline generation.');
    }

    private function fixtures(
        TaskState $state = TaskState::NotStarted,
        ?string $deadline = null,
    ): array {
        $manager = $this->user('manager');
        $reviewer = $this->user('project_manager');
        $otherReviewer = $this->user('manager');
        $assignee = $this->user('team_member');
        $otherAssignee = $this->user('team_member');
        $project = Project::factory()->create(['project_manager_id' => $reviewer->id]);
        $project->members()->attach([$assignee->id, $otherAssignee->id]);
        $deadline ??= now(config('app.timezone'))->addDays(5)->toDateString();

        $task = Task::query()->create([
            'project_id' => $project->id,
            'title' => 'Deadline generation task',
            'assignee_id' => $assignee->id,
            'created_by' => $manager->id,
            'assigned_by' => $manager->id,
            'priority' => 'Medium',
            'status' => $state,
            'progress' => $state === TaskState::NotStarted ? 0 : 50,
            'due_date' => $deadline,
        ]);
        $task->forceFill([
            'reviewer_id' => $reviewer->id,
            'execution_due_date' => $deadline,
            'review_due_date' => in_array($state, [TaskState::Submitted, TaskState::InReview], true)
                ? now(config('app.timezone'))->addDays(2)->toDateString()
                : null,
            'submitted_at' => in_array($state, [TaskState::Submitted, TaskState::InReview], true)
                ? now()->subHour()
                : null,
            'review_started_at' => $state === TaskState::InReview ? now()->subMinutes(30) : null,
        ])->save();

        return [
            'manager' => $manager,
            'reviewer' => $reviewer,
            'other_reviewer' => $otherReviewer,
            'assignee' => $assignee,
            'other_assignee' => $otherAssignee,
            'project' => $project,
            'task' => $task->fresh(),
        ];
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('name', $role)->value('id'),
        ]);
    }
}
