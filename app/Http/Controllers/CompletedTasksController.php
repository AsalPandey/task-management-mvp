<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\CompletedTask;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use App\Models\TaskHistory;

class CompletedTasksController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $oneWeekAgo = now()->subDays(7);
        if ($user && $user->role && $user->role->name === 'team_member') {
            $completed = CompletedTask::with(['project', 'assignee'])
                ->where('assignee_id', $user->id)
                ->where('completed_at', '>=', $oneWeekAgo)
                ->orderByDesc('completed_at')
                ->paginate(15);
        } else {
            $completed = CompletedTask::with(['project', 'assignee'])
                ->where('completed_at', '>=', $oneWeekAgo)
                ->orderByDesc('completed_at')
                ->paginate(15);
        }
        return view('completed-tasks', compact('completed'));
    }
    public function revert($id)
    {
        DB::beginTransaction();
        try {
            $completed = CompletedTask::findOrFail($id);
            // Prevent duplicate revert (by title, project, and assignee)
            $exists = \App\Models\Task::where([
                ['title', $completed->title],
                ['project_id', $completed->project_id],
                ['assignee_id', $completed->assignee_id],
            ])->where('status', '!=', 'Completed')->exists();
            if (!$exists) {
                $taskData = $completed->toArray();
                unset($taskData['id']); // Let DB assign new ID
                unset($taskData['completed_at']);
                unset($taskData['reverted']);
                $taskData['status'] = 'In Progress';
                $taskData['progress'] = 0;
                $task = Task::create($taskData);
                // Audit trail: reverted
                TaskHistory::create([
                    'task_id' => $task->id,
                    'user_id' => auth()->id(),
                    'action' => 'reverted',
                    'changes' => json_encode($taskData),
                ]);
                // Notification placeholder
                // event(new \App\Events\TaskReverted($task));
                $completed->delete();
                DB::commit();
                return response()->json(['success' => true, 'task' => $task]);
            } else {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Task already active.'], 409);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Server Error',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 