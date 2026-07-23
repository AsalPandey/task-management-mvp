<?php

namespace Tests\Feature;

use App\Enums\TaskState;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Support\TaskStateCompatibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MachineTaskStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_state_definition_exposes_machine_values_labels_and_categories(): void
    {
        $this->assertSame([
            'not_started',
            'in_progress',
            'on_hold',
            'submitted',
            'in_review',
            'revision_requested',
            'completed',
            'cancelled',
        ], array_column(TaskState::cases(), 'value'));

        $this->assertSame('Not Started', TaskState::NotStarted->label());
        $this->assertSame('Revision Requested', TaskState::RevisionRequested->label());
        $this->assertTrue(TaskState::Completed->isFinal());
        $this->assertTrue(TaskState::Cancelled->isFinal());
        $this->assertFalse(TaskState::InProgress->isFinal());
        $this->assertTrue(TaskState::NotStarted->isExecutionState());
        $this->assertTrue(TaskState::RevisionRequested->isExecutionState());
        $this->assertTrue(TaskState::Submitted->isReviewState());
        $this->assertTrue(TaskState::InReview->isReviewState());
        $this->assertTrue(TaskState::InProgress->allowsProgressUpdates());
        $this->assertFalse(TaskState::OnHold->allowsProgressUpdates());
        $this->assertTrue(TaskState::Submitted->hasActiveDeadline());
        $this->assertFalse(TaskState::Completed->hasActiveDeadline());
    }

    public function test_temporary_legacy_labels_normalize_to_machine_values(): void
    {
        $this->assertSame('not_started', TaskStateCompatibility::normalizeGenericInput('Not Started'));
        $this->assertSame('in_progress', TaskStateCompatibility::normalizeGenericInput('In Progress'));
        $this->assertSame('on_hold', TaskStateCompatibility::normalizeGenericInput('On Hold'));
        $this->assertSame('completed', TaskStateCompatibility::normalizeGenericInput('Completed'));
        $this->assertSame('completed', TaskStateCompatibility::normalizeGenericInput('completed'));
        $this->assertSame('In Review', TaskStateCompatibility::label('in_review'));

        $this->expectException(ValidationException::class);
        TaskStateCompatibility::normalizeGenericInput('submitted');
    }

    public function test_task_storage_uses_machine_values_while_legacy_presentation_remains_compatible(): void
    {
        $task = Task::query()->create([
            'title' => 'Machine state storage',
            'priority' => 'Medium',
            'status' => 'In Progress',
            'progress' => 10,
        ]);

        $this->assertSame('in_progress', $task->getRawOriginal('status'));
        $this->assertSame('in_progress', $task->fresh()->getRawOriginal('status'));
        $this->assertSame(TaskState::InProgress, $task->fresh()->machineState());
        $this->assertSame('In Progress', $task->fresh()->status);
        $this->assertSame('In Progress', $task->fresh()->statusLabel());
    }

    public function test_generic_http_updates_reject_new_workflow_states_without_side_effects(): void
    {
        [$manager, $task] = $this->managedTask();

        foreach ([
            TaskState::Submitted,
            TaskState::InReview,
            TaskState::RevisionRequested,
            TaskState::Cancelled,
        ] as $state) {
            $this->actingAs($manager)
                ->putJson(route('tasks.update', $task), [
                    'status' => $state->value,
                    'progress' => 50,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('status');

            $this->assertSame(TaskState::NotStarted, $task->fresh()->machineState());
            $this->assertSame(0, $task->fresh()->progress);
        }

        $this->assertDatabaseCount('task_histories', 0);
        $this->assertDatabaseCount('task_events', 0);
    }

    private function managedTask(): array
    {
        $managerRole = Role::query()->create(['name' => 'manager', 'label' => 'Manager']);
        $manager = User::factory()->create(['role_id' => $managerRole->id]);
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $task = Task::query()->create([
            'project_id' => $project->id,
            'assignee_id' => $manager->id,
            'title' => 'Generic protection task',
            'priority' => 'Medium',
            'status' => TaskState::NotStarted,
            'progress' => 0,
        ]);

        return [$manager, $task];
    }
}
