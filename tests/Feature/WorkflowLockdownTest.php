<?php

namespace Tests\Feature;

use App\Enums\TaskState;
use App\Http\Middleware\EnsureTaskCorrelationId;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\TaskHistory;
use App\Models\TaskRevisionCycle;
use App\Models\User;
use App\Notifications\TaskReviewWorkflowNotification;
use App\Services\TaskEventRecorder;
use App\Services\TaskLifecycleService;
use App\ValueObjects\TaskOperationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WorkflowLockdownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Notification::fake();
    }

    public function test_create_and_generic_update_reject_every_lifecycle_bypass(): void
    {
        $f = $this->fixtures();

        $this->actingAs($f['manager'])
            ->postJson(route('tasks.store'), [
                'title' => 'Bypass attempt',
                'project_id' => $f['project']->id,
                'assignee_id' => $f['assignee']->id,
                'reviewer_id' => $f['reviewer']->id,
                'priority' => 'Medium',
                'status' => TaskState::InProgress->value,
                'progress' => 50,
                'approved_at' => now()->toAtomString(),
                'review_due_date' => now()->addWeek()->toDateString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status', 'progress', 'approved_at', 'review_due_date']);

        try {
            app(TaskLifecycleService::class)->create([
                'title' => 'Service bypass attempt',
                'project_id' => $f['project']->id,
                'assignee_id' => $f['assignee']->id,
                'reviewer_id' => $f['reviewer']->id,
                'priority' => 'Medium',
                'execution_due_date' => now()->addWeek()->toDateString(),
            ], $f['manager']);
            $this->fail('The public lifecycle service accepted creation-time workflow metadata.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('execution_due_date', $exception->errors());
        }

        $response = $this->postJson(route('tasks.store'), [
            'title' => 'Locked-down task',
            'project_id' => $f['project']->id,
            'assignee_id' => $f['assignee']->id,
            'reviewer_id' => $f['reviewer']->id,
            'priority' => 'Medium',
            'due_date' => now()->addDays(5)->toDateString(),
        ])->assertOk();

        $task = Task::query()->findOrFail($response->json('task.id'));
        $this->assertSame(TaskState::NotStarted, $task->machineState());
        $this->assertSame(0, $task->progress);
        $this->assertSame($f['reviewer']->id, $task->reviewer_id);

        foreach ([
            ['status' => TaskState::InProgress->value],
            ['reviewer_id' => $f['manager']->id],
            ['due_date' => now()->addWeek()->toDateString()],
            ['review_due_date' => now()->addWeek()->toDateString()],
            ['revision_due_date' => now()->addWeek()->toDateString()],
            ['hold_reason' => 'Injected'],
            ['cancelled_at' => now()->toAtomString()],
            ['task_uid' => '00000000000000000000000001'],
        ] as $payload) {
            $this->putJson(route('tasks.update', $task), $payload)->assertUnprocessable();
        }

        $this->assertSame(TaskState::NotStarted, $task->fresh()->machineState());
        $this->assertDatabaseCount('task_events', 1);
    }

    public function test_reviewer_reassignment_is_locked_audited_reasoned_and_notified(): void
    {
        $f = $this->fixtures();
        $newReviewer = $this->user('manager');
        $task = $this->task($f);
        $id = $task->id;
        $uid = $task->task_uid;

        $this->actingAs($f['project_manager'])
            ->postJson(route('tasks.reviewer.reassign', $task), [
                'reviewer_id' => $newReviewer->id,
            ])
            ->assertOk();

        $task->refresh();
        $this->assertSame($id, $task->id);
        $this->assertSame($uid, $task->task_uid);
        $this->assertSame(TaskState::NotStarted, $task->machineState());
        $this->assertSame($newReviewer->id, $task->reviewer_id);
        $this->assertDatabaseHas('task_events', [
            'task_id' => $task->id,
            'event_type' => TaskEventRecorder::REVIEWER_REASSIGNED,
        ]);
        $this->assertDatabaseHas('task_histories', [
            'task_id' => $task->id,
            'action' => 'reviewer_reassigned',
        ]);
        Notification::assertSentTo($f['reviewer'], TaskReviewWorkflowNotification::class);
        Notification::assertSentTo($newReviewer, TaskReviewWorkflowNotification::class);
        Notification::assertSentTo($f['assignee'], TaskReviewWorkflowNotification::class);

        $task->forceFill([
            'status' => TaskState::Submitted,
            'submitted_at' => now(),
        ])->save();

        $this->postJson(route('tasks.reviewer.reassign', $task), [
            'reviewer_id' => $f['reviewer']->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->postJson(route('tasks.reviewer.reassign', $task), [
            'reviewer_id' => $f['reviewer']->id,
            'reason' => 'Primary reviewer has returned.',
        ])->assertOk();

        $history = TaskHistory::query()
            ->where('task_id', $task->id)
            ->where('action', 'reviewer_reassigned')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('Primary reviewer has returned.', $history->changes['reason']);
        $this->assertSame(
            "task_histories:{$history->id}",
            TaskEvent::query()
                ->where('task_id', $task->id)
                ->where('event_type', TaskEventRecorder::REVIEWER_REASSIGNED)
                ->orderByDesc('sequence')
                ->firstOrFail()
                ->metadata['reason_reference'],
        );
    }

    public function test_deadline_commands_are_distinct_contextual_audited_and_keep_state(): void
    {
        $f = $this->fixtures();
        $task = $this->task($f, TaskState::InProgress);
        $originalState = $task->machineState();

        $this->actingAs($f['project_manager'])
            ->postJson(route('tasks.deadline.change', [$task, 'execution']), [
                'due_date' => now()->addDays(6)->toDateString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->postJson(route('tasks.deadline.change', [$task, 'execution']), [
            'due_date' => now()->addDays(6)->toDateString(),
            'reason' => 'External dependency moved.',
        ])->assertOk();

        $task->refresh();
        $this->assertSame($originalState, $task->machineState());
        $this->assertSame(now()->addDays(6)->toDateString(), $task->execution_due_date->toDateString());
        $this->assertSame($task->execution_due_date->toDateString(), $task->due_date->toDateString());

        $task->forceFill(['status' => TaskState::Submitted])->save();
        $this->postJson(route('tasks.deadline.change', [$task, 'review']), [
            'due_date' => now()->addDays(7)->toDateString(),
            'reason' => 'Review capacity changed.',
        ])->assertOk();

        $cycle = TaskRevisionCycle::query()->create([
            'task_id' => $task->id,
            'cycle_number' => 1,
            'requested_by' => $f['reviewer']->id,
            'requested_at' => now(),
            'revision_due_date' => now()->addDays(3),
            'origin' => 'review',
        ]);
        $task->forceFill([
            'status' => TaskState::RevisionRequested,
            'active_revision_cycle_id' => $cycle->id,
            'revision_due_date' => $cycle->revision_due_date,
        ])->save();
        $this->postJson(route('tasks.deadline.change', [$task, 'revision']), [
            'due_date' => now()->addDays(8)->toDateString(),
            'reason' => 'Revision scope expanded.',
        ])->assertOk();

        $this->assertSame(now()->addDays(8)->toDateString(), $cycle->fresh()->revision_due_date->toDateString());
        $this->assertSame(
            ['execution', 'review', 'revision'],
            TaskEvent::query()
                ->where('event_type', TaskEventRecorder::DEADLINE_CHANGED)
                ->orderBy('sequence')
                ->get()
                ->pluck('metadata.deadline_type')
                ->all(),
        );
        $this->assertSame(
            TaskHistory::query()
                ->where('task_id', $task->id)
                ->where('action', 'deadline_changed')
                ->orderBy('id')
                ->pluck('id')
                ->map(fn (int $id) => "task_histories:{$id}")
                ->all(),
            TaskEvent::query()
                ->where('task_id', $task->id)
                ->where('event_type', TaskEventRecorder::DEADLINE_CHANGED)
                ->orderBy('sequence')
                ->get()
                ->pluck('metadata.reason_reference')
                ->all(),
        );
    }

    public function test_team_member_serialization_and_timeline_hide_management_only_data(): void
    {
        $f = $this->fixtures();
        $task = $this->task($f);
        $task->forceFill([
            'hold_reason' => 'Management-only hold reason',
            'cancellation_reason' => 'Management-only cancellation reason',
            'priority_override_reason' => 'Management-only priority reason',
        ])->save();

        $this->actingAs($f['assignee'])
            ->getJson(route('tasks.edit', $task))
            ->assertOk()
            ->assertJsonMissingPath('task.hold_reason')
            ->assertJsonMissingPath('task.cancellation_reason')
            ->assertJsonMissingPath('task.priority_override_reason');

        $this->actingAs($f['manager'])
            ->getJson(route('tasks.edit', $task))
            ->assertOk()
            ->assertJsonPath('task.hold_reason', 'Management-only hold reason')
            ->assertJsonPath('task.cancellation_reason', 'Management-only cancellation reason');

        $recorder = app(TaskEventRecorder::class);
        $context = TaskOperationContext::test($f['manager']->id, 'approval-pair');
        $recorder->record($task, TaskEventRecorder::APPROVED, $context, []);
        $recorder->record($task, TaskEventRecorder::COMPLETED, $context, []);
        $recorder->record(
            $task,
            TaskEventRecorder::REVIEWER_REASSIGNED,
            TaskOperationContext::test($f['manager']->id),
            ['reviewer_id' => ['before' => $f['reviewer']->id, 'after' => $f['manager']->id]],
            ['reason_reference' => "task_histories:reviewer_reassigned:{$task->id}"],
        );

        $response = $this->actingAs($f['assignee'])
            ->getJson(route('tasks.timeline', $task))
            ->assertOk()
            ->assertJsonFragment(['label' => 'Task approved and completed'])
            ->assertJsonFragment(['label' => 'Reviewer reassigned'])
            ->assertJsonMissing(['label' => 'Task completed'])
            ->assertJsonMissing(['details' => ['Management reason recorded']]);

        $this->assertCount(2, $response->json('entries'));
        $this->actingAs($f['manager'])
            ->getJson(route('tasks.timeline', $task))
            ->assertJsonFragment(['details' => ['Reviewer assignment updated', 'Management reason recorded']]);
    }

    public function test_filters_remain_scoped_and_cover_every_machine_state(): void
    {
        $f = $this->fixtures();

        foreach (TaskState::cases() as $state) {
            $this->task($f, $state, ['title' => 'State '.$state->value]);
        }

        $outsideManager = $this->user('project_manager');
        $outsideProject = Project::factory()->create(['project_manager_id' => $outsideManager->id]);
        $outsideAssignee = $this->user('team_member');
        $outsideProject->members()->attach($outsideAssignee->id);
        Task::query()->create([
            'project_id' => $outsideProject->id,
            'title' => 'Outside cancelled task',
            'assignee_id' => $outsideAssignee->id,
            'priority' => 'Medium',
            'status' => TaskState::Cancelled,
            'progress' => 0,
        ]);

        foreach (TaskState::cases() as $state) {
            $this->actingAs($f['project_manager'])
                ->get(route('tasks', ['status' => $state->value]))
                ->assertOk()
                ->assertSee('State '.$state->value)
                ->assertDontSee('Outside cancelled task');
        }

        $this->get(route('tasks', [
            'status' => TaskState::Submitted->value,
            'reviewer' => $f['reviewer']->id,
            'scope' => 'waiting_for_review',
        ]))->assertOk()->assertSee('State submitted');
    }

    public function test_static_runtime_guard_covers_routes_controllers_services_and_forms(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/TasksController.php'));
        $taskView = file_get_contents(resource_path('views/tasks.blade.php'));
        $storeRequest = file_get_contents(app_path('Http/Requests/TaskStoreRequest.php'));
        $updateRequest = file_get_contents(app_path('Http/Requests/TaskUpdateRequest.php'));

        $this->assertStringNotContainsString("forceFill(['status'", $controller);
        $this->assertStringNotContainsString("->update(['status'", $controller);
        $this->assertStringNotContainsString('id="taskStatus"', $taskView);
        $this->assertStringNotContainsString('name="taskStatus"', $taskView);
        $this->assertStringContainsString("'status' => ['missing']", $storeRequest);
        $this->assertStringContainsString("'status' => ['missing']", $updateRequest);

        foreach ([
            'tasks.store',
            'tasks.update',
            'tasks.destroy',
            'tasks.bulk-delete',
            'tasks.reopen',
            'tasks.cancel',
            'tasks.reviewer.reassign',
            'tasks.deadline.change',
            'tasks.start',
            'tasks.hold',
            'tasks.resume',
            'tasks.submit',
            'tasks.review.start',
            'tasks.revision.request',
            'tasks.revision.start',
            'tasks.resubmit',
            'tasks.approve',
            'tasks.approve.override',
        ] as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route, $routeName);
            $this->assertContains(EnsureTaskCorrelationId::class, $route->gatherMiddleware(), $routeName);
        }

        $this->actingAs($this->fixtures()['manager'])
            ->postJson(route('tasks.complete', 999999))
            ->assertGone();
    }

    /**
     * @return array<string, mixed>
     */
    private function fixtures(): array
    {
        $manager = $this->user('manager');
        $projectManager = $this->user('project_manager');
        $reviewer = $projectManager;
        $assignee = $this->user('team_member');
        $project = Project::factory()->create(['project_manager_id' => $projectManager->id]);
        $project->members()->attach($assignee->id);

        return compact('manager', 'projectManager', 'reviewer', 'assignee', 'project') + [
            'project_manager' => $projectManager,
        ];
    }

    private function task(array $fixtures, TaskState $state = TaskState::NotStarted, array $attributes = []): Task
    {
        $task = Task::query()->create(array_merge([
            'project_id' => $fixtures['project']->id,
            'title' => 'Workflow lockdown task',
            'description' => 'Workflow test fixture',
            'assignee_id' => $fixtures['assignee']->id,
            'created_by' => $fixtures['manager']->id,
            'priority' => 'Medium',
            'status' => $state,
            'progress' => $state === TaskState::Completed ? 100 : 50,
            'due_date' => now()->addDays(5),
        ], $attributes));
        $task->forceFill([
            'reviewer_id' => $fixtures['reviewer']->id,
            'execution_due_date' => now()->addDays(5),
        ])->save();

        return $task;
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('name', $role)->value('id'),
        ]);
    }
}
