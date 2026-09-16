<?php

namespace App\Http\Controllers;

use App\Contracts\TaskTransitionCommand;
use App\Enums\TaskState;
use App\Exceptions\TaskNotificationDispatchException;
use App\Http\Middleware\EnsureTaskCorrelationId;
use App\Http\Requests\ApproveTaskRequest;
use App\Http\Requests\CancelTaskRequest;
use App\Http\Requests\ChangeTaskDeadlineRequest;
use App\Http\Requests\HoldTaskRequest;
use App\Http\Requests\OverrideApproveTaskRequest;
use App\Http\Requests\ReassignTaskReviewerRequest;
use App\Http\Requests\ReopenApprovedTaskRequest;
use App\Http\Requests\RequestTaskRevisionRequest;
use App\Http\Requests\ResubmitTaskRequest;
use App\Http\Requests\ResumeTaskRequest;
use App\Http\Requests\StartTaskRequest;
use App\Http\Requests\StartTaskReviewRequest;
use App\Http\Requests\StartTaskRevisionRequest;
use App\Http\Requests\SubmitTaskRequest;
use App\Http\Requests\TaskIndexRequest;
use App\Http\Requests\TaskStoreRequest;
use App\Http\Requests\TaskUpdateRequest;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskAssignmentCandidateService;
use App\Services\TaskEventRecorder;
use App\Services\TaskLifecycleService;
use App\Services\TaskReadService;
use App\Services\TaskTimelineService;
use App\Services\TaskTransitionExecutor;
use App\Services\TaskViewData;
use App\TaskTransitions\ApproveTask;
use App\TaskTransitions\CancelTask;
use App\TaskTransitions\ChangeTaskDeadline;
use App\TaskTransitions\HoldTask;
use App\TaskTransitions\OverrideApproveTask;
use App\TaskTransitions\ReassignTaskReviewer;
use App\TaskTransitions\ReopenApprovedTask;
use App\TaskTransitions\RequestTaskRevision;
use App\TaskTransitions\ResubmitTask;
use App\TaskTransitions\ResumeTask;
use App\TaskTransitions\StartTask;
use App\TaskTransitions\StartTaskReview;
use App\TaskTransitions\StartTaskRevision;
use App\TaskTransitions\SubmitTask;
use App\ValueObjects\TaskOperationContext;
use App\ValueObjects\TaskTransitionResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TasksController extends Controller
{
    use AuthorizesRequests;

    private const TASKS_PER_PAGE = 24;

    public function __construct(
        private readonly TaskReadService $taskReads,
        private readonly TaskViewData $taskViewData,
        private readonly TaskTimelineService $taskTimeline,
    ) {}

    public function index(TaskIndexRequest $request)
    {
        $user = $request->user();
        $filters = array_filter(
            $request->validated(),
            fn ($value) => $value !== null && $value !== ''
        );

        $tasksQuery = $this->visibleTasks()
            ->when(
                ! isset($filters['status']),
                fn ($query) => $query->whereNotIn('status', [
                    TaskState::Completed->value,
                    TaskState::Cancelled->value,
                ]),
            )
            ->with(['project', 'assignee', 'creator', 'reviewer', 'activeRevisionCycle'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['priority'] ?? null, fn ($query, $priority) => $query->where('priority', $priority))
            ->when($filters['project'] ?? null, fn ($query, $project) => $query->where('project_id', $project))
            ->when($filters['assignee'] ?? null, fn ($query, $assignee) => $query->where('assignee_id', $assignee))
            ->when($filters['reviewer'] ?? null, fn ($query, $reviewer) => $query->where('reviewer_id', $reviewer))
            ->when(
                ($filters['scope'] ?? null) === 'assigned_to_me',
                fn ($query) => $query->where('assignee_id', $user->id),
            )
            ->when(
                ($filters['scope'] ?? null) === 'created_by_me',
                fn ($query) => $query->where('created_by', $user->id),
            )
            ->when(
                ($filters['scope'] ?? null) === 'waiting_for_review',
                fn ($query) => $query
                    ->where('reviewer_id', $user->id)
                    ->whereIn('status', [TaskState::Submitted->value, TaskState::InReview->value]),
            );

        if (isset($filters['search'])) {
            $this->applySearch($tasksQuery, $filters['search']);
        }

        $tasks = $tasksQuery
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::TASKS_PER_PAGE)
            ->appends($filters);

        $filterProjects = Project::query()
            ->whereIn('id', $this->visibleTasks()->whereNotNull('project_id')->select('project_id'))
            ->orderBy('name')
            ->get(['id', 'name']);

        $assignees = User::query()
            ->whereIn('id', $this->visibleTasks()->whereNotNull('assignee_id')->select('assignee_id'))
            ->orderBy('name')
            ->get(['id', 'name']);

        $filterReviewers = User::query()
            ->whereIn('id', $this->visibleTasks()->whereNotNull('reviewer_id')->select('reviewer_id'))
            ->orderBy('name')
            ->get(['id', 'name']);

        $projects = collect();
        $assignmentCandidates = collect();
        $reviewerCandidates = collect();

        if ($user->can('create', Task::class)) {
            $projects = $this->visibleProjects()
                ->whereNotIn('status', ['completed', 'archived'])
                ->with([
                    'members' => fn ($query) => $query->where('active', true)->orderBy('name'),
                    'members.role',
                    'projectManager',
                ])
                ->orderBy('name')
                ->get();

            $assignmentCandidates = app(TaskAssignmentCandidateService::class)
                ->forProjects($projects)
                ->get(['id', 'name']);

            $reviewerCandidates = User::query()
                ->where('active', true)
                ->whereHas('role', fn ($query) => $query->whereIn('name', ['manager', 'project_manager']))
                ->with('role')
                ->orderBy('name')
                ->get(['id', 'name', 'role_id']);
        }

        return view('tasks', [
            'tasks' => $tasks,
            'assignees' => $assignees,
            'assignmentCandidates' => $assignmentCandidates,
            'reviewerCandidates' => $reviewerCandidates,
            'projects' => $projects,
            'filterProjects' => $filterProjects,
            'filterReviewers' => $filterReviewers,
            'filters' => $filters,
            'hasActiveFilters' => $filters !== [],
            'user' => $user,
        ]);
    }

    public function store(TaskStoreRequest $request, TaskLifecycleService $tasks)
    {
        $correlationId = $request->attributes->get(EnsureTaskCorrelationId::REQUEST_ATTRIBUTE);

        if ($correlationId) {
            $existingEvent = DB::table('task_events')
                ->where('event_type', TaskEventRecorder::CREATED)
                ->where('correlation_id', $correlationId)
                ->first();

            if ($existingEvent) {
                if ((int) $existingEvent->actor_id !== (int) $request->user()->id) {
                    return response()->json([
                        'message' => 'The provided correlation ID is already associated with another request.',
                    ], 409);
                }

                $existingTask = Task::find($existingEvent->task_id);
                if ($existingTask) {
                    $incomingData = $request->validated();
                    $incomingHash = $this->taskCreationPayloadHash($incomingData);
                    $metadata = is_string($existingEvent->metadata)
                        ? json_decode($existingEvent->metadata, true)
                        : (array) ($existingEvent->metadata ?? []);
                    $storedHash = is_array($metadata) ? ($metadata['payload_hash'] ?? null) : null;
                    $matches = $storedHash !== null
                        ? hash_equals($storedHash, $incomingHash)
                        : $this->isPayloadMatchingTask($existingTask, $incomingData);

                    if (! $matches) {
                        return response()->json([
                            'message' => 'Idempotency key collision with mismatched payload.',
                        ], 409);
                    }

                    return response()->json([
                        'success' => true,
                        'moved' => false,
                        'task' => $this->formatTask($existingTask, $request->user()),
                        'idempotent_replay' => true,
                    ]);
                }

                return response()->json([
                    'message' => 'The provided correlation ID is already associated with a completed operation.',
                ], 409);
            }
        }

        try {
            $task = $tasks->create(
                $request->validated(),
                $request->user(),
                TaskOperationContext::web(
                    $request->user(),
                    $correlationId,
                ),
            );

            return response()->json([
                'success' => true,
                'moved' => false,
                'task' => $this->formatTask($task, $request->user()),
            ]);
        } catch (TaskNotificationDispatchException $exception) {
            Log::warning('Task operation succeeded but notification dispatch failed.', [
                'operation' => $exception->operation,
                'task_id' => $exception->taskId,
                'error' => $exception->getMessage(),
            ]);

            $task = Task::findOrFail($exception->taskId);

            return response()->json([
                'success' => true,
                'moved' => false,
                'task' => $this->formatTask($task, $request->user()),
                'notification_status' => 'delivery_failed',
            ]);
        }
    }

    public function update(TaskUpdateRequest $request, Task $task, TaskLifecycleService $tasks)
    {
        try {
            $updated = $tasks->update(
                $task,
                $request->validated(),
                $request->user(),
                TaskOperationContext::web(
                    $request->user(),
                    $request->attributes->get(EnsureTaskCorrelationId::REQUEST_ATTRIBUTE),
                ),
            );

            return response()->json([
                'success' => true,
                'moved' => $updated->machineState() === TaskState::Completed,
                'task' => $this->formatTask($updated),
            ]);
        } catch (TaskNotificationDispatchException $exception) {
            Log::warning('Task operation succeeded but notification dispatch failed.', [
                'operation' => $exception->operation,
                'task_id' => $exception->taskId,
                'error' => $exception->getMessage(),
            ]);

            $freshTask = Task::findOrFail($task->id);

            return response()->json([
                'success' => true,
                'moved' => $freshTask->machineState() === TaskState::Completed,
                'task' => $this->formatTask($freshTask),
                'notification_status' => 'delivery_failed',
            ]);
        }
    }

    public function destroy(Task $task, TaskLifecycleService $tasks)
    {
        $this->authorize('delete', $task);
        $tasks->delete($task, auth()->user());

        return response()->json(['success' => true]);
    }

    public function bulkDelete(Request $request, TaskLifecycleService $service)
    {
        $this->authorize('bulkActions', Task::class);

        $ids = $request->validate([
            'task_ids' => ['required', 'array', 'min:1'],
            'task_ids.*' => ['integer', 'exists:tasks,id'],
        ])['task_ids'];

        $tasks = $this->visibleTasks()->whereIn('id', $ids)->get();

        DB::transaction(function () use ($tasks, $service) {
            foreach ($tasks as $task) {
                $this->authorize('delete', $task);
                $service->delete($task, auth()->user(), 'bulk_deleted');
            }
        });

        return response()->json(['success' => true]);
    }

    public function retiredDirectCompletion()
    {
        return response()->json([
            'success' => false,
            'message' => 'Direct completion is retired. Tasks must be completed through reviewer approval.',
        ], 410);
    }

    public function reopen(
        ReopenApprovedTaskRequest $request,
        Task $task,
        TaskTransitionExecutor $executor,
    ) {
        $command = app()->make(ReopenApprovedTask::class, [
            'reopenReason' => $request->validated()['reopen_reason'],
            'revisionDueDate' => $request->validated()['revision_due_date'],
        ]);

        return $this->executeTransition(
            $executor,
            $task,
            $request->user(),
            $command,
            $this->operationContext($request),
            'Task reopened for revision.',
        );
    }

    public function cancel(
        CancelTaskRequest $request,
        Task $task,
        TaskTransitionExecutor $executor,
    ) {
        $command = app()->make(CancelTask::class, [
            'cancellationReason' => $request->validated()['cancellation_reason'],
            'expectedState' => $task->machineState()->value,
        ]);

        return $this->executeTransition(
            $executor,
            $task,
            $request->user(),
            $command,
            $this->operationContext($request),
            'Task cancelled.',
        );
    }

    public function reassignReviewer(
        ReassignTaskReviewerRequest $request,
        Task $task,
        TaskTransitionExecutor $executor,
    ) {
        $command = app()->make(ReassignTaskReviewer::class, [
            'reviewerId' => (int) $request->validated()['reviewer_id'],
            'reason' => $request->validated()['reason'] ?? null,
        ]);

        return $this->executeTransition(
            $executor,
            $task,
            $request->user(),
            $command,
            $this->operationContext($request),
            'Reviewer reassigned.',
        );
    }

    public function changeDeadline(
        ChangeTaskDeadlineRequest $request,
        Task $task,
        TaskTransitionExecutor $executor,
    ) {
        $command = app()->make(ChangeTaskDeadline::class, [
            'deadlineType' => $request->validated()['deadline_type'],
            'dueDate' => $request->validated()['due_date'],
            'reason' => $request->validated()['reason'] ?? null,
        ]);

        return $this->executeTransition(
            $executor,
            $task,
            $request->user(),
            $command,
            $this->operationContext($request),
            'Task deadline changed.',
        );
    }

    public function start(
        StartTaskRequest $request,
        Task $task,
        TaskTransitionExecutor $executor,
        StartTask $command,
    ) {
        return $this->executeTransition(
            $executor,
            $task,
            $request->user(),
            $command,
            $this->operationContext($request),
            'Work started.',
        );
    }

    public function hold(
        HoldTaskRequest $request,
        Task $task,
        TaskTransitionExecutor $executor,
    ) {
        $command = app()->make(HoldTask::class, [
            'reason' => $request->validated()['reason'],
        ]);

        return $this->executeTransition(
            $executor,
            $task,
            $request->user(),
            $command,
            $this->operationContext($request),
            'Task placed on hold.',
        );
    }

    public function resume(
        ResumeTaskRequest $request,
        Task $task,
        TaskTransitionExecutor $executor,
        ResumeTask $command,
    ) {
        return $this->executeTransition(
            $executor,
            $task,
            $request->user(),
            $command,
            $this->operationContext($request),
            'Work resumed.',
        );
    }

    public function submit(
        SubmitTaskRequest $request,
        Task $task,
        TaskTransitionExecutor $executor,
    ) {
        $command = app()->make(SubmitTask::class, [
            'submissionNote' => $request->validated()['submission_note'] ?? null,
        ]);

        return $this->executeTransition(
            $executor,
            $task,
            $request->user(),
            $command,
            $this->operationContext($request),
            'Task submitted for review.',
        );
    }

    public function startReview(
        StartTaskReviewRequest $request,
        Task $task,
        TaskTransitionExecutor $executor,
        StartTaskReview $command,
    ) {
        return $this->executeTransition(
            $executor,
            $task,
            $request->user(),
            $command,
            $this->operationContext($request),
            'Task review started.',
        );
    }

    public function requestRevision(
        RequestTaskRevisionRequest $request,
        Task $task,
        TaskTransitionExecutor $executor,
    ) {
        $command = app()->make(RequestTaskRevision::class, [
            'formalFeedback' => $request->validated()['formal_feedback'],
            'revisionDueDate' => $request->validated()['revision_due_date'],
        ]);

        return $this->executeTransition(
            $executor,
            $task,
            $request->user(),
            $command,
            $this->operationContext($request),
            'Revision requested.',
        );
    }

    public function startRevision(
        StartTaskRevisionRequest $request,
        Task $task,
        TaskTransitionExecutor $executor,
        StartTaskRevision $command,
    ) {
        return $this->executeTransition(
            $executor,
            $task,
            $request->user(),
            $command,
            $this->operationContext($request),
            'Revision started.',
        );
    }

    public function resubmit(
        ResubmitTaskRequest $request,
        Task $task,
        TaskTransitionExecutor $executor,
    ) {
        $command = app()->make(ResubmitTask::class, [
            'submissionNote' => $request->validated()['submission_note'] ?? null,
        ]);

        return $this->executeTransition(
            $executor,
            $task,
            $request->user(),
            $command,
            $this->operationContext($request),
            'Task resubmitted for review.',
        );
    }

    public function approve(
        ApproveTaskRequest $request,
        Task $task,
        TaskTransitionExecutor $executor,
    ) {
        $command = app()->make(ApproveTask::class, [
            'approvalComment' => $request->validated()['approval_comment'] ?? null,
        ]);

        return $this->executeTransition(
            $executor,
            $task,
            $request->user(),
            $command,
            $this->operationContext($request),
            'Task approved and completed.',
        );
    }

    public function overrideApprove(
        OverrideApproveTaskRequest $request,
        Task $task,
        TaskTransitionExecutor $executor,
    ) {
        $command = app()->make(OverrideApproveTask::class, [
            'overrideReason' => $request->validated()['override_reason'],
            'approvalComment' => $request->validated()['approval_comment'] ?? null,
        ]);

        return $this->executeTransition(
            $executor,
            $task,
            $request->user(),
            $command,
            $this->operationContext($request),
            'Task approved and completed by Manager override.',
        );
    }

    public function edit(Task $task)
    {
        $this->authorize('view', $task);

        return response()->json([
            'success' => true,
            'task' => $this->formatTask(
                $task->load(['project', 'assignee', 'reviewer', 'activeRevisionCycle']),
                request()->user(),
            ),
        ]);
    }

    public function timeline(Request $request, Task $task)
    {
        $this->authorize('view', $task);

        return response()->json([
            'success' => true,
            'task' => [
                'id' => (int) $task->id,
                'task_uid' => $task->task_uid,
                'title' => $task->title,
            ],
            'entries' => $this->taskTimeline->forViewer($task, $request->user()),
        ]);
    }

    private function visibleTasks(): Builder
    {
        return $this->taskReads->visibleTo(auth()->user());
    }

    private function visibleProjects(): Builder
    {
        $user = auth()->user();

        if ($user->hasRole('manager')) {
            return Project::query();
        }

        if ($user->hasRole('project_manager')) {
            return Project::query()->where('project_manager_id', $user->id);
        }

        return Project::query()->whereHas('members', fn ($query) => $query->whereKey($user->id));
    }

    private function applySearch(Builder $query, string $search): void
    {
        $escapedSearch = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search));
        $pattern = "%{$escapedSearch}%";

        $query->where(function ($searchQuery) use ($pattern) {
            $searchQuery
                ->whereRaw("LOWER(tasks.title) LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(tasks.description) LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereHas('project', fn ($projectQuery) => $projectQuery
                    ->whereRaw("LOWER(projects.name) LIKE ? ESCAPE '!'", [$pattern]))
                ->orWhereHas('assignee', fn ($assigneeQuery) => $assigneeQuery
                    ->whereRaw("LOWER(users.name) LIKE ? ESCAPE '!'", [$pattern]));
        });
    }

    private function formatTask(Task $task, ?User $viewer = null): array
    {
        $viewer ??= auth()->user();
        $task->loadMissing(['project', 'assignee', 'reviewer']);

        return $this->taskViewData->make($task, $viewer);
    }

    private function operationContext(Request $request): TaskOperationContext
    {
        return TaskOperationContext::web(
            $request->user(),
            $request->attributes->get(EnsureTaskCorrelationId::REQUEST_ATTRIBUTE),
        );
    }

    private function executeTransition(
        TaskTransitionExecutor $executor,
        Task $task,
        User $actor,
        TaskTransitionCommand $command,
        TaskOperationContext $context,
        string $message,
    ) {
        try {
            $result = $executor->execute($task, $actor, $command, $context);

            return $this->transitionResponse($result, $message);
        } catch (TaskNotificationDispatchException $exception) {
            Log::warning('Task operation succeeded but notification dispatch failed.', [
                'operation' => $exception->operation,
                'task_id' => $exception->taskId,
                'error' => $exception->getMessage(),
            ]);

            $freshTask = Task::findOrFail($exception->taskId);

            return response()->json([
                'success' => true,
                'message' => $message,
                'task' => $this->formatTask($freshTask, $actor),
                'notification_status' => 'delivery_failed',
            ]);
        }
    }

    private function transitionResponse(TaskTransitionResult $result, string $message)
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'task' => $this->formatTask($result->task),
        ]);
    }

    private function taskCreationPayloadHash(array $data): string
    {
        return hash('sha256', json_encode([
            'assignee_id' => isset($data['assignee_id']) ? (int) $data['assignee_id'] : null,
            'comments' => ! empty($data['comments']) ? (string) $data['comments'] : null,
            'description' => ! empty($data['description']) ? (string) $data['description'] : null,
            'due_date' => ! empty($data['due_date']) ? (string) $data['due_date'] : null,
            'priority' => isset($data['priority']) ? (string) ($data['priority'] instanceof \BackedEnum ? $data['priority']->value : $data['priority']) : null,
            'project_id' => isset($data['project_id']) ? (int) $data['project_id'] : null,
            'reviewer_id' => isset($data['reviewer_id']) ? (int) $data['reviewer_id'] : null,
            'start_date' => ! empty($data['start_date']) ? (string) $data['start_date'] : null,
            'title' => isset($data['title']) ? (string) $data['title'] : null,
        ]));
    }

    private function isPayloadMatchingTask(Task $task, array $data): bool
    {
        if ((int) $task->project_id !== (int) ($data['project_id'] ?? 0)) {
            return false;
        }

        if ((string) $task->title !== (string) ($data['title'] ?? '')) {
            return false;
        }

        if ((int) $task->assignee_id !== (int) ($data['assignee_id'] ?? 0)) {
            return false;
        }

        if (array_key_exists('reviewer_id', $data) && (int) $task->reviewer_id !== (int) $data['reviewer_id']) {
            return false;
        }

        $priorityValue = $task->priority instanceof \BackedEnum ? $task->priority->value : (string) $task->priority;
        if (array_key_exists('priority', $data) && $priorityValue !== (string) $data['priority']) {
            return false;
        }

        return true;
    }
}
