<?php

namespace Tests\Feature;

use App\Enums\TaskState;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskApproval;
use App\Models\TaskHistory;
use App\Models\TaskRevisionCycle;
use App\Models\TaskSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkflowFreeTextXssTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('payloads')]
    public function test_workflow_free_text_remains_stored_but_cannot_execute_in_views_json_or_timeline(string $payload): void
    {
        $this->seed();
        [$manager, $member, $task] = $this->fixtures($payload);

        $tasksResponse = $this->actingAs($manager)
            ->get(route('tasks'))
            ->assertOk()
            ->assertSee($payload);

        if (str_starts_with($payload, 'javascript:')) {
            $tasksResponse
                ->assertDontSee('href="'.$payload.'"', false)
                ->assertDontSee('src="'.$payload.'"', false)
                ->assertDontSee('action="'.$payload.'"', false);
        } else {
            $tasksResponse->assertDontSee($payload, false);
        }

        $this->get(route('tasks.edit', $task))
            ->assertOk()
            ->assertHeader('content-type', 'application/json')
            ->assertJsonPath('task.title', $payload)
            ->assertJsonPath('task.description', $payload);

        $timeline = $this->actingAs($member)
            ->getJson(route('tasks.timeline', $task))
            ->assertOk()
            ->assertHeader('content-type', 'application/json')
            ->assertJsonPath('success', true)
            ->assertJsonPath('task.title', $payload)
            ->assertJsonMissingPath('task.description')
            ->assertJsonMissingPath('task.cancellation_reason')
            ->assertJsonMissingPath('task.override_reason');

        $taskView = file_get_contents(resource_path('views/tasks.blade.php'));
        $completedView = file_get_contents(resource_path('views/completed-tasks.blade.php'));
        $this->assertIsString($taskView);
        $this->assertIsString($completedView);
        foreach ([$taskView, $completedView] as $view) {
            $this->assertStringContainsString('window.Phase3UI.showTimeline(data.entries)', $view);
        }

        $timelineRenderer = file_get_contents(public_path('js/phase3.js'));
        $this->assertIsString($timelineRenderer);
        $this->assertStringContainsString('element.textContent = text', $timelineRenderer);
        $this->assertStringContainsString('html: timeline', $timelineRenderer);
        $this->assertStringNotContainsString('innerHTML = entry', $timelineRenderer);

        $this->assertSame($payload, $task->fresh()->title);
        $this->assertSame($payload, $task->fresh()->description);
        $this->assertSame($payload, $task->submissions()->firstOrFail()->submission_note);
        $this->assertSame($payload, $task->revisionCycles()->firstOrFail()->formal_feedback);
        $this->assertSame($payload, $task->revisionCycles()->firstOrFail()->reopen_reason);
        $this->assertSame($payload, $task->approvals()->firstOrFail()->approval_comment);
        $this->assertSame($payload, $task->approvals()->firstOrFail()->override_reason);

        $changes = TaskHistory::query()->where('task_id', $task->id)->firstOrFail()->changes;
        foreach ([
            'hold_reason',
            'reviewer_reassignment_reason',
            'deadline_change_reason',
            'revision_request_reason',
            'reopen_reason',
            'cancellation_reason',
            'manager_override_reason',
        ] as $field) {
            $this->assertSame($payload, $changes[$field]);
        }
    }

    #[DataProvider('payloads')]
    public function test_notification_messages_and_excerpts_are_escaped_in_authenticated_views(string $payload): void
    {
        $this->seed();
        [$manager] = $this->fixtures($payload);

        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'workflow-xss-regression',
            'notifiable_type' => User::class,
            'notifiable_id' => $manager->id,
            'data' => json_encode([
                'type' => 'task_revision_requested',
                'message' => $payload,
                'feedback_excerpt' => $payload,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $notificationsResponse = $this->actingAs($manager)
            ->get(route('notifications.all'))
            ->assertOk()
            ->assertSee($payload);

        $dashboardResponse = $this->get(route('manager.dashboard'))->assertOk();

        if (str_starts_with($payload, 'javascript:')) {
            foreach ([$notificationsResponse, $dashboardResponse] as $response) {
                $response
                    ->assertDontSee('href="'.$payload.'"', false)
                    ->assertDontSee('src="'.$payload.'"', false)
                    ->assertDontSee('action="'.$payload.'"', false);
            }
        } else {
            $notificationsResponse->assertDontSee($payload, false);
            $dashboardResponse->assertDontSee($payload, false);
        }
    }

    public static function payloads(): array
    {
        return [
            'script' => ["<script>alert('xss')</script>"],
            'image handler' => ['<img src=x onerror=alert(1)>'],
            'svg handler' => ['<svg onload=alert(1)>'],
            'attribute breakout' => ['"><script>alert(document.domain)</script>'],
            'javascript scheme' => ['javascript:alert(1)'],
        ];
    }

    /**
     * @return array{User, User, Task}
     */
    private function fixtures(string $payload): array
    {
        $manager = User::factory()->create([
            'role_id' => Role::query()->where('name', 'manager')->value('id'),
        ]);
        $member = User::factory()->create([
            'role_id' => Role::query()->where('name', 'team_member')->value('id'),
        ]);
        $project = Project::factory()->create(['project_manager_id' => $manager->id]);
        $project->members()->attach($member->id);
        $task = Task::query()->create([
            'project_id' => $project->id,
            'title' => $payload,
            'description' => $payload,
            'assignee_id' => $member->id,
            'status' => TaskState::InReview,
        ]);
        $task->forceFill(['reviewer_id' => $manager->id])->save();

        $cycle = TaskRevisionCycle::query()->create([
            'task_id' => $task->id,
            'cycle_number' => 1,
            'requested_by' => $manager->id,
            'requested_at' => now(),
            'formal_feedback' => $payload,
            'reopen_reason' => $payload,
            'revision_due_date' => now()->addWeek(),
            'origin' => 'review_revision',
        ]);
        $submission = TaskSubmission::query()->create([
            'task_id' => $task->id,
            'revision_cycle_id' => $cycle->id,
            'submitted_by' => $member->id,
            'submitted_at' => now(),
            'submission_note' => $payload,
        ]);
        TaskApproval::query()->create([
            'task_id' => $task->id,
            'submission_id' => $submission->id,
            'revision_cycle_id' => $cycle->id,
            'approved_by' => $manager->id,
            'assigned_reviewer_id' => $manager->id,
            'approved_at' => now(),
            'is_override' => true,
            'approval_comment' => $payload,
            'override_reason' => $payload,
        ]);
        TaskHistory::query()->create([
            'task_id' => $task->id,
            'project_id' => $project->id,
            'task_title' => $payload,
            'user_id' => $manager->id,
            'action' => 'workflow_free_text_xss_regression',
            'changes' => array_fill_keys([
                'hold_reason',
                'reviewer_reassignment_reason',
                'deadline_change_reason',
                'revision_request_reason',
                'reopen_reason',
                'cancellation_reason',
                'manager_override_reason',
            ], $payload),
            'created_at' => now(),
        ]);

        return [$manager, $member, $task];
    }
}
