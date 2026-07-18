<?php

namespace App\Http\Controllers;

use App\Models\CompletedTask;
use App\Services\TaskLifecycleService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class CompletedTasksController extends Controller
{
    use AuthorizesRequests;

    public function index()
    {
        $completed = $this->visibleCompletedTasks()
            ->with(['project', 'assignee', 'completer'])
            ->where('completed_at', '>=', now()->subDays(7))
            ->latest('completed_at')
            ->paginate(15);

        return view('completed-tasks', compact('completed'));
    }

    public function revert(CompletedTask $completedTask, TaskLifecycleService $tasks)
    {
        $this->authorize('revert', $completedTask);

        $task = $tasks->revert($completedTask, auth()->user());

        return response()->json(['success' => true, 'task' => $task]);
    }

    private function visibleCompletedTasks()
    {
        $user = auth()->user();

        if ($user->hasRole('manager')) {
            return CompletedTask::withTrashed();
        }

        if ($user->hasRole('project_manager')) {
            return CompletedTask::withTrashed()
                ->whereHas('project', fn ($query) => $query->where('project_manager_id', $user->id));
        }

        return CompletedTask::withTrashed()->where('assignee_id', $user->id);
    }
}
