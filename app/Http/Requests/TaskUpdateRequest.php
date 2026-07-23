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

        $ability = $this->input('status') === TaskState::Completed->value ? 'complete' : 'update';

        return $task
            ? ($this->user()?->can($ability, $task) ?? false)
            : (bool) $this->user();
    }

    public function rules()
    {
        return [
            'title' => 'sometimes|required|string|max:255',
            'project_id' => 'sometimes|required|exists:projects,id',
            'description' => 'nullable|string',
            'assignee_id' => 'sometimes|nullable|exists:users,id',
            'priority' => ['sometimes', 'required', Rule::in(Task::PRIORITIES)],
            'status' => ['sometimes', 'required', Rule::in(TaskStateCompatibility::genericStates())],
            'progress' => 'sometimes|required|integer|min:0|max:100',
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

                if (! $task instanceof Task
                    || ! is_string($status)
                    || $validator->errors()->has('status')) {
                    return;
                }

                $target = TaskState::tryFrom($status);

                if ($target
                    && TaskStateCompatibility::requiresDedicatedExecutionTransition(
                        $task->machineState(),
                        $target,
                    )) {
                    $validator->errors()->add(
                        'status',
                        'Use the dedicated task execution action for this state change.',
                    );
                }
            },
        ];
    }
}
