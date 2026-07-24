<?php

namespace App\Http\Requests;

use App\Enums\TaskState;
use App\Models\Task;
use App\Support\TaskStateCompatibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TaskUpdateRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('status'))) {
            $this->merge([
                'status' => TaskStateCompatibility::normalizeLegacyLabel($this->input('status')),
            ]);
        }
    }

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
            'assignee_id' => 'sometimes|nullable|exists:users,id',
            'reviewer_id' => 'sometimes|nullable|exists:users,id',
            'review_due_date' => 'sometimes|nullable|date|after:today',
            'submitted_at' => ['missing'],
            'review_started_at' => ['missing'],
            'approved_at' => ['missing'],
            'approved_by' => ['missing'],
            'completed_at' => ['missing'],
            'completed_by' => ['missing'],
            'cancelled_at' => ['missing'],
            'cancelled_by' => ['missing'],
            'cancellation_reason' => ['missing'],
            'revision_count' => ['missing'],
            'active_revision_cycle_id' => ['missing'],
            'revision_due_date' => ['missing'],
            'priority' => ['sometimes', 'required', Rule::in(Task::PRIORITIES)],
            'status' => ['sometimes', 'required', Rule::in(TaskStateCompatibility::genericStates())],
            'progress' => 'sometimes|required|integer|min:0|max:99',
            'start_date' => 'nullable|date',
            'due_date' => 'nullable|date|after_or_equal:start_date',
            'comments' => 'nullable|string',
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
                $status = $this->input('status');

                if ($this->user()?->hasRole('team_member')
                    && ($this->exists('reviewer_id') || $this->exists('review_due_date'))) {
                    $validator->errors()->add(
                        'reviewer_id',
                        'Only management may assign or change a reviewer.',
                    );
                }

                if ($task instanceof Task && $task->machineState()->isFinal() && $this->all() !== []) {
                    $validator->errors()->add(
                        'task',
                        'Completed and cancelled tasks require a dedicated workflow action.',
                    );
                }

                if (! $task instanceof Task
                    || ! is_string($status)
                    || $validator->errors()->has('status')) {
                    return;
                }

                $target = TaskState::tryFrom($status);

                if ($target
                    && TaskStateCompatibility::requiresDedicatedTransition(
                        $task->machineState(),
                        $target,
                    )) {
                    $validator->errors()->add(
                        'status',
                        'Use the dedicated task workflow action for this state change.',
                    );
                }
            },
        ];
    }
}
