<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompletedTaskIndexRequest;
use App\Services\TaskReadService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class CompletedTasksController extends Controller
{
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

    /**
     * Preserve the old URL as a non-querying compatibility response.
     *
     * The legacy identifier is deliberately not resolved or mapped to a canonical task.
     */
    public function retiredRevert(string $completedTask): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'The legacy completed-task reopen endpoint has been retired. Reopen the canonical task instead.',
        ], 410);
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
