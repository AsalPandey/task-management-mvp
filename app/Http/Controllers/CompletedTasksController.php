<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureTaskCorrelationId;
use App\Http\Requests\CompletedTaskIndexRequest;
use App\Models\CompletedTask;
use App\Services\TaskLifecycleService;
use App\Services\TaskReadService;
use App\ValueObjects\TaskOperationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

class CompletedTasksController extends Controller
{
    use AuthorizesRequests;

    private const COMPLETED_TASKS_PER_PAGE = 15;

    public function __construct(private readonly TaskReadService $taskReads) {}

    public function index(CompletedTaskIndexRequest $request)
    {
        $filters = array_filter(
            $request->validated(),
            fn ($value) => $value !== null && $value !== '',
        );
        $query = $this->taskReads->completedVisibleTo($request->user())
            ->with(['project', 'assignee', 'completedBy'])
            ->where('completed_at', '>=', now()->subDays(7))
            ->when($filters['priority'] ?? null, fn (Builder $query, string $priority) => $query->where('priority', $priority))
            ->when($filters['project'] ?? null, fn (Builder $query, int $project) => $query->where('project_id', $project))
            ->when($filters['assignee'] ?? null, fn (Builder $query, int $assignee) => $query->where('assignee_id', $assignee));

        if (isset($filters['search'])) {
            $this->applySearch($query, $filters['search']);
        }

        $completed = $query
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->paginate(self::COMPLETED_TASKS_PER_PAGE)
            ->withQueryString();

        return view('completed-tasks', compact('completed'));
    }

    public function revert(Request $request, CompletedTask $completedTask, TaskLifecycleService $tasks)
    {
        $this->authorize('revert', $completedTask);

        $task = $tasks->revert(
            $completedTask,
            $request->user(),
            TaskOperationContext::web(
                $request->user(),
                $request->attributes->get(EnsureTaskCorrelationId::REQUEST_ATTRIBUTE),
            ),
        );

        return response()->json(['success' => true, 'task' => $task]);
    }

    private function applySearch(Builder $query, string $search): void
    {
        $escapedSearch = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search));
        $pattern = "%{$escapedSearch}%";

        $query->where(function (Builder $searchQuery) use ($pattern): void {
            $searchQuery
                ->whereRaw("LOWER(tasks.title) LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(tasks.description) LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereHas('project', fn (Builder $projectQuery) => $projectQuery
                    ->whereRaw("LOWER(projects.name) LIKE ? ESCAPE '!'", [$pattern]))
                ->orWhereHas('assignee', fn (Builder $assigneeQuery) => $assigneeQuery
                    ->whereRaw("LOWER(users.name) LIKE ? ESCAPE '!'", [$pattern]));
        });
    }
}
