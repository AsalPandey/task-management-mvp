<?php

namespace App\Http\Requests;

use App\Enums\TaskState;
use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TaskUpdateRequest extends FormRequest
{
    public function authorize()
    {
        $task = $this->route('task');

        return $task
            ? ($this->user()?->can('update', $task) ?? false)
            : (bool) $this->user();
    }

    public function rules()
    {
        return [
            'title' => 'sometimes|required|string|max:255',
            'project_id' => 'sometimes|required|exists:projects,id',
            'description' => 'nullable|string',
            'assignee_id' => 'sometimes|required|exists:users,id',
            'reviewer_id' => ['missing'],
            'review_due_date' => ['missing'],
            'execution_due_date' => ['missing'],
            'revision_due_date' => ['missing'],
            'due_date' => ['missing'],
            'task_uid' => ['missing'],
            'state' => ['missing'],
            'started_at' => ['missing'],
            'submitted_at' => ['missing'],
            'review_started_at' => ['missing'],
            'approved_at' => ['missing'],
            'approved_by' => ['missing'],
            'completed_at' => ['missing'],
            'completed_by' => ['missing'],
            'held_at' => ['missing'],
            'held_by' => ['missing'],
            'hold_reason' => ['missing'],
            'cancelled_at' => ['missing'],
            'cancelled_by' => ['missing'],
            'cancellation_reason' => ['missing'],
            'revision_count' => ['missing'],
            'active_revision_cycle_id' => ['missing'],
            'priority' => ['sometimes', 'required', Rule::in(Task::PRIORITIES)],
            'status' => ['missing'],
            'progress' => 'sometimes|required|integer|min:0|max:99',
            'start_date' => 'nullable|date',
            'comments' => 'nullable|string',
            'expected_version' => ['sometimes', 'required', 'integer', 'min:1'],
            'lock_version' => ['sometimes', 'required', 'integer', 'min:1'],
            'version' => ['sometimes', 'required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $task = $this->route('task');
                $expectedVersion = $this->input('expected_version') ?? $this->input('lock_version') ?? $this->input('version');
                $isStale = $expectedVersion !== null && $task instanceof Task && (int) $expectedVersion !== (int) $task->lock_version;

                if (! $isStale && $task instanceof Task && $task->machineState()->isFinal() && $this->all() !== []) {
                    $validator->errors()->add(
                        'task',
                        'Completed and cancelled tasks require a dedicated workflow action.',
                    );
                }

                if (! $task instanceof Task) {
                    return;
                }

                if ($this->user()?->hasRole('team_member')
                    && $task->machineState() !== TaskState::InProgress
                    && ($this->exists('progress') || $this->exists('comments'))) {
                    $validator->errors()->add(
                        'progress',
                        'Progress and comments may be updated only while work is in progress.',
                    );
                }
            },
        ];
    }
}
