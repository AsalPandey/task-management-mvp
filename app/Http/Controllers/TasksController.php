<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureTaskCorrelationId;
use App\Http\Requests\TaskIndexRequest;
use App\Http\Requests\TaskStoreRequest;
use App\Http\Requests\TaskUpdateRequest;
use App\Models\CompletedTask;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskLifecycleService;
use App\ValueObjects\TaskOperationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TasksController extends Controller
{
    use AuthorizesRequests;

    private const TASKS_PER_PAGE = 24;

    public function index(TaskIndexRequest $request)
    {
        $user = $request->user();
        $filters = array_filter(
            $request->validated(),
            fn ($value) => $value !== null && $value !== ''
        );

        $tasksQuery = $this->visibleTasks()
            ->with(['project', 'assignee', 'creator'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['priority'] ?? null, fn ($query, $priority) => $query->where('priority', $priority))
            ->when($filters['project'] ?? null, fn ($query, $project) => $query->where('project_id', $project))
            ->when($filters['assignee'] ?? null, fn ($query, $assignee) => $query->where('assignee_id', $assignee));

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

        $projects = collect();

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
        }

        return view('tasks', [
            'tasks' => $tasks,
            'assignees' => $assignees,
            'projects' => $projects,
            'filterProjects' => $filterProjects,
            'filters' => $filters,
            'hasActiveFilters' => $filters !== [],
            'user' => $user,
        ]);
    }

    public function store(TaskStoreRequest $request, TaskLifecycleService $tasks)
    {
        $task = $tasks->create(
            $request->validated(),
            $request->user(),
            TaskOperationContext::web(
                $request->user(),
                $request->attributes->get(EnsureTaskCorrelationId::REQUEST_ATTRIBUTE),
            ),
        );

        return response()->json([
            'success' => true,
            'moved' => $task instanceof CompletedTask,
            'task' => $task,
        ]);
    }

    public function update(TaskUpdateRequest $request, Task $task, TaskLifecycleService $tasks)
    {
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
            'moved' => $updated instanceof CompletedTask,
            'task' => $this->formatTask($updated),
        ]);
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

    public function bulkComplete(Request $request, TaskLifecycleService $service)
    {
        $this->authorize('bulkActions', Task::class);

        $ids = $request->validate([
            'task_ids' => ['required', 'array', 'min:1'],
            'task_ids.*' => ['integer', 'exists:tasks,id'],
        ])['task_ids'];

        $tasks = $this->visibleTasks()->whereIn('id', $ids)->with(['project', 'assignee'])->get();

        DB::transaction(function () use ($tasks, $service) {
            foreach ($tasks as $task) {
                $task->forceFill(['status' => 'Completed', 'progress' => 100]);
                $service->complete($task, auth()->user(), 'bulk_completed');
            }
        });

        return response()->json(['success' => true]);
    }

    public function edit(Task $task)
    {
        $this->authorize('view', $task);

        return response()->json([
            'success' => true,
            'task' => $this->formatTask($task->load(['project', 'assignee'])),
        ]);
    }

    private function visibleTasks(): Builder
    {
        $user = auth()->user();

        if ($user->hasRole('manager')) {
            return Task::query();
        }

        if ($user->hasRole('project_manager')) {
            return Task::query()->whereHas('project', fn ($query) => $query->where('project_manager_id', $user->id));
        }

        return Task::query()->where('assignee_id', $user->id);
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

    private function formatTask($task): array
    {
        $taskArr = $task->toArray();
        $taskArr['start_date'] = $task->start_date ? $task->start_date->format('Y-m-d') : null;
        $taskArr['due_date'] = $task->due_date ? $task->due_date->format('Y-m-d') : null;

        return $taskArr;
    }
}
